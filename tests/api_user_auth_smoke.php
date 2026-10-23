<?php
// 2026-10-23 — пользовательские сеансы, профили и WS проверяются на SQLite без .env.
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('DB_HOST='); putenv('DB_NAME='); putenv('USE_REDIS=false'); putenv('LOG_DIRECTORY=');
require_once dirname(__DIR__) . '/lib/core.class.php';
require_once __DIR__ . '/fixtures/api_runtime.php';

use MajorDoMo\Core\{Config, NetworkAccess};
use MajorDoMo\Core\Api\{AccessPolicy, ApiException, BrowserAccess, Contract, Request, Schema, UserAuthentication, UserTokenStore};
use MajorDoMo\Core\Auth\LoginAttempts;
use MajorDoMo\Core\Users\{UserAccounts, Passwords};
use MajorDoMo\Core\WebSockets\{Access, Tickets};

$checks = 0;
function check(bool $condition, string $label): void { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($label); }
function denied(callable $call, string $code): void {
    try { $call(); } catch (ApiException $error) { check($error->status === 401 && $error->errorCode === $code && $error->details['reauthenticate'], $code); return; }
    throw new RuntimeException('Expected ' . $code);
}
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$root = sys_get_temp_dir() . '/mjd-api-' . bin2hex(random_bytes(8));
mkdir($root);
try {
Config::boot($root);
check(Config::string('APP_ENV') === 'testing' && Config::string('DB_HOST') === '' && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Изоляция подтверждена');
$db->exec('CREATE TABLE users (ID INTEGER PRIMARY KEY,USERNAME TEXT,NAME TEXT,PASSWORD TEXT,IS_ADMIN INTEGER,LINKED_OBJECT TEXT,IS_DEFAULT INTEGER DEFAULT 0);
    CREATE TABLE objects (ID INTEGER PRIMARY KEY,TITLE TEXT,CLASS_ID INTEGER);
    CREATE TABLE classes (ID INTEGER PRIMARY KEY,TITLE TEXT);
    INSERT INTO classes VALUES (3,\'Users\'); INSERT INTO objects VALUES (7,\'Member\',3)');
$db->exec(Schema::userSessionsSql(true));
$insert = $db->prepare('INSERT INTO users (ID,USERNAME,NAME,PASSWORD,IS_ADMIN,LINKED_OBJECT) VALUES (?,?,?,?,?,?)');
$insert->execute(array(1, 'member', 'Житель', hash('sha512', 'user-secret'), 0, 'Member'));
$insert->execute(array(2, 'admin', 'Администратор', Passwords::hash('admin-secret'), 1, ''));
$insert->execute(array(3, 'empty', 'Без пароля', '', 0, ''));
$insert->execute(array(4, 'spaces', 'Пробелы', hash('sha512', '   '), 0, ''));
$insert->execute(array(5, 'control', 'Перенос', hash('sha512', "bad\npass"), 0, ''));
$users = new UserAccounts($db); $tokens = new UserTokenStore($db);
check($users->verifyAdmin('member', 'user-secret') === null, 'Обычный пользователь не входит в админку');
$user = $users->verifyUser('member', 'user-secret');
check($user !== null && !Passwords::needsRehash($db->query('SELECT PASSWORD FROM users WHERE ID=1')->fetchColumn()), 'Вход обычного пользователя обновляет старый хеш');
check($users->verifyUser('admin', 'admin-secret') !== null && $users->verifyUser('empty', '') === null, 'Администратор входит; пустой пароль запрещён');
check($users->verifyUser('spaces', '   ') === null && $users->verifyUser('control', "bad\npass") === null, 'Некорректный старый пароль даёт отказ без исключения и утечки в журнал');
check($users->verifyUser("' OR 1=1 --", 'user-secret') === null, 'SQL-инъекция отклоняется');
$profile = $users->profile(1);
check($profile['object'] === array('id' => 7, 'title' => 'Member', 'class' => array('id' => 3, 'title' => 'Users')) && !$profile['is_admin'], 'Привязанный объект и класс');
check(!isset($profile['stamp'], $profile['PASSWORD']) && $users->profile(2)['object'] === null && $users->profile(2)['linked_object'] === null, 'Профиль без секретов и без привязки');
$db->exec("UPDATE users SET LINKED_OBJECT='Missing' WHERE ID=1");
check($users->profile(1)['linked_object'] === 'Missing' && $users->profile(1)['object'] === null, 'Удалённый объект не ломает профиль');
$db->exec("UPDATE users SET LINKED_OBJECT='Member' WHERE ID=1; DELETE FROM classes");
check($users->profile(1)['object']['class'] === null, 'Отсутствующий класс возвращается null');
$issued = $tokens->issue($user, 100000);
$secret = $issued['access_token'];
check(preg_match(UserTokenStore::PATTERN, $secret) === 1 && $issued['expires_at'] === 103600 && $issued['absolute_expires_at'] === 143200, 'Непрозрачный токен и оба срока');
check($db->query('SELECT TOKEN_HASH FROM api_user_sessions')->fetchColumn() === hash('sha256', $secret), 'Хранится только хеш токена');
check($tokens->authenticate($secret, false, 103599)['expires_at'] === 103600, 'WS-проверка не продлевает сеанс');
denied(fn() => $tokens->authenticate($secret, false, 103600), 'token_expired');
check($tokens->authenticate($secret, true, 102000)['expires_at'] === 105600, 'HTTP-запрос продлевает период бездействия');
check($tokens->authenticate($secret, true, 101000)['expires_at'] === 105600, 'Старый параллельный запрос не откатывает активность');
for ($now = 104000; $now < 143200; $now += 2000) $tokens->authenticate($secret, true, $now);
check($tokens->authenticate($secret, false, 143199)['expires_at'] === 143200, 'Продление ограничено абсолютным сроком');
denied(fn() => $tokens->authenticate($secret, true, 143200), 'token_expired');
$issued = $tokens->issue($user); $secret = $issued['access_token'];
$identity = $tokens->authenticate($secret, false);
$tokens->revoke($identity['id']);
denied(fn() => $tokens->authenticate($secret), 'token_revoked');
denied(fn() => $tokens->authenticate('mdmu_' . str_repeat('a', 64)), 'invalid_token');
denied(fn() => $tokens->authenticate('invalid'), 'invalid_token');
$secret = $tokens->issue($user)['access_token'];
$users->changePassword(1, 'changed-secret');
denied(fn() => $tokens->authenticate($secret), 'token_revoked');
$user = $users->verifyUser('member', 'changed-secret');
$secret = $tokens->issue($user)['access_token'];
$db->exec("UPDATE users SET USERNAME='renamed' WHERE ID=1");
denied(fn() => $tokens->authenticate($secret), 'token_revoked');
$user = $users->verifyUser('renamed', 'changed-secret');
$secret = $tokens->issue($user)['access_token'];
$ws = new Access(new AccessPolicy(new NetworkAccess()), new Tickets(sys_get_temp_dir()),
    static function ($token) use ($tokens): bool { $tokens->authenticate($token, false); return true; },
    static fn() => null, 'https://md.example.com');
$identity = $ws->authorize(array('REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'md.example.com', 'HTTP_ORIGIN' => 'https://ui.example.com'), array('token' => $secret));
check($identity['type'] === 'token' && $ws->valid($identity), 'Пользовательский токен подключает WS с поддомена');
$tokens->revoke($tokens->authenticate($secret, false)['id']);
check(!$ws->valid($identity), 'Отзыв закрывает уже подключённый WS');
$secret = $tokens->issue($user)['access_token'];
$identity = $tokens->authenticate($secret, false);
$db->exec('DELETE FROM users WHERE ID=1');
denied(fn() => $tokens->authenticate($secret), 'token_revoked');
$authentication = new UserAuthentication($users, $tokens, new LoginAttempts($root));
denied(fn() => $authentication->dispatch(new Request('GET', '/api/v1/auth/me'), $identity, '203.0.113.9'), 'token_revoked');
$browser = new BrowserAccess('https://md.example.com');
$server = array('HTTP_HOST' => 'md.example.com', 'HTTPS' => 'on', 'REMOTE_ADDR' => '192.168.1.5');
check(!$browser->check($server + array('HTTP_ORIGIN' => 'https://md.example.com')), 'Собственный origin не требует CORS');
foreach (array('https://example.com', 'https://ui.example.com', 'https://deep.ui.example.com:8443') as $origin) {
    check($browser->check($server + array('HTTP_ORIGIN' => $origin)), 'Origin своего домена разрешён: ' . $origin);
}
foreach (array('null', 'https://evil.test', 'https://example.com.evil.test', 'https://notexample.com', 'http://ui.example.com', 'https://example.com/', 'https://example.com?x=1', 'https://user@example.com') as $origin) {
    try { $browser->check($server + array('HTTP_ORIGIN' => $origin)); throw new RuntimeException('Origin принят: ' . $origin); }
    catch (ApiException $error) { check($error->status === 403, 'Посторонний Origin отклонён: ' . $origin); }
}
foreach (array(array('https://md.example.co.uk', 'https://evil.co.uk'), array('https://md.tenant.github.io', 'https://evil.github.io'), array('http://localhost', 'http://ui.localhost'), array('http://192.168.1.5', 'http://192.168.1.6')) as [$baseUrl, $origin]) {
    try { (new BrowserAccess($baseUrl))->check($server + array('HTTP_ORIGIN' => $origin)); throw new RuntimeException('Нарушена доменная граница'); }
    catch (ApiException $error) { check($error->status === 403, 'Общая зона, IP и localhost не расширяют доступ'); }
}
$policy = new AccessPolicy(new NetworkAccess(), baseUrl: 'https://md.example.com');
$cross = $server + array('HTTP_ORIGIN' => 'https://ui.example.com');
try { $policy->authorize($cross, static fn() => false, static fn() => true); throw new RuntimeException('Cookie или LAN разрешили доступ с поддомена'); }
catch (ApiException $error) { check($error->status === 401, 'Поддомен требует явный токен даже в LAN с cookie'); }
check($policy->authorize($cross + array('HTTP_AUTHORIZATION' => 'Bearer fixture'), static fn() => true) === 'token', 'Токен разрешает API с поддомена');
$spec = Contract::openApi();
check(isset($spec['paths']['/auth/login']['post'], $spec['paths']['/auth/me']['get'], $spec['paths']['/auth/logout']['post']) && $spec['paths']['/auth/login']['post']['security'] === array(), 'Авторизация присутствует в OpenAPI');
check($spec['paths']['/auth/me']['get']['security'] === array(array('bearerAuth' => array()), array('apiKey' => array())), 'Проверка сеанса требует токена даже в LAN');
echo 'API user auth smoke: ' . $checks . " passed\n";
} finally {
    ApiFixture::remove($root);
}

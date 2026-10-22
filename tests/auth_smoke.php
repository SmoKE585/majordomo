<?php
/** Native sessions and auth policies on a disposable SQLite database. No runtime boot. */
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('DB_HOST='); putenv('DB_NAME='); putenv('USE_REDIS=false');
putenv('LOG_DIRECTORY=');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\{Config, NetworkAccess};
use MajorDoMo\Core\Auth\{Authentication, LoginAttempts, RequestGuard};
use MajorDoMo\Core\Http\Session;
use MajorDoMo\Core\Users\{UserAccounts, Passwords};
use MajorDoMo\Core\Api\{AccessPolicy, ApiException};
use MajorDoMo\Core\Events\{EventBus, SystemEvents};

final class AuthEventFixture
{
    public bool $failWrites = false;
    public function __construct(private PDO $db) {}
    public function Insert(string $table, array &$record): int
    {
        if ($this->failWrites) throw new RuntimeException('Fixture write failure');
        if ($table !== 'system_events') throw new RuntimeException('Unexpected event table');
        $statement = $this->db->prepare('INSERT INTO system_events (NAME,PAYLOAD,SOURCE,CREATED,PROCESSED) VALUES (?,?,?,?,?)');
        $statement->execute(array_values($record));
        return (int)$this->db->lastInsertId();
    }
    public function SelectOne(string $sql): array|false { return $this->db->query($sql)->fetch(PDO::FETCH_ASSOC); }
    public function DbSafe1(mixed $value): string { return substr($this->db->quote((string)$value), 1, -1); }
}

$checks = 0;
function check(bool $condition, string $label): void { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($label); }
$root = sys_get_temp_dir() . '/mjd-auth-' . bin2hex(random_bytes(6));
mkdir($root);
file_put_contents($root . '/.env', "APP_ENV=testing\nDB_HOST=\nDB_NAME=\nUSE_REDIS=false\nTRUSTED_PROXIES=127.0.0.2/32\n");
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE users (ID INTEGER PRIMARY KEY,USERNAME TEXT,NAME TEXT,PASSWORD TEXT,IS_ADMIN INTEGER,IS_DEFAULT INTEGER DEFAULT 0)');
$insert = $db->prepare('INSERT INTO users (ID,USERNAME,NAME,PASSWORD,IS_ADMIN) VALUES (?,?,?,?,?)');
$insert->execute(array(1, 'admin', 'Admin', hash('sha512', 'old-secret'), 1));
$insert->execute(array(2, 'guest', 'Guest', hash('sha512', 'guest-secret'), 0));
$insert->execute(array(3, 'empty', 'Empty', '', 1));
try {
    Config::boot($root);
    check(Config::string('APP_ENV') === 'testing' && Config::string('DB_HOST') === '' && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Confirmed testing / SQLite isolation');
    $_SERVER = array('REMOTE_ADDR' => '203.0.113.1', 'HTTP_HOST' => 'home.test', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/panel/');
    $_GET = array('prj' => 'attacker-session-id', 'no_session' => '1');
    $session = new Session('prj', $root);
    check(session_id() !== 'attacker-session-id' && ini_get('session.use_only_cookies') === '1' && ini_get('session.use_strict_mode') === '1', 'URL session fixation and no_session ignored');
    $cookie = session_get_cookie_params();
    check($cookie['httponly'] && $cookie['samesite'] === 'Strict' && !$cookie['secure'], 'HttpOnly, Strict, browser-session cookie');
    check(!Session::isSecure(array('REMOTE_ADDR' => '203.0.113.1', 'HTTP_X_FORWARDED_PROTO' => 'https')), 'Untrusted proxy cannot choose secure mode');
    check(Session::isSecure(array('REMOTE_ADDR' => '127.0.0.2', 'HTTP_X_FORWARDED_PROTO' => 'https')), 'Trusted TLS proxy accepted');
    $session->set('filter', array('foo' => 1));
    check($session->get('filter')['foo'] === 1 && $session->has('filter'), 'Core state API');
    check($session->pull('filter')['foo'] === 1 && !$session->has('filter'), 'Pull consumes state');
    $accounts = new UserAccounts($db); $auth = new Authentication($session, $accounts);
    check(!$auth->login('guest', 'guest-secret') && !$auth->login('empty', '') && !$auth->login('admin', 'wrong'), 'Only administrator with valid nonempty password enters');
    check(!$auth->login('adm%', 'old-secret') && !$auth->login("' OR 1=1 --", 'old-secret'), 'Exact bound login, no wildcard or SQL login');
    $old = session_id(); $csrf = $session->csrfToken();
    check($auth->login('admin', 'old-secret'), 'Legacy administrator password accepted');
    check(session_id() !== $old && $session->csrfToken() !== $csrf && !$session->checkCsrf($csrf), 'Login rotates ID and CSRF');
    $hash = $db->query('SELECT PASSWORD FROM users WHERE ID=1')->fetchColumn();
    check(Passwords::verify('old-secret', $hash) && !Passwords::needsRehash($hash), 'Legacy password upgraded after login');
    check($auth->current()['id'] === 1 && $session->user()['username'] === 'admin' && !property_exists($session, 'data'), 'Identity separate from UI state; no legacy data property');
    $csrf = $session->csrfToken();
    $same = array('HTTP_HOST' => 'home.test', 'HTTP_ORIGIN' => 'http://home.test', 'REQUEST_METHOD' => 'POST');
    check(RequestGuard::allowed($session, $same + array('HTTP_X_CSRF_TOKEN' => $csrf), array(), true), 'Session API write with CSRF');
    check(!RequestGuard::allowed($session, $same, array(), true), 'Session API writes require CSRF even with same origin');
    check(!RequestGuard::allowed($session, $same + array('HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_X_CSRF_TOKEN' => $csrf), array()), 'Sibling-origin requests rejected');
    check(!RequestGuard::allowed($session, array('HTTP_HOST' => 'home.test', 'HTTP_ORIGIN' => 'http://evil.test', 'HTTP_X_CSRF_TOKEN' => $csrf), array()), 'Foreign Origin denied even with token');
    check(!RequestGuard::allowed($session, array('REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/panel/users/1/delete.html'), array()), 'Legacy GET mutations require same-origin proof');
    check(RequestGuard::allowed($session, array('REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/panel/users/1.html', 'HTTP_SEC_FETCH_SITE' => 'none'), array()), 'Address-bar and bookmark navigation works');
    $policy = new AccessPolicy(new NetworkAccess());
    check($policy->authorize(array('REMOTE_ADDR' => '203.0.113.1'), fn() => false, fn() => true) === 'session', 'Session authorizes external API');
    check($policy->authorize(array('REMOTE_ADDR' => '192.168.1.1'), fn() => false) === 'local', 'Existing local API policy retained');
    try { $policy->authorize(array('REMOTE_ADDR' => '192.168.1.1', 'HTTP_X_API_KEY' => 'invalid'), fn() => false, fn() => true); throw new RuntimeException('Invalid token accepted'); }
    catch (ApiException $e) { check($e->status === 401, 'Invalid explicit API token cannot fall back to session or LAN'); }
    $accounts->changePassword(1, 'changed-secret');
    check($auth->current() === null && $session->user() === null, 'CLI password reset revokes existing session');
    check($auth->login('admin', 'changed-secret'), 'Updated password works');
    $db->exec('UPDATE users SET IS_ADMIN=0 WHERE ID=1');
    check($auth->current() === null, 'Removing administrator rights revokes session');
    $db->exec('UPDATE users SET IS_ADMIN=1 WHERE ID=1'); $auth->login('admin', 'changed-secret');
    check($auth->current(time() + 3601) === null, 'Idle timeout enforced');
    $auth->login('admin', 'changed-secret'); $_SESSION['md_admin']['created'] = time() - 43201;
    check($auth->current() === null, 'Absolute lifetime enforced despite traffic');
    $auth->login('admin', 'changed-secret'); $db->exec('DELETE FROM users WHERE ID=1');
    check($auth->current() === null, 'Deleted user loses session');
    $long = str_repeat('a', 90) . 'x'; $longHash = Passwords::hash($long);
    check(Passwords::verify($long, $longHash) && !Passwords::verify(str_repeat('a', 90) . 'y', $longHash), 'Passwords are not truncated at 72 bytes');
    $attempts = new LoginAttempts($root); $calls = 0;
    $wrong = function () use (&$calls) { $calls++; return false; };
    check($attempts->attempt('203.0.113.2', 'admin', $wrong, 1000)['retry'] === 0, 'First error');
    $attempts->attempt('203.0.113.2', 'other', $wrong, 1001);
    check($attempts->attempt('203.0.113.2', 'admin', $wrong, 1002)['retry'] === 900, 'Three errors ban IP across logins');
    $pending = glob($root . '/var/events/*.json');
    check(count($pending) === 1, 'A ban durably enqueues exactly one system event before runtime boot');
    $queued = json_decode(file_get_contents($pending[0]), true, 32, JSON_THROW_ON_ERROR);
    check($queued['name'] === SystemEvents::AUTH_BRUTE_FORCE && $queued['payload']['ip'] === '203.0.113.2' && $queued['payload']['attempts'] === 3 && $queued['payload']['blocked_until'] === 1902, 'Brute-force event contains safe actionable details');
    check($attempts->attempt('203.0.113.2', 'admin', $wrong, 1003)['retry'] === 899 && $calls === 3, 'Banned requests never verify credentials');
    check(count(glob($root . '/var/events/*.json')) === 1, 'Repeated blocked requests do not duplicate event');
    check($attempts->attempt('203.0.113.2', 'admin', fn() => true, 1902)['ok'], 'Ban expires');
    for ($i = 0; $i < 3; $i++) $attempts->attempt('203.0.113.2', 'admin', $wrong, 2000 + $i);
    $db->exec('CREATE TABLE system_events (ID INTEGER PRIMARY KEY AUTOINCREMENT,NAME TEXT,PAYLOAD TEXT,SOURCE TEXT,CREATED TEXT,PROCESSED INTEGER)');
    $eventDatabase = new AuthEventFixture($db);
    EventBus::useDatabase(new \MajorDoMo\Core\Database($eventDatabase));
    $delivered = array();
    EventBus::on(SystemEvents::AUTH_BRUTE_FORCE, static function ($name, $payload, $source) use (&$delivered) { $delivered[] = array($name, $payload, $source); });
    check(EventBus::flushDeferred($root) === 2 && count($delivered) === 2 && $delivered[0][2] === 'auth', 'Normal runtime persists and dispatches both bans');
    check((int)$db->query('SELECT COUNT(*) FROM system_events WHERE PROCESSED=0')->fetchColumn() === 2 && glob($root . '/var/events/*.json') === array(), 'Events enter standard module delivery queue and leave filesystem outbox');
    check(EventBus::flushDeferred($root) === 0, 'Flushing again does not duplicate events');
    EventBus::defer($root, SystemEvents::AUTH_BRUTE_FORCE, array('ip' => '203.0.113.9'), 'auth');
    $eventDatabase->failWrites = true;
    check(EventBus::flushDeferred($root) === 0 && count(glob($root . '/var/events/*.json')) === 1, 'Failed DB write retains deferred event');
    $eventDatabase->failWrites = false;
    check(EventBus::flushDeferred($root) === 1 && count($delivered) === 3, 'Deferred event retries after recovery');
    check(SystemEvents::severity(SystemEvents::AUTH_BRUTE_FORCE) === 'warning', 'Brute-force event has warning severity');
    $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+');
    $cli = new \MajorDoMo\Core\Console\Application($root);
    check($cli->run(array('auth:unblock', '203.0.113.2', '--json'), $out, $err) === 0 && $attempts->attempt('203.0.113.2', 'admin', fn() => true, 2003)['ok'], 'CLI unblocks same state store');
    check($cli->run(array('auth:unblock', 'not-an-ip', '--json'), $out, $err) === 2, 'CLI validates IP');
    fclose($out); fclose($err);
    $log = file_get_contents(glob($root . '/cms/debmes/*/auth.log')[0]);
    check(str_contains($log, 'failure') && str_contains($log, 'banned') && str_contains($log, 'success') && !str_contains($log, 'old-secret'), 'Audit records attempts without passwords');
    session_write_close();
    echo 'Auth smoke: ' . $checks . " passed (isolated SQLite).\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { if ($item->isDir()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($root);
}

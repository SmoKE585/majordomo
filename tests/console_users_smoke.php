<?php

/** Styled CLI + user password recovery: SQLite in memory, injected secret input. */
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_HOST=');
putenv('DB_NAME=');
putenv('USE_REDIS=false');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Console\{Application, Output, TerminalInput};
use MajorDoMo\Core\Users\UserAccounts;
use MajorDoMo\Core\Config;

$checks = 0;
function check(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}

function executeCli(Application $application, array $arguments, bool $json = true): array
{
    $out = fopen('php://temp', 'w+');
    $err = fopen('php://temp', 'w+');
    try {
        if ($json) $arguments[] = '--json';
        $code = $application->run($arguments, $out, $err);
        rewind($out); rewind($err);
        $stdout = stream_get_contents($out);
        $stderr = stream_get_contents($err);
        $data = $json ? json_decode($stdout, true, 512, JSON_THROW_ON_ERROR) : null;
        if ($json) check($stderr === '' && $data['ok'] === ($code === 0) && !str_contains($stdout, "\033"), 'JSON remains clean without ANSI');
        return array($code, $data, $stdout, $stderr);
    } finally { fclose($out); fclose($err); }
}

$root = sys_get_temp_dir() . '/mjd-cli-users-' . bin2hex(random_bytes(6));
mkdir($root);
file_put_contents($root . '/.env', "APP_ENV=testing\nDB_HOST=\nDB_NAME=\nUSE_REDIS=false\n");
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE users (ID INTEGER PRIMARY KEY, USERNAME TEXT, NAME TEXT, PASSWORD TEXT, IS_ADMIN INTEGER, IS_DEFAULT INTEGER)');
$insert = $db->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?)');
$insert->execute(array(1, 'admin', 'Администратор', hash('sha512', 'old-admin'), 1, 1));
$insert->execute(array(2, 'guest', 'Гость', hash('sha512', 'old-guest'), 0, 0));
$insert->execute(array(3, 'doubles', 'Другой пользователь', '', 0, 0));
$accounts = new UserAccounts($db);
$stdin = fopen('php://temp', 'w+');
$prompts = fopen('php://temp', 'w+');
try {
    Config::boot($root);
    check(Config::string('APP_ENV') === 'testing' && Config::string('DB_NAME') === '' && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Actual environment is testing with SQLite, not production');
    $factory = static fn() => $accounts;
    $app = new Application($root, null, $factory, new TerminalInput($stdin, $prompts));
    [$code, $data, $stdout] = executeCli($app, array('users:list'));
    check($code === 0 && $data['data']['total'] === 3 && count($data['data']['users']) === 3, 'User list reads SQLite table');
    check(!str_contains($stdout, 'PASSWORD') && !str_contains($stdout, hash('sha512', 'old-admin')), 'User list contains neither password nor hash');
    check((int)$data['data']['users'][0]['IS_ADMIN'] === 1 && (int)$data['data']['users'][1]['IS_ADMIN'] === 0, 'User roles are displayed from users');
    [$code, $data] = executeCli($app, array('users:list', '--limit=1', '--offset=1'));
    check($code === 0 && count($data['data']['users']) === 1 && (int)$data['data']['users'][0]['ID'] === 2, 'Pagination has stable ID ordering');
    [$code, $data] = executeCli($app, array('users:list', '--search=Гость'));
    check($code === 0 && $data['data']['total'] === 1 && $data['data']['users'][0]['USERNAME'] === 'guest', 'Search supports user names');
    [$code, $data] = executeCli($app, array('users:list', '--search=\' OR 1=1 --'));
    check($code === 0 && $data['data']['total'] === 0, 'Search input stays a bound parameter');
    foreach (array('--limit=0', '--limit=101', '--offset=-1', '--limit=abc') as $option) {
        [$code] = executeCli($app, array('users:list', $option));
        check($code === 2, 'Invalid user pagination rejected: ' . $option);
    }
    foreach (array('0', '-1', '../1', '4294967296', '1 OR 1=1') as $id) {
        [$code] = executeCli($app, array('users:password', $id, '--password-stdin'));
        check($code === 2, 'Invalid account ID rejected');
    }
    [$code] = executeCli($app, array('users:password', '999', '--password-stdin'));
    check($code === 2, 'Unknown account fails before reading a secret');

    $beforeGuest = $db->query('SELECT * FROM users WHERE ID=2')->fetch(PDO::FETCH_ASSOC);
    $beforeAdmin = $db->query('SELECT * FROM users WHERE ID=1')->fetch(PDO::FETCH_ASSOC);
    fwrite($stdin, "Новый пароль123\r\n"); rewind($stdin);
    [$code, $data, $stdout, $stderr] = executeCli($app, array('users:password', '2', '--password-stdin'));
    check($code === 0 && $data['data']['changed'] && (int)$data['data']['user']['ID'] === 2, 'Password-stdin updates selected account');
    $after = $db->query('SELECT * FROM users WHERE ID=2')->fetch(PDO::FETCH_ASSOC);
    check(\MajorDoMo\Core\Users\Passwords::verify('Новый пароль123', $after['PASSWORD']) && !\MajorDoMo\Core\Users\Passwords::needsRehash($after['PASSWORD']), 'Password recovery stores a salted modern hash');
    unset($beforeGuest['PASSWORD'], $after['PASSWORD']);
    check($beforeGuest === $after && $db->query('SELECT * FROM users WHERE ID=1')->fetch(PDO::FETCH_ASSOC) === $beforeAdmin, 'Only selected password changes; roles and other accounts are preserved');
    check(!str_contains($stdout . $stderr, 'Новый пароль123') && !str_contains($stdout, hash('sha512', 'Новый пароль123')), 'Password and its hash never reach output');

    $before = $db->query('SELECT PASSWORD FROM users WHERE ID=2')->fetchColumn();
    foreach (array('', "\n", str_repeat('x', 1025) . "\n", "bad\0secret\n", "   \n") as $value) {
        ftruncate($stdin, 0); rewind($stdin); fwrite($stdin, $value); rewind($stdin);
        [$code] = executeCli($app, array('users:password', '2', '--password-stdin'));
        check($code === 2 && $db->query('SELECT PASSWORD FROM users WHERE ID=2')->fetchColumn() === $before, 'Empty, invalid or oversized stdin never changes password');
    }
    [$code] = executeCli($app, array('users:password', '2'));
    check($code === 2, 'JSON password command requires explicit stdin input');
    [$code] = executeCli($app, array('users:password', '2'), false);
    check($code === 2, 'No visible password fallback on noninteractive terminal');
    [$code, , $stdout, $stderr] = executeCli($app, array('users:password', '2', '--password=do-not-print'));
    check($code === 2 && !str_contains($stdout . $stderr, 'do-not-print'), 'Password argv option is rejected without echoing its value');

    $secrets = array('interactive-secret', 'different-secret');
    $hidden = new TerminalInput($stdin, $prompts, static function () use (&$secrets) { return array_shift($secrets); });
    $interactive = new Application($root, null, $factory, $hidden);
    [$code, , $stdout, $stderr] = executeCli($interactive, array('users:password', '2'), false);
    check($code === 2 && $db->query('SELECT PASSWORD FROM users WHERE ID=2')->fetchColumn() === $before, 'Mismatching hidden inputs do not save');
    check(!str_contains($stdout . $stderr, 'interactive-secret'), 'Hidden mismatch error reveals no secret');
    $secrets = array('interactive-secret', 'interactive-secret');
    [$code, , $stdout, $stderr] = executeCli($interactive, array('users:password', '2'), false);
    check($code === 0 && \MajorDoMo\Core\Users\Passwords::verify('interactive-secret', $db->query('SELECT PASSWORD FROM users WHERE ID=2')->fetchColumn()), 'Confirmed hidden password is stored');
    check(!str_contains($stdout . $stderr, 'interactive-secret'), 'Successful hidden flow reveals no password');
    [$code] = executeCli($interactive, array('users:password', '2', '--password-stdin'));
    check($code === 2, 'Stdin option cannot cause visible interactive input');
    [$code, , $stdout] = executeCli(new Application($root), array('users:list'));
    check($code === 1 && !str_contains($stdout, 'DB_PASSWORD='), 'Unconfigured live database is refused');

    $db->exec('DROP TABLE users');
    [$code, , $stdout] = executeCli($app, array('users:list'));
    check($code === 1 && !str_contains($stdout, 'SQLSTATE'), 'Driver error becomes a concise sanitized error');

    [$code, , $plain] = executeCli($app, array('list', '--no-ansi'), false);
    check($code === 0 && !str_contains($plain, "\033") && str_contains($plain, 'Пользователи · users') && str_contains($plain, 'Циклы · cycles'), 'Plain listing is grouped and contains no ANSI');
    [$code, , $styled] = executeCli($app, array('list', '--ansi'), false);
    check($code === 0 && str_contains($styled, "\033["), 'Forced ANSI renders styled headings');
    [$code] = executeCli($app, array('list', '--ansi', '--no-ansi'));
    check($code === 2, 'Conflicting color flags fail');
    [$code] = executeCli($app, array('list', '--ansi'));
    check($code === 0, 'JSON ignores ANSI decoration');
    [$code, , $redirected] = executeCli($app, array('list'), false);
    check($code === 0 && !str_contains($redirected, "\033"), 'Redirection automatically disables color');

    $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+');
    $output = new Output($out, $err, 40);
    $output->setAnsi(false);
    $output->table(array('ID', 'Логин', 'Имя', 'Права', 'Основной'), array(array(1, 'long-login-without-spaces', 'Длинное имя пользователя', 'Администратор', 'yes')));
    $output->table(array('A', 'B'), array(array("\033[31mbad\033[0m\033]0;bad-title\007", 'safe')));
    $output->status('WARN', str_repeat('Очень длинная строка ', 10));
    rewind($out); $text = stream_get_contents($out);
    check(!str_contains($text, "\033") && !str_contains($text, 'bad-title'), 'Untrusted table cells cannot inject terminal escapes');
    foreach (explode("\n", $text) as $line) check(mb_strwidth(rtrim($line, "\r"), 'UTF-8') <= 40, 'Narrow tables and statuses stay inside terminal width');
    fclose($out); fclose($err);
    echo 'OK: ' . $checks . " console style and user checks (SQLite in memory).\n";
} finally {
    fclose($stdin); fclose($prompts);
    // Only files created under this unique temporary fixture are removed.
    foreach (glob($root . '/cms/cached/*') ?: array() as $file) unlink($file);
    if (is_dir($root . '/cms/cached')) rmdir($root . '/cms/cached');
    if (is_dir($root . '/cms')) rmdir($root . '/cms');
    unlink($root . '/.env');
    rmdir($root);
}

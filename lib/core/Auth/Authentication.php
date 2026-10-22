<?php
namespace MajorDoMo\Core\Auth;
use MajorDoMo\Core\Http\Session;
use MajorDoMo\Core\Users\UserAccounts;

final class Authentication
{
    public function __construct(private Session $session, private UserAccounts $users) {}
    public function current(?int $now = null): ?array
    {
        $now ??= time();
        $auth = $_SESSION['md_admin'] ?? null;
        if (!is_array($auth)) return null;
        $user = $this->users->adminState((int)($auth['id'] ?? 0));
        if ($user === null || !hash_equals($user['stamp'], (string)($auth['stamp'] ?? ''))
            || (int)($auth['last'] ?? 0) <= $now - 3600 || (int)($auth['created'] ?? 0) <= $now - 43200) {
            $this->session->logout();
            return null;
        }
        $_SESSION['md_admin']['last'] = $now;
        $_SESSION['md_admin']['username'] = $user['username'];
        $_SESSION['md_admin']['name'] = $user['name'];
        return $user;
    }
    public function login(string $username, string $password): bool
    {
        $user = $this->users->verifyAdmin($username, $password);
        if ($user === null) return false;
        $this->session->logout();
        $_SESSION['md_admin'] = array('id' => $user['id'], 'stamp' => $user['stamp'], 'username' => $user['username'], 'name' => $user['name'], 'created' => time(), 'last' => time());
        return true;
    }
}

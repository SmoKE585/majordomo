<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\Auth\LoginAttempts;
use MajorDoMo\Core\Users\UserAccounts;

/**
 * Вход, профиль и выход внешнего интерфейса без cookie администратора.
 * Изменения: 2026-10-23 — единые учётные записи users и общая защита от перебора.
 */
final class UserAuthentication
{
    public function __construct(private UserAccounts $users, private UserTokenStore $tokens, private LoginAttempts $attempts) {}

    public function dispatch(Request $request, ?array $identity, string $ip): array
    {
        [$operation] = Contract::match($request);
        if ($operation['body'] !== null) Contract::validate($request->body, $operation['body']);
        if ($operation['action'] === 'authLogin') {
            $username = trim($request->body['username']);
            $user = null;
            $result = $this->attempts->attempt($ip, $username, function () use ($username, $request, &$user): bool {
                $user = $this->users->verifyUser($username, $request->body['password']);
                return $user !== null;
            });
            if (!$result['ok']) {
                if ($result['retry'] > 0) throw new ApiException(429, 'login_throttled', 'Слишком много попыток входа. Повторите позже.', array('retry_after' => $result['retry']), array('Retry-After' => (string)$result['retry']));
                throw new ApiException(401, 'invalid_credentials', 'Неверный логин или пароль.');
            }
            $profile = $this->profile($user['id']);
            return $this->tokens->issue($user) + array('user' => $profile);
        }
        if ($identity === null) throw new ApiException(401, 'user_token_required', 'Передайте пользовательский Bearer-токен.', array('reauthenticate' => true), array('WWW-Authenticate' => 'Bearer'));
        if ($operation['action'] === 'authLogout') {
            $this->tokens->revoke($identity['id']);
            return array('logged_out' => true);
        }
        if ($operation['action'] !== 'authMe') throw new ApiException(404, 'not_found', 'Маршрут не найден.');
        $userId = $identity['user_id'];
        unset($identity['id'], $identity['user_id']);
        return array('user' => $this->profile($userId)) + $identity;
    }

    private function profile(int $id): array
    {
        try { return $this->users->profile($id); }
        catch (\InvalidArgumentException $error) {
            // Учётную запись могли удалить между проверкой токена и чтением профиля.
            throw new ApiException(401, 'token_revoked', 'Учётная запись удалена. Войдите снова.', array('reauthenticate' => true), array('WWW-Authenticate' => 'Bearer error="invalid_token"'));
        }
    }
}

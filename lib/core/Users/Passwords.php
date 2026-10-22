<?php
namespace MajorDoMo\Core\Users;

/** Versioned prehash avoids bcrypt's 72-byte truncation for legacy passwords. */
final class Passwords
{
    private const PREFIX = 'mdm1:';
    public static function hash(string $password): string
    {
        self::validate($password);
        return self::PREFIX . password_hash(self::prepare($password), PASSWORD_DEFAULT);
    }
    public static function validate(string $password): void
    {
        if (trim($password) === '' || strlen($password) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            throw new \InvalidArgumentException('Пароль должен содержать от 1 до 1024 байт, без управляющих символов.');
        }
    }
    public static function verify(string $password, string $hash): bool
    {
        if ($password === '' || strlen($password) > 1024 || $hash === '') return false;
        if (preg_match('/^[a-f0-9]{128}$/D', $hash)) return hash_equals($hash, hash('sha512', $password));
        if (str_starts_with($hash, self::PREFIX)) return password_verify(self::prepare($password), substr($hash, strlen(self::PREFIX)));
        return password_verify($password, $hash);
    }
    public static function needsRehash(string $hash): bool
    {
        return !str_starts_with($hash, self::PREFIX) || password_needs_rehash(substr($hash, strlen(self::PREFIX)), PASSWORD_DEFAULT);
    }
    private static function prepare(string $password): string { return base64_encode(hash('sha384', $password, true)); }
}

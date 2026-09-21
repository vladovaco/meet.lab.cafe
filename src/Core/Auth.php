<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['user_id'])) {
            self::$user = Database::one('SELECT id, email, name, role FROM users WHERE id = ?', [(int) $_SESSION['user_id']]);
        }
        return self::$user;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function attempt(string $email, string $password): bool
    {
        $row = Database::one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
        if ($row === null || !password_verify($password, $row['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
        self::$user = null;
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }
}

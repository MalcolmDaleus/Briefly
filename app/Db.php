<?php
declare(strict_types=1);

final class Db
{
    private const SCHEMA_VERSION = 1;

    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $pdo = new PDO('sqlite:' . DATA_DIR . '/briefly.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 8000');
        if (config('sqlite_wal', true)) {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }
        if ($version < 1) {
            $pdo->exec((string) file_get_contents(APP_DIR . '/schema.sql'));
            $pdo->exec('PRAGMA user_version = 1');
            Sources::importSeed();
        }
        // Future migrations: if ($version < 2) { ...; PRAGMA user_version = 2 }
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

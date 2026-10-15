<?php
declare(strict_types=1);

final class Db
{
    private const SCHEMA_VERSION = 5;

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
            $version = 1;
        }
        if ($version < 2) {
            $cols = $pdo->query('PRAGMA table_info(user_articles)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('note', $cols, true)) {
                $pdo->exec('ALTER TABLE user_articles ADD COLUMN note TEXT');
            }
            $pdo->exec('PRAGMA user_version = 2');
            $version = 2;
        }
        if ($version < 3) {
            $cols = $pdo->query('PRAGMA table_info(user_articles)')->fetchAll(PDO::FETCH_COLUMN, 1);
            $add = static function (string $col, string $ddl) use ($pdo, $cols): void {
                if (!in_array($col, $cols, true)) {
                    $pdo->exec("ALTER TABLE user_articles ADD COLUMN {$ddl}");
                }
            };
            $add('opened', 'opened INTEGER NOT NULL DEFAULT 0');
            $add('open_count', 'open_count INTEGER NOT NULL DEFAULT 0');
            $add('first_opened_at', 'first_opened_at TEXT');
            $add('last_opened_at', 'last_opened_at TEXT');
            $add('first_saved_at', 'first_saved_at TEXT');
            $pdo->exec("UPDATE user_articles SET first_saved_at = saved_at WHERE first_saved_at IS NULL AND saved_at IS NOT NULL");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_articles_opened ON user_articles (user_id, opened, open_count)');
            $pdo->exec('PRAGMA user_version = 3');
            $version = 3;
        }
        if ($version < 4) {
            $articleCols = $pdo->query('PRAGMA table_info(articles)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('paywall_status', $articleCols, true)) {
                $pdo->exec("ALTER TABLE articles ADD COLUMN paywall_status TEXT NOT NULL DEFAULT 'unknown'");
            }
            if (!in_array('paywall_reason', $articleCols, true)) {
                $pdo->exec('ALTER TABLE articles ADD COLUMN paywall_reason TEXT');
            }
            if (!in_array('paywall_checked_at', $articleCols, true)) {
                $pdo->exec('ALTER TABLE articles ADD COLUMN paywall_checked_at TEXT');
            }
            $uaCols = $pdo->query('PRAGMA table_info(user_articles)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('dismissed', $uaCols, true)) {
                $pdo->exec('ALTER TABLE user_articles ADD COLUMN dismissed INTEGER NOT NULL DEFAULT 0');
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_articles_paywall ON articles (paywall_status)');
            $pdo->exec('PRAGMA user_version = 4');
            $version = 4;
        }
        if ($version < 5) {
            $userCols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('feed_filters', $userCols, true)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN feed_filters TEXT');
            }
            $sourceCols = $pdo->query('PRAGMA table_info(sources)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('paywall_safe', $sourceCols, true)) {
                $pdo->exec('ALTER TABLE sources ADD COLUMN paywall_safe INTEGER NOT NULL DEFAULT 0');
            }
            $pdo->exec('PRAGMA user_version = 5');
        }
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

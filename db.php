<?php
/**
 * 数据库连接 + 初始化
 * 支持 SQLite（默认，单文件）或 MySQL（共享主机常见）
 */
require_once __DIR__ . '/config.php';

class DB {
    private static $pdo = null;

    /** 判断当前数据库驱动：sqlite / mysql */
    public static function driver(): string {
        $dsn = Config::$DB_DSN;
        if ($dsn === '') return 'sqlite';
        return str_starts_with(strtolower($dsn), 'mysql:') ? 'mysql' : 'sqlite';
    }

    public static function pdo() {
        if (self::$pdo === null) {
            $isSqlite = self::driver() === 'sqlite';
            if ($isSqlite) {
                $path = Config::$DB_PATH;
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $dsn = 'sqlite:' . $path;
                $user = null;
                $pass = null;
            } else {
                $dsn = Config::$DB_DSN;
                $user = Config::$DB_USER;
                $pass = Config::$DB_PASS;
            }

            try {
                self::$pdo = new PDO($dsn, $user, $pass);
            } catch (PDOException $e) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                error_log("Database connection failed: {$e->getMessage()}");
                echo json_encode([
                    'error' => '数据库连接失败',
                    'detail' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            if ($isSqlite) {
                // WAL 在部分网络文件系统上可能失败，捕获异常降级
                try {
                    self::$pdo->exec('PRAGMA journal_mode = WAL');
                } catch (Throwable $e) {
                    error_log('SQLite WAL 设置失败（可能为网络文件系统），已降级：' . $e->getMessage());
                }
                self::$pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                self::$pdo->exec("SET NAMES utf8mb4");
                self::$pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            }

            self::init_schema();
            Config::load_from_db();
        }
        return self::$pdo;
    }

    /** 表名（带前缀） */
    public static function table(string $name): string {
        return (Config::$DB_PREFIX ?: '') . $name;
    }

    /** 当前时间 SQL 表达式 */
    public static function nowExpr(): string {
        return self::driver() === 'mysql' ? 'NOW()' : "datetime('now')";
    }

    /** 判断某列是否存在 */
    public static function columnExists(string $table, string $column): bool {
        $pdo = self::pdo();
        $tableName = self::table($table);
        if (self::driver() === 'mysql') {
            $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $tableName . ' LIKE ?');
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        }
        $stmt = $pdo->query('PRAGMA table_info(' . $tableName . ')');
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        return in_array($column, $cols, true);
    }

    /** 添加列 */
    public static function addColumn(string $table, string $column, string $def): void {
        $pdo = self::pdo();
        $tableName = self::table($table);
        $pdo->exec("ALTER TABLE {$tableName} ADD COLUMN {$column} {$def}");
    }

    private static function init_schema() {
        $pdo = self::$pdo;
        $driver = self::driver();
        $tables = self::schema();

        foreach ($tables as $name => $def) {
            $tableName = self::table($name);
            $sql = str_replace('{table}', $tableName, $def[$driver]);
            if ($driver === 'mysql' && stripos($sql, 'ENGINE=') === false) {
                $sql = rtrim($sql, ';') . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
            }
            $pdo->exec($sql);

            foreach (($def['indexes'] ?? []) as $idxSql) {
                $pdo->exec(str_replace('{table}', $tableName, $idxSql));
            }
        }

        // 迁移：为旧数据库补列
        $isSqlite = $driver === 'sqlite';
        $boolDef = $isSqlite ? 'INTEGER DEFAULT 0' : 'TINYINT(1) DEFAULT 0';
        $textDef = $isSqlite ? 'TEXT' : 'TEXT';
        $varcharDef = $isSqlite ? 'TEXT' : 'VARCHAR(255)';

        if (!self::columnExists('users', 'is_admin')) self::addColumn('users', 'is_admin', $boolDef);
        if (!self::columnExists('users', 'is_banned')) self::addColumn('users', 'is_banned', $boolDef);
        if (!self::columnExists('users', 'banned_reason')) self::addColumn('users', 'banned_reason', $textDef);

        if (!self::columnExists('soups', 'host_manual')) self::addColumn('soups', 'host_manual', $textDef);
        if (!self::columnExists('soups', 'extra')) self::addColumn('soups', 'extra', $textDef);
        if (!self::columnExists('rooms', 'ai_question_limit')) self::addColumn('rooms', 'ai_question_limit', 'INTEGER DEFAULT 0');
        if (!self::columnExists('rooms', 'member_limit')) self::addColumn('rooms', 'member_limit', 'INTEGER DEFAULT 0');
        if (!self::columnExists('rooms', 'ai_question_count')) self::addColumn('rooms', 'ai_question_count', 'INTEGER DEFAULT 0');
        if (!self::columnExists('soups', 'status')) self::addColumn('soups', 'status', $isSqlite ? "TEXT DEFAULT 'approved'" : "VARCHAR(255) DEFAULT 'approved'");
        if (!self::columnExists('soups', 'reject_reason')) self::addColumn('soups', 'reject_reason', $textDef);
        if (!self::columnExists('soups', 'images')) self::addColumn('soups', 'images', $isSqlite ? "TEXT DEFAULT '[]'" : "LONGTEXT");
        if (!self::columnExists('soups', 'view_count')) self::addColumn('soups', 'view_count', 'INTEGER DEFAULT 0');
        if (!self::columnExists('rooms', 'ai_ask_count')) self::addColumn('rooms', 'ai_ask_count', 'INTEGER DEFAULT 0');
        if (!self::columnExists('rooms', 'room_type')) self::addColumn('rooms', 'room_type', $isSqlite ? "TEXT DEFAULT 'normal'" : "VARCHAR(255) DEFAULT 'normal'");
        if (!self::columnExists('rooms', 'state')) self::addColumn('rooms', 'state', $isSqlite ? "TEXT DEFAULT '{}'" : "LONGTEXT");
        if (!self::columnExists('rooms', 'ai_key_encrypted')) self::addColumn('rooms', 'ai_key_encrypted', $textDef);

        // 第一个注册的用户自动设为管理员（如果还没有管理员）
        $tableName = self::table('users');
        $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM ' . $tableName . ' WHERE is_admin = 1')->fetchColumn();
        if ($adminCount === 0) {
            $firstUser = $pdo->query('SELECT id FROM ' . $tableName . ' ORDER BY id LIMIT 1')->fetch();
            if ($firstUser) {
                $pdo->exec('UPDATE ' . $tableName . ' SET is_admin = 1 WHERE id = ' . (int)$firstUser['id']);
            }
        }
    }

    private static function schema(): array {
        $isSqlite = self::driver() === 'sqlite';
        if ($isSqlite) {
            $text = 'TEXT';
            $now = "datetime('now')";
            $pk = 'INTEGER PRIMARY KEY AUTOINCREMENT';
            $bool = 'INTEGER DEFAULT 0';
        } else {
            $text = 'LONGTEXT';
            $now = 'CURRENT_TIMESTAMP';
            $pk = 'INT PRIMARY KEY AUTO_INCREMENT';
            $bool = 'TINYINT(1) DEFAULT 0';
        }

        // 通用索引模板（{table} 会被替换）
        $idx = function($name, $cols, $unique = false) {
            $u = $unique ? 'UNIQUE ' : '';
            return "CREATE {$u}INDEX IF NOT EXISTS {$name} ON {table} ({$cols})";
        };

        return [
            'users' => [
                'sqlite' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    username TEXT NOT NULL UNIQUE,
                    email TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    created_at TEXT DEFAULT ({$now}),
                    is_admin {$bool},
                    is_banned {$bool},
                    banned_reason TEXT
                );",
                'mysql' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    username VARCHAR(255) NOT NULL UNIQUE,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    created_at DATETIME DEFAULT {$now},
                    is_admin {$bool},
                    is_banned {$bool},
                    banned_reason TEXT
                );",
                'indexes' => [
                    $idx('idx_users_username', 'username'),
                    $idx('idx_users_email', 'email'),
                ],
            ],
            'soups' => [
                'sqlite' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    filename TEXT NOT NULL UNIQUE,
                    season TEXT,
                    episode TEXT,
                    title TEXT NOT NULL,
                    surface TEXT,
                    base TEXT,
                    host_manual TEXT,
                    extra TEXT,
                    author_id INTEGER,
                    created_at TEXT DEFAULT ({$now}),
                    sort_order INTEGER DEFAULT 0,
                    status TEXT DEFAULT 'approved',
                    reject_reason TEXT,
                    images TEXT DEFAULT '[]',
                    view_count INTEGER DEFAULT 0,
                    FOREIGN KEY (author_id) REFERENCES users(id)
                );",
                'mysql' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    filename VARCHAR(255) NOT NULL UNIQUE,
                    season VARCHAR(255),
                    episode VARCHAR(255),
                    title VARCHAR(255) NOT NULL,
                    surface LONGTEXT,
                    base LONGTEXT,
                    host_manual LONGTEXT,
                    extra LONGTEXT,
                    author_id INT,
                    created_at DATETIME DEFAULT {$now},
                    sort_order INT DEFAULT 0,
                    status VARCHAR(255) DEFAULT 'approved',
                    reject_reason TEXT,
                    images LONGTEXT,
                    view_count INT DEFAULT 0,
                    FOREIGN KEY (author_id) REFERENCES users(id)
                );",
                'indexes' => [
                    $idx('idx_soups_season', 'season'),
                ],
            ],
            'rooms' => [
                'sqlite' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    code TEXT NOT NULL UNIQUE,
                    host_id INTEGER NOT NULL,
                    soup_id INTEGER,
                    status TEXT DEFAULT 'playing',
                    ai_enabled INTEGER DEFAULT 1,
                    ai_question_limit INTEGER DEFAULT 0,
                    member_limit INTEGER DEFAULT 0,
                    ai_question_count INTEGER DEFAULT 0,
                    ai_ask_count INTEGER DEFAULT 0,
                    room_type TEXT DEFAULT 'normal',
                    state TEXT DEFAULT '{}',
                    ai_key_encrypted TEXT,
                    created_at TEXT DEFAULT ({$now}),
                    FOREIGN KEY (host_id) REFERENCES users(id),
                    FOREIGN KEY (soup_id) REFERENCES soups(id)
                );",
                'mysql' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    code VARCHAR(255) NOT NULL UNIQUE,
                    host_id INT NOT NULL,
                    soup_id INT,
                    status VARCHAR(255) DEFAULT 'playing',
                    ai_enabled {$bool} DEFAULT 1,
                    ai_question_limit INT DEFAULT 0,
                    member_limit INT DEFAULT 0,
                    ai_question_count INT DEFAULT 0,
                    ai_ask_count INT DEFAULT 0,
                    room_type VARCHAR(255) DEFAULT 'normal',
                    state LONGTEXT,
                    ai_key_encrypted LONGTEXT,
                    created_at DATETIME DEFAULT {$now},
                    FOREIGN KEY (host_id) REFERENCES users(id),
                    FOREIGN KEY (soup_id) REFERENCES soups(id)
                );",
                'indexes' => [
                    $idx('idx_rooms_code', 'code'),
                    $idx('idx_rooms_status', 'status'),
                    $idx('idx_rooms_type', 'room_type'),
                ],
            ],
            'messages' => [
                'sqlite' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    room_id INTEGER NOT NULL,
                    user_id INTEGER,
                    username TEXT,
                    msg_type TEXT NOT NULL,
                    content TEXT NOT NULL,
                    created_at TEXT DEFAULT ({$now}),
                    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id)
                );",
                'mysql' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    room_id INT NOT NULL,
                    user_id INT,
                    username VARCHAR(255),
                    msg_type VARCHAR(255) NOT NULL,
                    content LONGTEXT NOT NULL,
                    created_at DATETIME DEFAULT {$now},
                    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id)
                );",
                'indexes' => [
                    $idx('idx_messages_room', 'room_id, id'),
                ],
            ],
            'settings' => [
                'sqlite' => "CREATE TABLE IF NOT EXISTS {table} (
                    key TEXT PRIMARY KEY,
                    value TEXT,
                    updated_at TEXT DEFAULT ({$now})
                );",
                'mysql' => "CREATE TABLE IF NOT EXISTS {table} (
                    key VARCHAR(255) PRIMARY KEY,
                    value LONGTEXT,
                    updated_at DATETIME DEFAULT {$now}
                );",
                'indexes' => [],
            ],
            'admin_logs' => [
                'sqlite' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    admin_id INTEGER,
                    admin_name TEXT,
                    action TEXT NOT NULL,
                    target TEXT,
                    detail TEXT,
                    ip TEXT,
                    created_at TEXT DEFAULT ({$now})
                );",
                'mysql' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    admin_id INT,
                    admin_name VARCHAR(255),
                    action VARCHAR(255) NOT NULL,
                    target VARCHAR(255),
                    detail LONGTEXT,
                    ip VARCHAR(64),
                    created_at DATETIME DEFAULT {$now}
                );",
                'indexes' => [
                    $idx('idx_admin_logs_created', 'created_at'),
                ],
            ],
            'comments' => [
                'sqlite' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    soup_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    username TEXT NOT NULL,
                    content TEXT NOT NULL,
                    created_at TEXT DEFAULT ({$now}),
                    deleted_at TEXT,
                    FOREIGN KEY (soup_id) REFERENCES soups(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id)
                );",
                'mysql' => "CREATE TABLE IF NOT EXISTS {table} (
                    id {$pk},
                    soup_id INT NOT NULL,
                    user_id INT NOT NULL,
                    username VARCHAR(255) NOT NULL,
                    content LONGTEXT NOT NULL,
                    created_at DATETIME DEFAULT {$now},
                    deleted_at DATETIME,
                    FOREIGN KEY (soup_id) REFERENCES soups(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id)
                );",
                'indexes' => [
                    $idx('idx_comments_soup', 'soup_id, id'),
                ],
            ],
            'follows' => [
                'sqlite' => "CREATE TABLE IF NOT EXISTS {table} (
                    follower_id INTEGER NOT NULL,
                    following_id INTEGER NOT NULL,
                    created_at TEXT DEFAULT ({$now}),
                    PRIMARY KEY (follower_id, following_id),
                    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
                );",
                'mysql' => "CREATE TABLE IF NOT EXISTS {table} (
                    follower_id INT NOT NULL,
                    following_id INT NOT NULL,
                    created_at DATETIME DEFAULT {$now},
                    PRIMARY KEY (follower_id, following_id),
                    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
                );",
                'indexes' => [
                    $idx('idx_follows_follower', 'follower_id'),
                    $idx('idx_follows_following', 'following_id'),
                ],
            ],
            'room_members' => [
                'sqlite' => "CREATE TABLE IF NOT EXISTS {table} (
                    room_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    role TEXT NOT NULL DEFAULT 'player',
                    character_name TEXT,
                    joined_at TEXT DEFAULT ({$now}),
                    PRIMARY KEY (room_id, user_id),
                    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                );",
                'mysql' => "CREATE TABLE IF NOT EXISTS {table} (
                    room_id INT NOT NULL,
                    user_id INT NOT NULL,
                    role VARCHAR(255) NOT NULL DEFAULT 'player',
                    character_name VARCHAR(255),
                    joined_at DATETIME DEFAULT {$now},
                    PRIMARY KEY (room_id, user_id),
                    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                );",
                'indexes' => [
                    $idx('idx_room_members_room', 'room_id'),
                    $idx('idx_room_members_user', 'user_id'),
                ],
            ],
        ];
    }

    /** 导入 soups 目录的 MD 文件（仅当表为空时） */
    public static function import_soups_if_empty() {
        $pdo = self::pdo();
        $table = self::table('soups');
        $count = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        if ($count > 0) return;

        $dir = Config::$SOUPS_DIR;
        if (!is_dir($dir)) {
            $alt = __DIR__ . '/data/soups';
            if (is_dir($alt)) $dir = $alt;
            else return;
        }

        require_once __DIR__ . '/lib/md.php';
        $files = array_filter(scandir($dir), function ($f) { return str_ends_with($f, '.md'); });
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        $cols = 'filename, season, episode, title, surface, base, host_manual, extra, sort_order';
        $stmt = $pdo->prepare("INSERT INTO {$table} ({$cols}) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $pdo->beginTransaction();
        foreach ($files as $idx => $f) {
            $content = file_get_contents($dir . '/' . $f);
            $p = parse_md($f, $content);
            $stmt->execute([$p['filename'], $p['season'], $p['episode'], $p['title'], $p['surface'], $p['base'], $p['host_manual'], $p['extra'], $idx]);
        }
        $pdo->commit();
    }

    /**
     * 重新解析并更新所有汤的字段（保留 id/author_id/sort_order/created_at）
     * 用于 parse_md 升级后刷新已有数据。仅管理员可触发。
     * @return array {updated, skipped, total}
     */
    public static function reimport_all(): array {
        $pdo = self::pdo();
        $table = self::table('soups');
        $dir = Config::$SOUPS_DIR;
        if (!is_dir($dir)) {
            $alt = __DIR__ . '/data/soups';
            if (is_dir($alt)) $dir = $alt;
            else return ['updated' => 0, 'skipped' => 0, 'total' => 0, 'error' => 'soups 目录不存在'];
        }

        require_once __DIR__ . '/lib/md.php';

        // 1. 更新已存在的汤（源文件仍在的）
        $rows = $pdo->query('SELECT id, filename FROM ' . $table)->fetchAll();
        $stmt = $pdo->prepare("UPDATE {$table} SET title=?, season=?, episode=?, surface=?, base=?, host_manual=?, extra=? WHERE id=?");
        $updated = 0; $skipped = 0; $deleted = 0;
        $pdo->beginTransaction();
        $existingFiles = [];
        foreach ($rows as $row) {
            $file = $dir . '/' . $row['filename'];
            if (!is_file($file)) { $existingFiles[] = (int)$row['id']; continue; }
            $content = file_get_contents($file);
            $p = parse_md($row['filename'], $content);
            $stmt->execute([$p['title'], $p['season'], $p['episode'], $p['surface'], $p['base'], $p['host_manual'], $p['extra'], $row['id']]);
            $updated++;
        }
        if ($existingFiles) {
            $delStmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE id = ?');
            foreach ($existingFiles as $id) { $delStmt->execute([$id]); $deleted++; }
        }
        $pdo->commit();

        // 2. 导入新增的汤
        $dbFiles = array_column($rows, 'filename');
        $dirFiles = array_filter(scandir($dir), function ($f) { return str_ends_with($f, '.md'); });
        $newFiles = array_diff($dirFiles, $dbFiles);
        $imported = 0;
        if ($newFiles) {
            sort($newFiles, SORT_NATURAL | SORT_FLAG_CASE);
            $insStmt = $pdo->prepare("INSERT INTO {$table} (filename, season, episode, title, surface, base, host_manual, extra, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $pdo->beginTransaction();
            foreach ($newFiles as $f) {
                $content = file_get_contents($dir . '/' . $f);
                $p = parse_md($f, $content);
                $insStmt->execute([$p['filename'], $p['season'], $p['episode'], $p['title'], $p['surface'], $p['base'], $p['host_manual'], $p['extra']]);
                $imported++;
            }
            $pdo->commit();
        }

        return [
            'updated'  => $updated,
            'skipped'  => $skipped,
            'deleted'  => $deleted,
            'imported' => $imported,
            'total'    => count($rows),
        ];
    }
}

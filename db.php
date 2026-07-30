<?php
/**
 * 海龟汤馆 · MySQL-Only 数据库层
 * 专为共享主机设计：无 SQLite、无 Composer、PHP 8.3+
 */
class DB {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (self::$pdo !== null) return self::$pdo;
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', Config::$DB_HOST, Config::$DB_NAME, Config::$DB_CHARSET);
        self::$pdo = new PDO($dsn, Config::$DB_USER, Config::$DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    public static function table(string $name): string {
        return Config::$DB_PREFIX . $name;
    }

    public static function init(): void {
        $pdo = self::pdo();
        $charset = Config::$DB_CHARSET;

        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::table('users') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(32) NOT NULL UNIQUE,
            email VARCHAR(254) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            is_admin TINYINT NOT NULL DEFAULT 0,
            is_banned TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::table('soups') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            season VARCHAR(50) DEFAULT '',
            episode VARCHAR(50) DEFAULT '',
            title VARCHAR(200) NOT NULL,
            surface TEXT NOT NULL,
            base TEXT NOT NULL,
            host_manual TEXT,
            extra TEXT,
            author_id INT DEFAULT NULL,
            status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
            reject_reason VARCHAR(255) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            view_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY author_id (author_id),
            KEY status (status),
            KEY season (season)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::table('rooms') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(10) NOT NULL UNIQUE,
            host_id INT NOT NULL,
            soup_id INT NOT NULL,
            ai_enabled TINYINT NOT NULL DEFAULT 1,
            ai_question_limit INT NOT NULL DEFAULT 0,
            ai_question_count INT NOT NULL DEFAULT 0,
            member_limit INT NOT NULL DEFAULT 4,
            status ENUM('playing','ended') NOT NULL DEFAULT 'playing',
            game_started TINYINT NOT NULL DEFAULT 0,
            initial_sanity INT NOT NULL DEFAULT 100,
            current_resonance TEXT,
            tasks TEXT,
            task_state TEXT,
            state TEXT,
            ai_key VARCHAR(255) DEFAULT NULL,
            ai_provider VARCHAR(50) DEFAULT 'deepseek',
            ai_base_url VARCHAR(255) DEFAULT NULL,
            ai_model VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY host_id (host_id),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::table('room_members') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            user_id INT NOT NULL,
            role ENUM('host','player') NOT NULL DEFAULT 'player',
            character_name VARCHAR(50) DEFAULT NULL,
            sanity INT NOT NULL DEFAULT 100,
            sanity_consumed INT NOT NULL DEFAULT 0,
            fragments TEXT,
            last_skill_at DATETIME DEFAULT NULL,
            last_skill_type VARCHAR(50) DEFAULT NULL,
            muted_until DATETIME DEFAULT NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_member (room_id, user_id),
            KEY room_id (room_id)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::table('messages') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            username VARCHAR(32) DEFAULT NULL,
            msg_type ENUM('system','chat','ai','host_answer','host_question','skill','fragment') NOT NULL DEFAULT 'chat',
            content TEXT NOT NULL,
            meta TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY room_id (room_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::table('comments') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            soup_id INT NOT NULL,
            user_id INT NOT NULL,
            username VARCHAR(32) NOT NULL,
            content VARCHAR(1000) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            KEY soup_id (soup_id, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::table('follows') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            follow_user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_follow (user_id, follow_user_id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::table('rate_limits') . " (
            key_name VARCHAR(128) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL,
            expires_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::table('settings') . " (
            key_name VARCHAR(64) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");
    }

    public static function import_soups(): int {
        $dir = Config::$SOUPS_DIR;
        if (!is_dir($dir)) return 0;
        $pdo = self::pdo();
        $stmt = $pdo->prepare("SELECT filename FROM " . self::table('soups') . " WHERE filename = ?");
        $ins = $pdo->prepare("INSERT INTO " . self::table('soups') . "
            (filename, season, episode, title, surface, base, host_manual, extra, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $n = 0;
        foreach (glob($dir . '/*.md') as $file) {
            $fn = basename($file);
            $stmt->execute([$fn]);
            if ($stmt->fetch()) continue;
            $s = self::parse_soup_file($file);
            $sort = preg_match('/E(\d+)/i', $fn, $em) ? (int)$em[1] : 0;
            $ins->execute([
                $fn, $s['season'], $s['episode'], $s['title'], $s['surface'],
                $s['base'], $s['host_manual'], $s['extra'], $sort
            ]);
            $n++;
        }
        return $n;
    }

    public static function parse_soup_file(string $path): array {
        $raw = file_get_contents($path) ?: '';
        $s = ['season' => '', 'episode' => '', 'title' => '', 'surface' => '', 'base' => '', 'host_manual' => '', 'extra' => ''];

        if (preg_match('/^(.+?)《(.+?)》/u', $raw, $m)) {
            $s['season'] = trim($m[1]);
            $s['title'] = trim($m[2]);
        } elseif (preg_match('/^#\s*(.+)/m', $raw, $m)) {
            $s['title'] = trim($m[1]);
        }

        $s['episode'] = preg_match('/E(\d+)/i', basename($path), $m) ? 'E' . $m[1] : '';

        $parts = preg_split('/\n汤面\n|\n汤底\n/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) >= 3) {
            $header = $parts[0];
            $s['surface'] = trim($parts[1]);
            $rest = $parts[2];
            if (str_contains($rest, "\n主持人手册\n")) {
                [$base, $tail] = explode("\n主持人手册\n", $rest, 2);
                $s['base'] = trim($base);
                if (str_contains($tail, "\n其他内容\n")) {
                    [$s['host_manual'], $s['extra']] = explode("\n其他内容\n", $tail, 2);
                    $s['host_manual'] = trim($s['host_manual']);
                    $s['extra'] = trim($s['extra']);
                } else {
                    $s['host_manual'] = trim($tail);
                }
            } else {
                $s['base'] = trim($rest);
            }
            if (!$s['title'] && preg_match('/^#\s*(.+)/m', $header, $m)) {
                $s['title'] = trim($m[1]);
            }
        } else {
            $s['surface'] = $raw;
        }
        return array_map('trim', $s);
    }
}

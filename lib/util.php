<?php
/** 通用工具函数 + 游戏常量 */

class AIError extends Exception {
    public $code;
    public function __construct(string $message, string $code = 'ai_error') {
        parent::__construct($message);
        $this->code = $code;
    }
}

/** 可用角色 */
const CHARACTERS = [
    '减排除' => ['dept' => '灵探', 'skill' => '排除'],
    '许复元' => ['dept' => '灵探', 'skill' => '破局'],
    '辛笙'   => ['dept' => '灵探', 'skill' => '心声'],
    '意马'   => ['dept' => '灵契', 'skill' => '以意化灵'],
    '柳双鱼' => ['dept' => '灵契', 'skill' => '拷贝'],
    '柳千渊' => ['dept' => '灵者', 'skill' => '现'],
    '孙沐阳' => ['dept' => '灵者', 'skill' => '以心为眼'],
];

/** 碎片类型 */
const FRAGMENT_TYPES = ['暗喻', '线索', '指引', '剧情', '隐藏'];

/** 基础理智 */
const BASE_SANITY = 100;

/** 游戏人数 */
const ROOM_SIZE = 4;

function json_ok(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $error, int $status = 400, ?array $extra = null): void {
    http_response_code($status);
    $out = ['error' => $error];
    if ($extra !== null) $out += $extra;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function hash_password(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function gen_code(int $len = 6): string {
    return str_pad((string)random_int(0, 10 ** $len - 1), $len, '0', STR_PAD_LEFT);
}

function gen_room_code(int $len = 6): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function sanitize_filename(string $name): string {
    return preg_replace('/[^\p{L}\p{N}_\-\x{3000}\x{FF00}-\x{FFEF}]+/u', '_', $name);
}

function validate_length(string $value, int $max, string $label): void {
    if (mb_strlen($value) > $max) json_error("{$label} 超过 {$max} 字");
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(array $exempt = []): void {
    if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'], true)) return;

    $path = $_GET['r'] ?? ($_SERVER['REQUEST_URI'] ?? '');
    $path = preg_replace('/^\/index\.php\?r=/', '', $path);
    foreach ($exempt as $e) {
        if (str_starts_with($path, '/api/' . $e) || str_starts_with($path, '/' . $e) || str_starts_with($path, $e)) return;
    }

    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $body = body_json()['csrf_token'] ?? '';
    $token = is_string($header) && $header !== '' ? $header : $body;
    if (!is_string($token) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        json_error('CSRF 校验失败', 403);
    }
}

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM " . DB::table('users') . " WHERE id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) json_error('请先登录', 401);
    if ((int)$u['is_banned'] === 1) json_error('账号已被封禁', 403);
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ((int)$u['is_admin'] !== 1 && !admin_token_user()) json_error('无权访问', 403);
    return $u;
}

function admin_token_user(): ?array {
    if (Config::$ADMIN_API_TOKEN === '') return null;
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals(Config::$ADMIN_API_TOKEN, $token)) return null;
    $pdo = DB::pdo();
    $stmt = $pdo->query("SELECT * FROM " . DB::table('users') . " WHERE is_admin = 1 LIMIT 1");
    return $stmt->fetch() ?: null;
}

function sign_code(string $email, string $code): string {
    return hash_hmac('sha256', $email . '|' . $code, Config::$SECRET_KEY);
}

function verify_signed_code(string $email, string $token, string $code): bool {
    return hash_equals(sign_code($email, $code), $token);
}

function rate_limit(string $key, int $max, int $window): bool {
    $pdo = DB::pdo();
    $now = time();
    $fullKey = 'rl:' . $key;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT value FROM " . DB::table('rate_limits') . " WHERE key_name = ? AND expires_at > NOW()");
        $stmt->execute([$fullKey]);
        $row = $stmt->fetch();
        $data = $row ? (json_decode($row['value'], true) ?: ['count' => 0, 'start' => $now]) : ['count' => 0, 'start' => $now];

        if ($now - $data['start'] > $window) {
            $data = ['count' => 1, 'start' => $now];
        } else {
            $data['count']++;
        }

        $expires = date('Y-m-d H:i:s', $now + $window);
        $upsert = $pdo->prepare("INSERT INTO " . DB::table('rate_limits') . " (key_name, value, expires_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)");
        $upsert->execute([$fullKey, json_encode($data), $expires]);

        $pdo->commit();
        return $data['count'] <= $max;
    } catch (Throwable $e) {
        $pdo->rollBack();
        return true;
    }
}

function cleanup_rate_limits(): void {
    try {
        DB::pdo()->exec("DELETE FROM " . DB::table('rate_limits') . " WHERE expires_at <= NOW()");
    } catch (Throwable $e) {
        // ignore
    }
}

function escape_html(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 解析 soup extra 中的理智设定，如 "初始理智:80" */
function parse_initial_sanity(string $extra): int {
    if (preg_match('/初始理智[:：]\s*(\d+)/u', $extra, $m)) {
        return max(1, min(999, (int)$m[1]));
    }
    return BASE_SANITY;
}

/** 解析主持人手册/其他内容中的任务列表 */
function parse_tasks(string $text): array {
    $tasks = [];
    if (preg_match_all('/任务[:：]?\s*(.+?)(?=\n|$)/u', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $i => $item) {
            $tasks[] = ['id' => $i + 1, 'text' => trim($item[1]), 'done' => false];
        }
    }
    return $tasks;
}

/** 保存一条房间消息 */
function save_room_message(int $room_id, ?int $user_id, ?string $username, string $type, string $content, ?array $meta = null): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("INSERT INTO " . DB::table('messages') . "
        (room_id, user_id, username, msg_type, content, meta) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$room_id, $user_id, $username, $type, $content, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
    $id = (int)$pdo->lastInsertId();
    return [
        'id' => $id,
        'room_id' => $room_id,
        'user_id' => $user_id,
        'username' => $username,
        'msg_type' => $type,
        'content' => $content,
        'meta' => $meta,
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

function message_to_dict(array $m): array {
    $m['id'] = (int)$m['id'];
    $m['room_id'] = (int)$m['room_id'];
    $m['user_id'] = $m['user_id'] ? (int)$m['user_id'] : null;
    $m['meta'] = $m['meta'] ? (json_decode($m['meta'], true) ?: null) : null;
    return $m;
}

/** 读取房间完整信息（含成员） */
function rooms_require_room(string $code): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT r.*, u.username AS host_name, s.title AS soup_title, s.surface, s.season, s.base, s.host_manual, s.extra
        FROM " . DB::table('rooms') . " r
        LEFT JOIN " . DB::table('users') . " u ON r.host_id = u.id
        LEFT JOIN " . DB::table('soups') . " s ON r.soup_id = s.id
        WHERE r.code = ? AND r.status = 'playing'");
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('房间不存在或已结束', 404);
    return $r;
}

function rooms_is_member(int $room_id, int $user_id): bool {
    $stmt = DB::pdo()->prepare("SELECT 1 FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    return (bool)$stmt->fetch();
}

function rooms_load_members(int $room_id): array {
    $stmt = DB::pdo()->prepare("SELECT m.user_id, m.role, m.character_name, m.sanity, m.sanity_consumed, m.fragments, m.muted_until, m.joined_at, u.username
        FROM " . DB::table('room_members') . " m
        JOIN " . DB::table('users') . " u ON m.user_id = u.id
        WHERE m.room_id = ? ORDER BY m.joined_at");
    $stmt->execute([$room_id]);
    return array_map(fn($m) => member_to_dict($m), $stmt->fetchAll());
}

function member_to_dict(array $m): array {
    return [
        'user_id' => (int)$m['user_id'],
        'username' => $m['username'],
        'role' => $m['role'],
        'character' => $m['character_name'] ?? null,
        'dept' => $m['character_name'] ? (CHARACTERS[$m['character_name']]['dept'] ?? null) : null,
        'sanity' => (int)$m['sanity'],
        'sanity_consumed' => (int)$m['sanity_consumed'],
        'fragments' => $m['fragments'] ? (json_decode($m['fragments'], true) ?: []) : [],
        'muted_until' => $m['muted_until'],
        'joined_at' => $m['joined_at'],
    ];
}

function room_to_dict(array $r): array {
    return [
        'id' => (int)$r['id'],
        'code' => $r['code'],
        'host_id' => (int)$r['host_id'],
        'host_name' => $r['host_name'] ?? null,
        'soup_id' => $r['soup_id'] ? (int)$r['soup_id'] : null,
        'soup_title' => $r['soup_title'] ?? null,
        'status' => $r['status'],
        'ai_enabled' => (int)$r['ai_enabled'] === 1,
        'ai_question_limit' => (int)$r['ai_question_limit'],
        'ai_question_count' => (int)$r['ai_question_count'],
        'member_limit' => (int)$r['member_limit'],
        'game_started' => (int)$r['game_started'] === 1,
        'initial_sanity' => (int)$r['initial_sanity'],
        'current_resonance' => $r['current_resonance'] ?? '',
        'tasks' => $r['tasks'] ? (json_decode($r['tasks'], true) ?: []) : [],
        'task_state' => $r['task_state'] ? (json_decode($r['task_state'], true) ?: []) : [],
        'created_at' => $r['created_at'],
    ];
}

/** 校验角色是否可用，并返回羁绊冲突信息 */
function validate_character(array $members, string $character): ?string {
    if (!isset(CHARACTERS[$character])) return '未知角色';
    foreach ($members as $m) {
        if (($m['character_name'] ?? '') === $character) return '该角色已被选择';
    }
    $picked = array_column($members, 'character_name');
    if ($character === '意马' && in_array('孙沐阳', $picked, true)) return '意马拒绝与孙沐阳同时登场';
    if ($character === '孙沐阳' && in_array('意马', $picked, true)) return '孙沐阳无法与意马同时登场';
    return null;
}

/** 对玩家应用理智变动，返回实际变动值 */
function apply_sanity(int $room_id, int $user_id, int $delta): int {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT sanity, sanity_consumed FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    $m = $stmt->fetch();
    if (!$m) return 0;

    $before = (int)$m['sanity'];
    $consumed = (int)$m['sanity_consumed'];
    $after = max(0, min(999, $before + $delta));
    $actual = $after - $before;

    $pdo->prepare("UPDATE " . DB::table('room_members') . "
        SET sanity = ?, sanity_consumed = sanity_consumed + ? WHERE room_id = ? AND user_id = ?")
        ->execute([$after, abs($actual), $room_id, $user_id]);

    // 孙沐阳被动：每累计减少 15 理智获得 1 碎片
    if ($actual < 0) {
        $newConsumed = $consumed + abs($actual);
        $oldThresholds = (int)($consumed / 15);
        $newThresholds = (int)($newConsumed / 15);
        for ($i = $oldThresholds + 1; $i <= $newThresholds; $i++) {
            gain_fragment($room_id, $user_id, '孙沐阳', '隐藏');
        }
    }

    return $actual;
}

/** 给玩家增加碎片 */
function gain_fragment(int $room_id, int $user_id, string $source, string $type = '线索', string $note = ''): void {
    $type = in_array($type, FRAGMENT_TYPES, true) ? $type : '线索';
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT fragments, character_name FROM " . DB::table('room_members') . " WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    $m = $stmt->fetch();
    if (!$m) return;

    $fragments = $m['fragments'] ? (json_decode($m['fragments'], true) ?: []) : [];
    $fragments[] = ['type' => $type, 'source' => $source, 'note' => $note, 'at' => date('Y-m-d H:i:s')];

    // 柳双鱼拷贝：额外获得 1 块
    if ($m['character_name'] === '柳双鱼') {
        $fragments[] = ['type' => $type, 'source' => '柳双鱼·拷贝', 'note' => $note, 'at' => date('Y-m-d H:i:s')];
    }

    $pdo->prepare("UPDATE " . DB::table('room_members') . " SET fragments = ? WHERE room_id = ? AND user_id = ?")
        ->execute([json_encode($fragments, JSON_UNESCAPED_UNICODE), $room_id, $user_id]);
}

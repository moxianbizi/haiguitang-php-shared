<?php
/**
 * 灵之残响专属房间 API
 *
 * 与普通 rooms.php 的区别：
 *   1. 强制 season='灵之残响' 的汤
 *   2. rooms.state 存状态机（碎片/触发/任务/理智/提问计数）
 *   3. room_members 表存角色分配，AI 按角色视角回答
 *   4. AI 调用走 ask_ai_lzcx()，支持多轮上下文 + 状态注入
 *   5. 房主有手动控制接口：触发规则/完成任务/调理智（碎片只能由灵者角色能力获得）
 *
 * 路由前缀：/api/lzcxroom/*
 */

// 复用 rooms.php 的辅助函数（save_message / message_to_dict / count_room_members 等）
require_once __DIR__ . '/rooms.php';

/**
 * 灵之残响固定角色池（灵渊司成员）
 * 注意：此处的「角色」是玩家分配的灵渊司身份，不是汤中的「幻灵角色视角」。
 * 与 lib/ai.php 共用同一常量，避免重复定义 fatal error。
 */
if (!defined('LZCX_CHARACTERS')) {
    define('LZCX_CHARACTERS', [
        ['name' => '减',      'dept' => '纠察处·灵探', 'ability' => '排除：消耗2理智，提出结论并进行排除'],
        ['name' => '许复元',  'dept' => '纠察处·灵探', 'ability' => '破局：提出推理，主持人回答是/不是；若回答「不是」消耗4理智'],
        ['name' => '辛笙',    'dept' => '纠察处·灵探', 'ability' => '心声：消耗2理智，提出两个结论，主持人告知其中绝对正确的数量'],
        ['name' => '意马',    'dept' => '镇压所·灵契', 'ability' => '以意化灵：登场时初始理智+20%；羁绊：不与孙沐阳同场'],
        ['name' => '柳双鱼',  'dept' => '镇压所·灵契', 'ability' => '滞时：登场时初始理智-10%；拷贝：获得碎片时额外+1'],
        ['name' => '柳千渊',  'dept' => '重现署·灵者', 'ability' => '现！：消耗4理智获得一块碎片，不可连续发动'],
        ['name' => '孙沐阳',  'dept' => '重现署·灵者', 'ability' => '以心为眼：每减少15理智获得一块碎片；羁绊：不与意马同场'],
    ]);
}

function handle_lzcxroom(array $segments) {
    $action = $segments[1] ?? '';
    if ($action === '') {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') lzcx_list();
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST') lzcx_create();
        else json_error('Method Not Allowed', 405);
        return;
    }

    $code = strtoupper($action);
    $sub = $segments[2] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    // 房间级
    if ($method === 'GET' && $sub === '') { require_login(); lzcx_get($code); }
    elseif ($method === 'DELETE' && $sub === '') lzcx_dissolve($code);
    // 成员
    elseif ($sub === 'join' && $method === 'POST') lzcx_join($code);
    elseif ($sub === 'assign-character' && $method === 'POST') lzcx_assign_character($code);
    elseif ($sub === 'members' && $method === 'GET') { require_login(); lzcx_members($code); }
    // 消息
    elseif ($sub === 'messages' && $method === 'POST') lzcx_send_message($code);
    elseif ($sub === 'messages' && $method === 'GET') { require_login(); lzcx_poll_messages($code); }
    // AI 提问
    elseif ($sub === 'ask' && $method === 'POST') lzcx_ask($code);
    // 玩家技能（柳千渊/现！、灵者/幻灵等）
    elseif ($sub === 'skill' && $method === 'POST') lzcx_skill($code);
    // 房主绑定/更新 AI Key（房间全员共用）
    elseif ($sub === 'ai-key' && $method === 'POST') lzcx_set_ai_key($code);
    // 房主状态机控制（按钮面板，保留兼容）
    elseif ($sub === 'trigger' && $method === 'POST') lzcx_trigger($code);
    elseif ($sub === 'complete-task' && $method === 'POST') lzcx_complete_task($code);
    elseif ($sub === 'sanity' && $method === 'PUT') lzcx_set_sanity($code);
    elseif ($sub === 'reset-state' && $method === 'POST') lzcx_reset_state($code);
    // 房主主持人指令（纯对话模式，推荐）
    elseif ($sub === 'host-command' && $method === 'POST') lzcx_host_command($code);
    // 房主开始游戏
    elseif ($sub === 'start' && $method === 'POST') lzcx_start_game($code);
    else json_error('Not Found', 404);
}

// ===================== 汤源解析（从汤面/手册提取状态机参数） =====================

/**
 * 从汤的字段中解析灵之残响专属参数：
 *   - total_fragments: 总碎片数（"碎片数量：4"）
 *   - initial_sanity: 初始理智（"初始理智：60"）
 *   - characters: 角色列表（从"幻灵角色视角"段提取"1. 老板娘"等）
 *   - tasks: 任务列表（"任务1：..."）
 *   - rules: 隐藏规则触发条件（"规则六：...（触发条件：XXX）"）
 */
function lzcx_parse_meta(string $surface, string $hostManual, string $extra): array {
    $meta = [
        'total_fragments'  => 0,
        'initial_sanity'   => 0,
        'characters'       => [],
        'tasks'            => [],
        'hidden_rules'     => [], // ['name'=>'规则六', 'condition'=>'推理出主角为人鱼']
        'key_nodes'        => [], // 作者预定义的关键节点名列表（空=让 AI 自行拆分）
    ];

    $blob = $surface . "\n" . $hostManual . "\n" . $extra;

    // 碎片数量
    if (preg_match('/碎片数量[：:]\s*(\d+)/u', $blob, $m)) {
        $meta['total_fragments'] = (int)$m[1];
    }
    // 初始理智
    if (preg_match('/初始理智[：:]\s*(\d+)/u', $blob, $m)) {
        $meta['initial_sanity'] = (int)$m[1];
    }

    // 关键节点：作者可在 extra/host_manual 中用「【关键节点】」段预定义
    // 格式：
    //   【关键节点】
    //   1. 主角是人鱼
    //   2. 死因是溺水
    //   ...
    // 也兼容「关键节点：」「关键节点:」作标题。每行一个节点。
    if (preg_match('/【?关键节点】?\s*[：:]\s*\n([\s\S]*?)(?=\n【|\n关键节点|\n规则|\n任务|\n幻灵|\n残响|\n收容|\Z)/u', $blob, $km)) {
        $block = $km[1];
        $lines = explode("\n", $block);
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;
            // 去掉行首序号 "1." "1、" "1)" "* " "- "
            $t = preg_replace('/^[*\-]?\s*\d+[.、)]\s*/u', '', $t);
            $t = trim($t, " *　");
            if ($t !== '' && mb_strlen($t) <= 60) {
                $meta['key_nodes'][] = $t;
            }
        }
        $meta['key_nodes'] = array_values(array_unique($meta['key_nodes']));
    }

    // 角色：灵之残响采用固定灵渊司角色池，不按汤中「幻灵角色视角」分配。
    // 汤中幻灵角色（老板娘、客人等）由灵者使用「幻灵」能力后临时扮演。
    $meta['characters'] = array_column(LZCX_CHARACTERS, 'name');
    $meta['characters_info'] = LZCX_CHARACTERS;

    // 碎片图片：按顺序提取 extra 中所有 markdown 图片 URL（已在前端/md.php 转换为 /soups-img/）
    if (preg_match_all('/!\[.*?\]\(([^)]+)\)/u', $extra, $im)) {
        $meta['fragment_images'] = array_values(array_unique($im[1]));
    }

    // 碎片类型：按顺序提取 extra 中"暗喻碎片/线索碎片/指引碎片/剧情碎片/隐藏碎片"
    if (preg_match_all('/(暗喻|线索|指引|剧情|隐藏)碎片/u', $extra, $tm)) {
        $meta['fragment_types'] = $tm[1];
    }

    // 任务：「任务1：XXX」「任务 1：XXX」「最终任务：XXX」
    if (preg_match_all('/(?:最终任务|任务\s*(\d))[：:]\s*([^\n]+)/u', $blob, $tm, PREG_SET_ORDER)) {
        foreach ($tm as $t) {
            $num = $t[1] !== '' ? (int)$t[1] : 999; // 最终任务用 999
            $meta['tasks'][] = ['num' => $num, 'desc' => trim($t[2])];
        }
    }

    // 隐藏规则触发条件：「规则六：...（触发条件：XXX）」
    if (preg_match_all('/规则([一二三四五六七八九十]+|\d+)[：:][^\n]*?（触发条件(?:\d+)?[：:]([^）]+)）/u', $blob, $rm, PREG_SET_ORDER)) {
        foreach ($rm as $r) {
            $meta['hidden_rules'][] = [
                'name'      => '规则' . $r[1],
                'condition' => trim($r[2]),
            ];
        }
    }

    return $meta;
}

/**
 * 初始化灵之残响房间的状态机
 * key_nodes:
 *   - 作者预定义时：[['name'=>str,'hit'=>false], ...]
 *   - 作者未定义时：[]（空数组，启用机制但让 AI 首次回答自行拆分）
 *   - 注意：[] 与"未启用"不同。未启用由调用方决定（lzcx 默认启用）。
 */
function lzcx_init_state(array $meta): array {
    $keyNodes = [];
    foreach (($meta['key_nodes'] ?? []) as $name) {
        $keyNodes[] = ['name' => $name, 'hit' => false];
    }
    return [
        'released_fragments' => 0,
        'total_fragments'    => $meta['total_fragments'] ?? 0,
        'base_initial_sanity'=> $meta['initial_sanity'] ?? 0,
        'initial_sanity'     => $meta['initial_sanity'] ?? 0,
        'sanity'             => $meta['initial_sanity'] ?? 0,
        'triggered_rules'    => [],
        'completed_tasks'    => [],
        'ask_count'          => 0,
        'characters_meta'    => $meta['characters'] ?? [],
        'characters_info'    => $meta['characters_info'] ?? [],
        'tasks_meta'         => $meta['tasks'] ?? [],
        'hidden_rules_meta'  => $meta['hidden_rules'] ?? [],
        'fragment_images'    => $meta['fragment_images'] ?? [],
        'fragment_types'     => $meta['fragment_types'] ?? [],
        // 关键节点：空数组=已启用机制但待 AI 自拆；非空=作者预定义
        'key_nodes'          => $keyNodes,
        'cleared'            => false, // 是否已通关（命中≥85%）
        // 幻灵状态
        'possessed_user_id'  => null,
        'possessed_character'=> null,
        // 孙沐阳被动累计
        'sun_muyang_last_lost' => 0,
        'sun_fragment_type'  => null, // 孙沐阳指定的下次被动碎片类型
        // 柳千渊现！上次发动者（不可连续发动）
        'last_xian_user_id'  => null,
    ];
}

/** 安全读取房间 state */
function lzcx_load_state(array $room): array {
    $s = json_decode($room['state'] ?? '{}', true);
    if (!is_array($s)) $s = [];
    // 兜底字段
    $s += [
        'released_fragments' => 0,
        'total_fragments'    => 0,
        'base_initial_sanity'=> 0,
        'initial_sanity'     => 0,
        'sanity'             => 0,
        'triggered_rules'    => [],
        'completed_tasks'    => [],
        'ask_count'          => 0,
        'characters_meta'    => [],
        'characters_info'    => [],
        'fragment_images'    => [],
        'fragment_types'     => [],
        'key_nodes'          => [],
        'cleared'            => false,
        'game_started'       => false,
        'possessed_user_id'  => null,
        'possessed_character'=> null,
        'sun_muyang_last_lost' => 0,
        'sun_fragment_type'  => null,
        'last_xian_user_id'  => null,
    ];
    // 角色池是全局固定的，每次加载都刷新，避免旧房间保留过期角色
    $s['characters_meta'] = array_column(LZCX_CHARACTERS, 'name');
    $s['characters_info'] = LZCX_CHARACTERS;
    // 旧房间可能缺少碎片图片列表，按需从汤源补一次
    if (empty($s['fragment_images']) && !empty($room['soup_id'])) {
        $stmt = DB::pdo()->prepare("SELECT extra FROM soups WHERE id = ? AND status = 'approved'");
        $stmt->execute([(int)$room['soup_id']]);
        $extra = $stmt->fetchColumn();
        if ($extra && preg_match_all('/!\[.*?\]\(([^)]+)\)/u', (string)$extra, $im)) {
            $s['fragment_images'] = array_values(array_unique($im[1]));
        }
    }
    return $s;
}

/** 写回 state */
function lzcx_save_state(int $roomId, array $state): void {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('UPDATE rooms SET state = ? WHERE id = ?');
    $stmt->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $roomId]);
}

/** 取房间（必须是 lzcx 类型） */
function lzcx_require_room(string $code): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE code = ? AND room_type = 'lzcx'");
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('灵之残响房间不存在', 404);
    return $r;
}

/** 验证汤是灵之残响系列 */
function lzcx_require_soup(int $soupId): array {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id, filename, season, episode, title, surface, base, host_manual, extra FROM soups WHERE id = ? AND status = 'approved'");
    $stmt->execute([$soupId]);
    $s = $stmt->fetch();
    if (!$s) json_error('海龟汤不存在或未通过审核', 400);
    if ($s['season'] !== '灵之残响') {
        json_error('灵之残响专属房间只能选择「灵之残响」系列的汤');
    }
    return $s;
}

// ===================== 房间 CRUD =====================

function lzcx_create() {
    $user = require_login();
    if (!rate_limit("lzcx_room_create_user_{$user['id']}", Config::$RATE_LIMIT_ROOM_CREATE, 60)) {
        json_error('创建房间过于频繁，请稍后再试', 429);
    }
    $data = body_json();
    $soup_id = (int)($data['soup_id'] ?? 0);
    if ($soup_id <= 0) json_error('灵之残响房间必须选汤');
    $soup = lzcx_require_soup($soup_id);

    $ai_enabled = $data['ai_enabled'] ?? true;
    $ai_question_limit = max(0, (int)($data['ai_question_limit'] ?? 0));
    // 灵之残响固定 4 人：1 主持人 + 3 玩家
    $member_limit = 4;

    // 解析汤的灵之残响参数，初始化状态机
    $meta = lzcx_parse_meta($soup['surface'] ?? '', $soup['host_manual'] ?? '', $soup['extra'] ?? '');
    $state = lzcx_init_state($meta);
    $state['game_started'] = false;

    $pdo = DB::pdo();
    $code = gen_room_code();
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) break;
        $code = gen_room_code();
    }

    $stmt = $pdo->prepare("INSERT INTO rooms (code, host_id, soup_id, ai_enabled, ai_question_limit, member_limit, status, room_type, state) VALUES (?, ?, ?, ?, ?, ?, 'playing', 'lzcx', ?)");
    $stmt->execute([$code, $user['id'], $soup_id, $ai_enabled ? 1 : 0, $ai_question_limit, $member_limit, json_encode($state, JSON_UNESCAPED_UNICODE)]);
    $id = (int)$pdo->lastInsertId();

    // 房主自动加入成员表，角色=host
    $stmt = $pdo->prepare('INSERT INTO room_members (room_id, user_id, role) VALUES (?, ?, ?)');
    $stmt->execute([$id, $user['id'], 'host']);

    // 系统消息
    $stmt = $pdo->prepare('INSERT INTO messages (room_id, msg_type, content) VALUES (?, ?, ?)');
    $stmt->execute([$id, 'system', '灵之残响房间已创建，满 4 人后房主可开始游戏。']);

    lzcx_get($code, 201);
}

function lzcx_list() {
    require_login();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT r.id, r.code, r.host_id, r.soup_id, r.status, r.ai_enabled, r.ai_question_limit, r.ai_question_count, r.member_limit, r.created_at, u.username AS host_name, s.title AS soup_title FROM rooms r LEFT JOIN users u ON r.host_id = u.id LEFT JOIN soups s ON r.soup_id = s.id WHERE r.room_type = 'lzcx' AND r.status = 'playing' ORDER BY r.created_at DESC LIMIT 50");
    $stmt->execute();
    $rooms = $stmt->fetchAll();
    json_ok(['rooms' => array_map('lzcx_room_to_dict', $rooms)]);
}

function lzcx_get(string $code, int $status = 200) {
    $pdo = DB::pdo();
    $user = current_user();
    $stmt = $pdo->prepare("SELECT r.*, u.username AS host_name, s.title AS soup_title, s.surface, s.season, s.base, s.host_manual, s.extra FROM rooms r LEFT JOIN users u ON r.host_id = u.id LEFT JOIN soups s ON r.soup_id = s.id WHERE r.code = ? AND r.room_type = 'lzcx'");
    $stmt->execute([$code]);
    $r = $stmt->fetch();
    if (!$r) json_error('灵之残响房间不存在', 404);

    $room = lzcx_room_to_dict($r);
    $room['state'] = lzcx_load_state($r);
    // 房主 key 绑定状态（仅返回布尔，不暴露 key 本身）
    $room['has_host_key'] = !empty($r['ai_key_encrypted']);

    // 成员列表（含角色）
    $stmt = $pdo->prepare('SELECT rm.user_id, rm.role, rm.character_name, rm.joined_at, u.username FROM room_members rm JOIN users u ON rm.user_id = u.id WHERE rm.room_id = ? ORDER BY rm.joined_at');
    $stmt->execute([$r['id']]);
    $room['members'] = $stmt->fetchAll();

    // 汤：玩家只看汤面；房主额外看汤底/手册/补充内容
    $isHost = $user && (int)$r['host_id'] === (int)$user['id'];
    $soup = null;
    if ($r['soup_id']) {
        $soup = [
            'id' => (int)$r['soup_id'],
            'title' => $r['soup_title'],
            'season' => $r['season'],
            'surface' => $r['surface'],
        ];
        if ($isHost) {
            $soup['base'] = $r['base'] ?? '';
            $soup['host_manual'] = $r['host_manual'] ?? '';
            $soup['extra'] = $r['extra'] ?? '';
        }
    }

    // 最近消息
    $limit = Config::$ROOM_MSG_LIMIT;
    if ($limit > 0) {
        $sql = 'SELECT id, user_id, username, msg_type, content, created_at FROM messages WHERE room_id = ? ORDER BY id DESC LIMIT ?';
        $params = [$r['id'], $limit];
    } else {
        $sql = 'SELECT id, user_id, username, msg_type, content, created_at FROM messages WHERE room_id = ? ORDER BY id ASC';
        $params = [$r['id']];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $msgs = $stmt->fetchAll();
    if ($limit > 0) $msgs = array_reverse($msgs);

    json_ok([
        'room' => $room,
        'soup' => $soup,
        'messages' => array_map('message_to_dict', $msgs),
    ], $status);
}

function lzcx_dissolve(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以解散房间', 403);

    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // room_members / messages 走 ON DELETE CASCADE，删 rooms 即可
        $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
        $stmt->execute([(int)$r['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('解散房间失败：' . $e->getMessage(), 500);
    }
    json_ok(['msg' => '房间已解散']);
}

// ===================== 成员管理 =====================

function lzcx_join(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ($r['status'] !== 'playing') json_error('房间已结束');

    $pdo = DB::pdo();
    // 已在则不重复加（游戏开始后也允许已在房间的成员重新进入/刷新）
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$r['id'], $user['id']]);
    if ($stmt->fetch()) json_ok(['msg' => '已加入']);

    // 游戏已开始则禁止新玩家加入
    $state = lzcx_load_state($r);
    if (!empty($state['game_started'])) json_error('游戏已开始，无法加入', 403);

    // 人数上限：灵之残响固定 4 人（1 房主 + 3 玩家）
    if ((int)$r['member_limit'] > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM room_members WHERE room_id = ? AND role != 'host'");
        $stmt->execute([$r['id']]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt >= (int)$r['member_limit'] - 1) json_error('房间人数已达上限（灵之残响固定 4 人）', 403);
    }

    $stmt = $pdo->prepare("INSERT INTO room_members (room_id, user_id, role) VALUES (?, ?, 'player')");
    $stmt->execute([$r['id'], $user['id']]);

    save_message($r['id'], $user, 'system', "{$user['username']} 加入了房间");
    json_ok(['msg' => '已加入']);
}

function lzcx_assign_character(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以分配角色', 403);

    $data = body_json();
    $targetUserId = (int)($data['user_id'] ?? 0);
    $character = trim($data['character'] ?? '');
    if ($targetUserId <= 0) json_error('缺少 user_id');
    // character 为空表示取消角色
    if (mb_strlen($character) > 50) json_error('角色名过长');

    $state = lzcx_load_state($r);
    if (!empty($state['game_started'])) json_error('游戏已经开始，无法分配角色', 403);

    if ($character !== '') {
        lzcx_check_character_conflict((int)$r['id'], $character, $targetUserId);
    }

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$r['id'], $targetUserId]);
    if (!$stmt->fetch()) json_error('目标用户不在房间内', 404);

    $stmt = $pdo->prepare('UPDATE room_members SET character_name = ? WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$character !== '' ? $character : null, $r['id'], $targetUserId]);

    // 取目标用户名
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
    $stmt->execute([$targetUserId]);
    $tu = $stmt->fetch();

    $msg = $character !== ''
        ? "房主分配 {$tu['username']} 扮演角色「{$character}」"
        : "房主取消了 {$tu['username']} 的角色";
    save_message($r['id'], $user, 'system', $msg);

    // 角色被动：意马 +20% 初始理智，柳双鱼 -10% 初始理智
    $state = lzcx_load_state($r);
    $state = lzcx_recalc_initial_sanity((int)$r['id'], $state);
    lzcx_save_state((int)$r['id'], $state);

    json_ok(['msg' => '已分配', 'state' => $state]);
}

/** 根据当前房间角色重新计算初始理智（意马+20%，柳双鱼-10%） */
function lzcx_recalc_initial_sanity(int $roomId, array $state): array {
    $base = (int)($state['base_initial_sanity'] ?? $state['initial_sanity'] ?? 0);
    if ($base <= 0) return $state;

    $multiplier = 1.0;
    if (lzcx_has_character_in_room($roomId, '意马')) $multiplier += 0.2;
    if (lzcx_has_character_in_room($roomId, '柳双鱼')) $multiplier -= 0.1;

    $newInitial = (int)round($base * $multiplier);
    $state['base_initial_sanity'] = $base;
    $state['initial_sanity'] = $newInitial;
    // 登场时重置为新的初始理智
    $state['sanity'] = $newInitial;
    return $state;
}

function lzcx_members(string $code) {
    $r = lzcx_require_room($code);
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT rm.user_id, rm.role, rm.character_name, rm.joined_at, u.username FROM room_members rm JOIN users u ON rm.user_id = u.id WHERE rm.room_id = ? ORDER BY rm.joined_at');
    $stmt->execute([$r['id']]);
    json_ok(['members' => $stmt->fetchAll()]);
}

// ===================== 消息 =====================

function lzcx_send_message(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    if ($content === '') json_error('内容不能为空');
    validate_length($content, 2000, '消息内容');

    $r = lzcx_require_room($code);
    if ($r['status'] !== 'playing') json_error('房间已结束');

    // 必须是房间成员
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$r['id'], $user['id']]);
    if (!$stmt->fetch()) json_error('您不是该房间成员', 403);

    if (!rate_limit("lzcx_msg_room_{$r['id']}_user_{$user['id']}", Config::$RATE_LIMIT_MSG_SEND, 60)) {
        json_error('发送消息过于频繁，请稍后再试', 429);
    }

    $state = lzcx_load_state($r);
    if (empty($state['game_started'])) json_error('游戏尚未开始，请等待房主开始游戏', 403);
    // 幻灵状态下禁止主动发言（只能被他人 @ 提问）
    if (!empty($state['possessed_user_id']) && (int)$state['possessed_user_id'] === (int)$user['id']) {
        json_error('你正处于幻灵状态，已被禁言，等待其他玩家向你提问');
    }

    $msg = save_message($r['id'], $user, 'chat', $content);
    json_ok(['message' => message_to_dict($msg)]);
}

function lzcx_poll_messages(string $code) {
    $r = lzcx_require_room($code);
    $since = (int)($_GET['since'] ?? 0);
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT id, user_id, username, msg_type, content, created_at FROM messages WHERE room_id = ? AND id > ? ORDER BY id');
    $stmt->execute([$r['id'], $since]);
    $msgs = $stmt->fetchAll();
    json_ok(['messages' => array_map('message_to_dict', $msgs), 'last_id' => end($msgs) ? (int)end($msgs)['id'] : $since]);
}

// ===================== 玩家技能（纯对话驱动） =====================

function lzcx_skill(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    if ($content === '') json_error('技能内容不能为空');
    validate_length($content, 500, '技能内容');

    $r = lzcx_require_room($code);
    if ($r['status'] !== 'playing') json_error('房间已结束');
    if (!$r['soup_id']) json_error('房间里还没有选汤');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT role, character_name FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$r['id'], $user['id']]);
    $membership = $stmt->fetch();
    if (!$membership) json_error('您不是该房间成员', 403);

    $character = $membership['character_name'] ?? '';
    if ($character === '') json_error('您还没有被分配角色，无法使用技能');

    $state = lzcx_load_state($r);
    if (empty($state['game_started'])) json_error('游戏尚未开始，请等待房主开始游戏', 403);

    // 幻灵状态下禁止普通发言/技能（只能被提问）
    if (!empty($state['possessed_user_id']) && (int)$state['possessed_user_id'] === (int)$user['id']) {
        json_error('你正处于幻灵状态，已被禁言，等待其他玩家向你提问');
    }

    // 解析技能指令：/技能名 [参数]
    $cmd = ltrim($content, '/');
    $parts = preg_split('/\s+/u', $cmd, -1, PREG_SPLIT_NO_EMPTY);
    $skill = strtolower($parts[0] ?? '');
    $arg = trim(implode(' ', array_slice($parts, 1)));

    // 保存玩家技能宣言消息
    save_message($r['id'], $user, 'chat', $content);

    switch ($skill) {
        case '排除':
        case 'exclude':
            if ($character !== '减') json_error('只有「减」可以发动【排除】');
            if ((int)$state['sanity'] < 2) json_error('理智不足，无法发动【排除】');
            if ($arg === '') json_error('请给出要排除的结论，例如 /排除 凶手是老板娘');
            $consume = lzcx_consume_sanity($r, $user, $state, 2);
            $state = $consume['state'];
            $sunMsg = $consume['sun_message'];

            $answer = lzcx_ai_judge($r, $user, $state, $character, 'exclude', $arg);
            $success = false;
            if (preg_match('/<<<EXCLUDE:(成功|失败)>>>/u', $answer, $m)) {
                $success = $m[1] === '成功';
                $answer = str_replace($m[0], '', $answer);
            }
            $answer = trim($answer);
            $systemMsg = "减发动了【排除】，消耗2理智。结论「{$arg}」" . ($success ? '排除成功' : '排除失败') . "。";
            if ($sunMsg) $systemMsg .= ' ' . $sunMsg;
            save_message($r['id'], $user, 'system', $systemMsg);
            $a_msg = save_message($r['id'], null, 'ai_answer', $answer ?: "结论「{$arg}」" . ($success ? '排除成功' : '排除失败') . "。");

            json_ok(['message' => message_to_dict($a_msg), 'state' => $state, 'skill' => 'exclude']);

        case '破局':
        case 'poju':
            if ($character !== '许复元') json_error('只有「许复元」可以发动【破局】');
            if ($arg === '') json_error('请给出推理，例如 /破局 凶手是老板娘');

            $answer = lzcx_ai_judge($r, $user, $state, $character, 'poju', $arg);
            $result = '';
            if (preg_match('/<<<RESULT:(是|不是|是也不是)>>>/u', $answer, $m)) {
                $result = $m[1];
                $answer = str_replace($m[0], '', $answer);
            }
            $answer = trim($answer);

            // 若回答为「不是」消耗4理智
            $sunMsg = '';
            if ($result === '不是') {
                $consume = lzcx_consume_sanity($r, $user, $state, 4);
                $state = $consume['state'];
                $sunMsg = $consume['sun_message'];
            }

            $systemMsg = "许复元发动了【破局】。主持人回答：{$result}" . ($result === '不是' ? '，许复元消耗4理智' : '') . "。";
            if ($sunMsg) $systemMsg .= ' ' . $sunMsg;
            save_message($r['id'], $user, 'system', $systemMsg);
            $a_msg = save_message($r['id'], null, 'ai_answer', $answer ?: "主持人回答：{$result}。");

            json_ok(['message' => message_to_dict($a_msg), 'state' => $state, 'skill' => 'poju']);

        case '心声':
        case 'xinsheng':
            if ($character !== '辛笙') json_error('只有「辛笙」可以发动【心声】');
            if ((int)$state['sanity'] < 2) json_error('理智不足，无法发动【心声】');
            if ($arg === '') json_error('请给出两个结论，用「；」分隔，例如 /心声 凶手是老板娘；死者是自杀');
            $consume = lzcx_consume_sanity($r, $user, $state, 2);
            $state = $consume['state'];
            $sunMsg = $consume['sun_message'];

            $answer = lzcx_ai_judge($r, $user, $state, $character, 'xinsheng', $arg);
            $count = null;
            if (preg_match('/<<<COUNT:(\d+)>>>/u', $answer, $m)) {
                $count = (int)$m[1];
                $answer = str_replace($m[0], '', $answer);
            }
            $answer = trim($answer);

            $systemMsg = "辛笙发动了【心声】，消耗2理智。两个结论中绝对正确的数量为：" . ($count !== null ? $count : '未知') . "。";
            if ($sunMsg) $systemMsg .= ' ' . $sunMsg;
            save_message($r['id'], $user, 'system', $systemMsg);
            $a_msg = save_message($r['id'], null, 'ai_answer', $answer ?: "绝对正确的结论数量：" . ($count !== null ? $count : '未知') . "。");

            json_ok(['message' => message_to_dict($a_msg), 'state' => $state, 'skill' => 'xinsheng']);

        case '现':
        case '现！':
        case 'xian':
            if ($character !== '柳千渊') json_error('只有「柳千渊」可以发动【现！】');
            if ((int)$state['sanity'] < 4) json_error('理智不足，无法发动【现！】');
            // 不可连续发动：同一玩家不能连续两次发动现！
            if (!empty($state['last_xian_user_id']) && (int)$state['last_xian_user_id'] === (int)$user['id']) {
                json_error('【现！】不可连续发动，请等待其他玩家行动后再试');
            }

            // 记录本次发动者，并统一消耗理智（孙沐阳自己降理智才会触发被动）
            $state['last_xian_user_id'] = (int)$user['id'];
            $consume = lzcx_consume_sanity($r, $user, $state, 4);
            $state = $consume['state'];
            $sunMsg = $consume['sun_message'];

            // 获得碎片（含柳双鱼拷贝）
            $gain = lzcx_gain_fragment($r, $state);
            $state = $gain['state'];
            $gained = $gain['fragments'];
            $copyMessage = count($gained) > 1 ? '柳双鱼发动【拷贝】，额外复制了一张碎片。' : '';

            $fragAnswerParts = [];
            foreach ($gained as $f) {
                $typeLabel = $f['type'] ? "【{$f['type']}碎片】" : '碎片';
                $fragAnswerParts[] = $f['url']
                    ? "主持人展示了{$typeLabel}：\n\n![碎片]({$f['url']})"
                    : "主持人展示了{$typeLabel}（暂无图片）";
            }
            $fragAnswer = implode("\n\n", $fragAnswerParts);

            $systemMsg = "柳千渊发动了【现！】，消耗4理智，获得一片残响碎片。";
            if ($copyMessage) $systemMsg .= ' ' . $copyMessage;
            if ($sunMsg) $systemMsg .= ' ' . $sunMsg;
            save_message($r['id'], $user, 'system', $systemMsg);

            $a_msg = save_message($r['id'], null, 'ai_answer', $fragAnswer ?: '主持人展示了一片残响碎片。');

            json_ok(['message' => message_to_dict($a_msg), 'state' => $state, 'skill' => 'xian']);

        case '幻灵':
        case 'huanling':
        case 'possess':
            if (!in_array($character, ['柳千渊', '孙沐阳'], true)) {
                json_error('只有「重现署·灵者」可以发动【幻灵】');
            }
            if ((int)$state['sanity'] < 5) json_error('理智不足，无法发动【幻灵】');
            if ($arg === '') json_error('请指定幻灵角色，例如 /幻灵 老板娘');
            if (!empty($state['possessed_user_id'])) json_error('当前已有幻灵状态，请等待其结束后再发动');

            $consume = lzcx_consume_sanity($r, $user, $state, 5);
            $state = $consume['state'];
            $sunMsg = $consume['sun_message'];
            $state['possessed_user_id'] = (int)$user['id'];
            $state['possessed_character'] = $arg;
            lzcx_save_state((int)$r['id'], $state);

            $systemMsg = "{$user['username']}（{$character}）发动【幻灵】，化身为「{$arg}」，已被禁言。其他玩家可 @{$user['username']} 向其提问，主持人会以「{$arg}」的身份回答。";
            if ($sunMsg) $systemMsg .= ' ' . $sunMsg;
            save_message($r['id'], $user, 'system', $systemMsg);

            json_ok(['state' => $state, 'skill' => 'huanling']);

        case '碎片类型':
        case 'fragmenttype':
        case 'fragment-type':
            if ($character !== '孙沐阳') json_error('只有「孙沐阳」可以指定碎片类型');
            $validTypes = ['暗喻', '线索', '指引', '剧情', '隐藏'];
            if (!in_array($arg, $validTypes, true)) {
                json_error('请指定有效碎片类型：暗喻、线索、指引、剧情、隐藏。例如 /碎片类型 暗喻');
            }
            $state['sun_fragment_type'] = $arg;
            lzcx_save_state((int)$r['id'], $state);
            save_message($r['id'], $user, 'system', "孙沐阳指定了下次【以心为眼】获得的碎片类型：{$arg}。");
            json_ok(['state' => $state, 'skill' => 'fragmenttype']);

        default:
            json_error('未知技能。当前支持：/排除、/破局、/心声、/现、/幻灵 角色名、/碎片类型 类型');
    }
}

/** 判断房间中是否有某个灵渊司角色 */
function lzcx_has_character_in_room(int $roomId, string $characterName): bool {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT 1 FROM room_members WHERE room_id = ? AND character_name = ?');
    $stmt->execute([$roomId, $characterName]);
    return (bool)$stmt->fetch();
}

/** 检查角色羁绊冲突（意马不与孙沐阳同场） */
function lzcx_check_character_conflict(int $roomId, string $characterName, ?int $excludeUserId = null): void {
    if ($characterName !== '意马' && $characterName !== '孙沐阳') return;
    $conflict = $characterName === '意马' ? '孙沐阳' : '意马';
    $pdo = DB::pdo();
    $sql = 'SELECT 1 FROM room_members WHERE room_id = ? AND character_name = ?';
    $params = [$roomId, $conflict];
    if ($excludeUserId) {
        $sql .= ' AND user_id != ?';
        $params[] = $excludeUserId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        json_error("「{$characterName}」与「{$conflict}」存在羁绊，不能同场", 403);
    }
}

/**
 * 统一获得碎片（含柳双鱼拷贝）。
 * $preferredType: 孙沐阳可指定碎片类型，优先获取该类型中第一张未释放的碎片。
 * 返回 ['state'=>..., 'fragments'=>[['index'=>int,'type'=>string,'url'=>string|null], ...]]
 */
function lzcx_gain_fragment(array $r, array $state, ?string $preferredType = null): array {
    $total = (int)($state['total_fragments'] ?? 0);
    $released = (int)($state['released_fragments'] ?? 0);
    $types = $state['fragment_types'] ?? [];
    $images = $state['fragment_images'] ?? [];

    if ($total > 0 && $released >= $total) {
        return ['state' => $state, 'fragments' => []];
    }

    $idx = $released;
    // 若指定了类型，找第一张未释放的该类型碎片
    if ($preferredType) {
        $max = $total > 0 ? $total : max(count($types), $released + 1, count($images));
        for ($i = $released; $i < $max; $i++) {
            if (($types[$i] ?? '') === $preferredType) {
                $idx = $i;
                break;
            }
        }
    }

    $gained = [];
    $gained[] = [
        'index' => $idx,
        'type' => $types[$idx] ?? '',
        'url' => $images[$idx] ?? null,
    ];
    $state['released_fragments'] = $idx + 1;

    // 柳双鱼拷贝：若柳双鱼在场且还有剩余碎片，额外再释放一张
    if (lzcx_has_character_in_room((int)$r['id'], '柳双鱼')) {
        $newReleased = (int)$state['released_fragments'];
        if ($total === 0 || $newReleased < $total) {
            $gained[] = [
                'index' => $newReleased,
                'type' => $types[$newReleased] ?? '',
                'url' => $images[$newReleased] ?? null,
            ];
            $state['released_fragments'] = $newReleased + 1;
        }
    }

    lzcx_save_state((int)$r['id'], $state);
    return ['state' => $state, 'fragments' => $gained];
}

/**
 * 通用 AI 技能判定。
 * 要求 AI 在回答第一行输出结果标记：
 *   /排除 → <<<EXCLUDE:成功>>> 或 <<<EXCLUDE:失败>>>
 *   /破局 → <<<RESULT:是>>> / <<<RESULT:不是>>> / <<<RESULT:是也不是>>>
 *   /心声 → <<<COUNT:0>>> / <<<COUNT:1>>> / <<<COUNT:2>>>
 */
function lzcx_ai_judge(array $r, array $user, array $state, string $character, string $skill, string $arg): string {
    if (empty($r['ai_enabled'])) {
        return lzcx_fallback_judge($skill, $arg);
    }

    [$api_key, $provider, $baseUrl, $model] = lzcx_decode_host_key($r['ai_key_encrypted'] ?? null);
    if ($api_key === '') {
        return lzcx_fallback_judge($skill, $arg);
    }

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT surface, base, host_manual, extra FROM soups WHERE id = ? AND status = 'approved'");
    $stmt->execute([$r['soup_id']]);
    $soup = $stmt->fetch();
    if (!$soup) return lzcx_fallback_judge($skill, $arg);

    $prompts = [
        'exclude' => "玩家「{$user['username']}」扮演「减」，发动技能【排除】，消耗2理智。\n" .
                     "结论：「{$arg}」\n" .
                     "请判断该残响中是否存在本结论。\n" .
                     "第一行必须输出结果标记：<<<EXCLUDE:成功>>>（不存在，排除成功）或 <<<EXCLUDE:失败>>>（存在，排除失败）。\n" .
                     "第二行起用主持人口吻简短说明，不要泄露汤底。",
        'poju' => "玩家「{$user['username']}」扮演「许复元」，发动技能【破局】。\n" .
                  "推理：「{$arg}」\n" .
                  "请站在上帝视角判断该推理是否正确。\n" .
                  "第一行必须输出结果标记：<<<RESULT:是>>>、<<<RESULT:不是>>> 或 <<<RESULT:是也不是>>>。\n" .
                  "第二行起用主持人口吻简短说明，不要泄露汤底。",
        'xinsheng' => "玩家「{$user['username']}」扮演「辛笙」，发动技能【心声】，消耗2理智。\n" .
                      "两个结论：「{$arg}」\n" .
                      "请判断其中绝对正确的结论数量。\n" .
                      "第一行必须输出结果标记：<<<COUNT:0>>>、<<<COUNT:1>>> 或 <<<COUNT:2>>>。\n" .
                      "第二行起用主持人口吻简短说明，不要泄露汤底。",
    ];

    $prompt = $prompts[$skill] ?? '';
    if ($prompt === '') return lzcx_fallback_judge($skill, $arg);

    try {
        return ask_ai_lzcx(
            $soup['surface'] ?: '',
            $soup['base'] ?? '',
            $soup['host_manual'] ?? '',
            $soup['extra'] ?? '',
            [],
            $state,
            $prompt,
            $character,
            $user['username'],
            $api_key,
            $provider,
            $baseUrl,
            $model,
            null
        );
    } catch (Throwable $e) {
        return lzcx_fallback_judge($skill, $arg);
    }
}

/** AI 未启用或无 Key 时的兜底判定（随机/保守） */
function lzcx_fallback_judge(string $skill, string $arg): string {
    switch ($skill) {
        case 'exclude':
            return '<<<EXCLUDE:失败>>> 该结论无法排除（AI 未启用，请主持人人工判定）。';
        case 'poju':
            return '<<<RESULT:是也不是>>> 该推理部分正确（AI 未启用，请主持人人工判定）。';
        case 'xinsheng':
            return '<<<COUNT:1>>> 两个结论中有一个绝对正确（AI 未启用，请主持人人工判定）。';
        default:
            return '';
    }
}

/**
 * 统一消耗理智并触发孙沐阳被动【以心为眼】。
 * 返回 ['state'=>..., 'sun_message'=>...]
 */
function lzcx_consume_sanity(array $r, array $user, array $state, int $cost): array {
    if ($cost <= 0) return ['state' => $state, 'sun_message' => ''];
    $state['sanity'] = max(0, (int)$state['sanity'] - $cost);
    lzcx_save_state((int)$r['id'], $state);
    $sunExtra = lzcx_check_sun_muyang_reward($r, $user, $state);
    if ($sunExtra) {
        return ['state' => $sunExtra['state'], 'sun_message' => $sunExtra['message']];
    }
    return ['state' => $state, 'sun_message' => ''];
}

/**
 * 孙沐阳被动【以心为眼】：孙沐阳自己每减少15理智获得一块碎片，可指定碎片类型。
 * 返回 null 或 ['state'=>..., 'message'=>...]
 */
function lzcx_check_sun_muyang_reward(array $r, array $user, array $state): ?array {
    // 只有孙沐阳自己消耗理智时才触发
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT user_id FROM room_members WHERE room_id = ? AND character_name = ?');
    $stmt->execute([(int)$r['id'], '孙沐阳']);
    $sunUserId = $stmt->fetchColumn();
    if (!$sunUserId || (int)$sunUserId !== (int)$user['id']) return null;

    $initial = (int)($state['initial_sanity'] ?? 0);
    $current = (int)($state['sanity'] ?? 0);
    $lost = $initial - $current;
    $threshold = 15;
    $prevLost = (int)($state['sun_muyang_last_lost'] ?? 0);
    $count = intdiv(max(0, $lost), $threshold) - intdiv(max(0, $prevLost), $threshold);

    if ($count <= 0) return null;

    $preferredType = $state['sun_fragment_type'] ?? null;
    $msgParts = [];
    for ($i = 0; $i < $count; $i++) {
        $gain = lzcx_gain_fragment($r, $state, $preferredType);
        if (empty($gain['fragments'])) break;
        $state = $gain['state'];
        $first = $gain['fragments'][0];
        $typeLabel = $first['type'] ? "【{$first['type']}碎片】" : '碎片';
        $msgParts[] = "孙沐阳发动【以心为眼】，累计理智减少达到阈值，获得第 " . ($first['index'] + 1) . " 片{$typeLabel}。";
        if (count($gain['fragments']) > 1) {
            $msgParts[] = '柳双鱼发动【拷贝】，额外复制了一张碎片。';
        }
        // 指定类型仅在本次触发中生效一次，用完即清空
        $preferredType = null;
        $state['sun_fragment_type'] = null;
    }
    $state['sun_muyang_last_lost'] = $lost;
    lzcx_save_state((int)$r['id'], $state);

    return ['state' => $state, 'message' => implode(' ', $msgParts)];
}

// ===================== AI 提问（核心） =====================

function lzcx_ask(string $code) {
    $user = require_login();
    $data = body_json();
    $content = trim($data['content'] ?? '');
    $api_key = (string)($data['api_key'] ?? '');
    $provider = (string)($data['provider'] ?? 'deepseek');
    $ai_base_url = (string)($data['base_url'] ?? '');
    $ai_model = (string)($data['model'] ?? '');
    if ($content === '') json_error('问题不能为空');
    validate_length($content, 500, '问题内容');

    $r = lzcx_require_room($code);
    if ($r['status'] !== 'playing') json_error('房间已结束');
    if (!$r['soup_id']) json_error('房间里还没有选汤');
    if (!$r['ai_enabled']) json_error('AI 未启用');

    // 必须是房间成员
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT role, character_name FROM room_members WHERE room_id = ? AND user_id = ?');
    $stmt->execute([$r['id'], $user['id']]);
    $membership = $stmt->fetch();
    if (!$membership) json_error('您不是该房间成员', 403);

    if (!rate_limit("lzcx_ai_ask_user_{$user['id']}", Config::$RATE_LIMIT_AI_ASK, 60)) {
        json_error('AI 提问过于频繁，请稍后再试', 429);
    }
    if ((int)$r['ai_question_limit'] > 0 && (int)$r['ai_question_count'] >= (int)$r['ai_question_limit']) {
        json_error('AI 提问次数已达上限（' . (int)$r['ai_question_limit'] . '次）');
    }

    // ===== 房主 key 共享：优先用房间绑定的加密 key bundle，没有才用前端传的 key =====
    [$hostKey, $hostProvider, $hostBaseUrl, $hostModel] = lzcx_decode_host_key($r['ai_key_encrypted'] ?? null);
    if ($hostKey !== '') {
        $api_key = $hostKey;
        // 房主绑定时一并存的 provider 配置优先（保证全员用同一套调用方式）
        $provider = $hostProvider ?: $provider;
        $ai_base_url = $hostBaseUrl ?: $ai_base_url;
        $ai_model = $hostModel ?: $ai_model;
    }
    if ($api_key === '') {
        json_error('本房间未绑定 AI Key，请房主在房间内绑定后再提问', 'missing_key');
    }

    // 取汤（含主持人手册/其他内容，仅审核汤）
    $stmt = $pdo->prepare("SELECT surface, base, host_manual, extra FROM soups WHERE id = ? AND status = 'approved'");
    $stmt->execute([$r['soup_id']]);
    $soup = $stmt->fetch();
    if (!$soup || empty($soup['base'])) {
        $a_msg = save_message($r['id'], null, 'ai_answer', '该汤没有汤底，无法提问');
        json_ok(['answer' => message_to_dict($a_msg), 'error' => '该汤没有汤底，无法提问']);
    }

    // 加载状态机
    $state = lzcx_load_state($r);
    if (empty($state['game_started'])) json_error('游戏尚未开始，请等待房主开始游戏', 403);

    // 幻灵状态处理
    $possessedUsername = null;
    $possessedCharacter = null;
    if (!empty($state['possessed_user_id'])) {
        if ((int)$state['possessed_user_id'] === (int)$user['id']) {
            json_error('你正处于幻灵状态，已被禁言，等待其他玩家向你提问');
        }
        $stmt = $pdo->prepare('SELECT u.username FROM room_members rm JOIN users u ON rm.user_id = u.id WHERE rm.room_id = ? AND rm.user_id = ?');
        $stmt->execute([$r['id'], (int)$state['possessed_user_id']]);
        $possessedUsername = $stmt->fetchColumn() ?: null;
        $possessedCharacter = $state['possessed_character'] ?? '';
    }

    // 若提问 @了幻灵玩家，主持人以幻灵角色回答
    $aiContent = $content;
    if ($possessedUsername && $possessedCharacter && str_contains($content, '@' . $possessedUsername)) {
        $aiContent = "[向幻灵角色「{$possessedCharacter}」（玩家 @{$possessedUsername}）提问] " . $content . "\n\n请主持人以「{$possessedCharacter}」的身份回答，只能用「是」「不是」或「是也不是」回答，不要泄露其他信息。";
    }

    // 取最近 N 条 ai_question + ai_answer 作为多轮上下文
    $stmt = $pdo->prepare("SELECT msg_type, username, content FROM messages WHERE room_id = ? AND msg_type IN ('ai_question','ai_answer') ORDER BY id DESC LIMIT 40");
    $stmt->execute([$r['id']]);
    $rows = array_reverse($stmt->fetchAll());
    $history = [];
    foreach ($rows as $row) {
        if ($row['msg_type'] === 'ai_question') {
            $history[] = ['role' => 'user', 'name' => $row['username'] ?? '', 'content' => $row['content']];
        } else { // ai_answer
            $history[] = ['role' => 'assistant', 'name' => '', 'content' => $row['content']];
        }
    }

    // 保存提问（仍用原始内容展示）
    $q_msg = save_message($r['id'], $user, 'ai_question', $content);

    // 提问者角色：房主=host（全知），player 用 character_name
    $askerCharacter = '';
    if ($membership['role'] !== 'host' && !empty($membership['character_name'])) {
        $askerCharacter = $membership['character_name'];
    }

    // 关键节点状态：state.key_nodes 存在则启用机制
    // - null：旧房间未启用（向后兼容，理论上 lzcx_init_state 已默认 []）
    // - []：启用但待 AI 自拆
    // - [{name,hit}, ...]：已有节点列表
    $keyNodes = array_key_exists('key_nodes', $state) ? $state['key_nodes'] : null;

    try {
        $answer = ask_ai_lzcx(
            $soup['surface'] ?: '',
            $soup['base'],
            $soup['host_manual'] ?? '',
            $soup['extra'] ?? '',
            $history,
            $state,
            $aiContent,
            $askerCharacter,
            $user['username'],
            $api_key,
            $provider,
            $ai_base_url,
            $ai_model,
            $keyNodes
        );
    } catch (AIError $e) {
        json_ok([
            'question' => message_to_dict($q_msg),
            'answer' => null,
            'error' => $e->getMessage(),
            'code' => $e->aiCode,
        ]);
    }

    // ===== 剥离 AI 回答中的元信息标记 + 更新关键节点/隐藏规则/任务状态 =====
    $justCleared = false;
    $newHits = [];
    $newTriggers = [];
    $newTasks = [];
    if ($keyNodes !== null) {
        // 1) 处理 NODES 标记（AI 自拆节点，仅在 key_nodes 为空时接收）
        if (empty($state['key_nodes']) && preg_match('/<<<NODES:([^>]+?)>>>/u', $answer, $nm)) {
            $names = array_filter(array_map('trim', explode('|', $nm[1])));
            $names = array_values(array_unique($names));
            if (count($names) >= 3) { // 至少 3 个才采纳，防 AI 乱输出
                $state['key_nodes'] = array_map(function ($n) { return ['name' => $n, 'hit' => false]; }, $names);
            }
            $answer = str_replace($nm[0], '', $answer);
        }

        // 2) 处理 HIT 标记
        if (preg_match('/<<<HIT:([^>]+?)>>>/u', $answer, $hm)) {
            $hitName = trim($hm[1]);
            foreach (($state['key_nodes'] ?? []) as &$node) {
                if (!$node['hit'] && $node['name'] === $hitName) {
                    $node['hit'] = true;
                    $newHits[] = $hitName;
                    break;
                }
            }
            unset($node);
            $answer = str_replace($hm[0], '', $answer);
            // 兜底：剥离可能残留的其它 HIT 标记
            $answer = preg_replace('/<<<HIT:[^>]*?>>>/u', '', $answer);
        }

        // 3) 处理 TRIGGER 标记（AI 自动触发隐藏规则）
        if (preg_match_all('/<<<TRIGGER:([^>]+?)>>>/u', $answer, $tm)) {
            foreach ($tm[1] as $ruleName) {
                $ruleName = trim($ruleName);
                if ($ruleName === '') continue;
                $triggeredList = $state['triggered_rules'] ?? [];
                if (!in_array($ruleName, $triggeredList, true)) {
                    $state['triggered_rules'][] = $ruleName;
                    $newTriggers[] = $ruleName;
                }
            }
            $answer = preg_replace('/<<<TRIGGER:[^>]*?>>>/u', '', $answer);
        }

        // 4) 处理 TASK 标记（AI 自动判定任务完成）
        if (preg_match_all('/<<<TASK:(\d+)>>>/u', $answer, $tmm)) {
            foreach ($tmm[1] as $taskNum) {
                $taskNum = (int)$taskNum;
                if ($taskNum <= 0) continue;
                $completedList = $state['completed_tasks'] ?? [];
                if (!in_array($taskNum, $completedList, true)) {
                    $state['completed_tasks'][] = $taskNum;
                    $newTasks[] = $taskNum;
                }
            }
            sort($state['completed_tasks']);
            $answer = preg_replace('/<<<TASK:\d+>>>/u', '', $answer);
        }
        $answer = trim($answer);

        // 5) 通关判定：命中节点数 / 总节点数 ≥ 85%
        $nodes = $state['key_nodes'] ?? [];
        if (!empty($nodes) && empty($state['cleared'])) {
            $total = count($nodes);
            $hitCount = count(array_filter($nodes, function ($n) { return !empty($n['hit']); }));
            if ($total > 0 && ($hitCount / $total) >= 0.85) {
                $state['cleared'] = true;
                $justCleared = true;
            }
        }
    }

    // 成功后递增 ask_count 和房间 ai_question_count
    $state['ask_count'] = (int)($state['ask_count'] ?? 0) + 1;
    lzcx_save_state((int)$r['id'], $state);
    $pdo->exec('UPDATE rooms SET ai_question_count = ai_question_count + 1 WHERE id = ' . (int)$r['id']);

    $a_msg = save_message($r['id'], null, 'ai_answer', $answer);

    // 向幻灵提问后自动解除幻灵状态
    $possessedCleared = false;
    if ($possessedUsername && $possessedCharacter && str_contains($content, '@' . $possessedUsername)) {
        $state['possessed_user_id'] = null;
        $state['possessed_character'] = null;
        lzcx_save_state((int)$r['id'], $state);
        save_message($r['id'], null, 'system', "幻灵状态已解除，{$possessedUsername} 恢复正常发言。");
        $possessedCleared = true;
    }

    // 命中节点系统提示
    if (!empty($newHits)) {
        save_message($r['id'], null, 'system', '🎯 命中关键节点：' . implode('、', $newHits));
    }
    // 隐藏规则触发系统提示
    if (!empty($newTriggers)) {
        save_message($r['id'], null, 'system', '📜 AI 触发隐藏规则：' . implode('、', $newTriggers));
    }
    // 任务完成系统提示
    if (!empty($newTasks)) {
        $taskLabels = array_map(function ($n) { return $n === 999 ? '最终任务' : "任务{$n}"; }, $newTasks);
        save_message($r['id'], null, 'system', '✅ AI 判定任务完成：' . implode('、', $taskLabels));
    }
    // 通关系统提示
    if ($justCleared) {
        $nodes = $state['key_nodes'] ?? [];
        $total = count($nodes);
        $hitCount = count(array_filter($nodes, function ($n) { return !empty($n['hit']); }));
        save_message($r['id'], null, 'system', "🏆 通关！已盘出 {$hitCount}/{$total} 个关键节点（≥85%），真相大白！");
    }

    json_ok([
        'question' => message_to_dict($q_msg),
        'answer' => message_to_dict($a_msg),
        'state' => $state,
    ]);
}

// ===================== 房主状态机控制 =====================

function lzcx_trigger(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以触发规则', 403);
    if (!empty($r['ai_enabled'])) json_error('AI 主持模式下，隐藏规则由 AI 根据玩家提问自动触发，房主无需手动触发', 403);

    $data = body_json();
    $ruleName = trim($data['rule'] ?? '');
    if ($ruleName === '') json_error('请填写规则名（如 规则六）');

    $state = lzcx_load_state($r);
    if (in_array($ruleName, $state['triggered_rules'] ?? [], true)) {
        json_error('该规则已触发');
    }
    $state['triggered_rules'][] = $ruleName;
    lzcx_save_state((int)$r['id'], $state);

    save_message($r['id'], $user, 'system', "房主触发了 {$ruleName}，对应真相已可向玩家揭示");

    json_ok(['msg' => '已触发', 'state' => $state]);
}

function lzcx_complete_task(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以标记任务完成', 403);
    if (!empty($r['ai_enabled'])) json_error('AI 主持模式下，任务完成由 AI 自动判定，房主无需手动标记', 403);

    $data = body_json();
    $taskNum = (int)($data['task'] ?? 0);
    if ($taskNum <= 0) json_error('请填写任务编号');

    $state = lzcx_load_state($r);
    if (in_array($taskNum, $state['completed_tasks'] ?? [], true)) {
        json_error('该任务已完成');
    }
    $state['completed_tasks'][] = $taskNum;
    // 任务按序排序
    sort($state['completed_tasks']);
    lzcx_save_state((int)$r['id'], $state);

    save_message($r['id'], $user, 'system', "房主标记任务 {$taskNum} 已完成");

    json_ok(['msg' => '已标记', 'state' => $state]);
}

function lzcx_set_sanity(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以调整理智', 403);

    $data = body_json();
    $sanity = (int)($data['sanity'] ?? -1);
    if ($sanity < 0) json_error('理智值必须 ≥ 0');

    $state = lzcx_load_state($r);
    $state['sanity'] = $sanity;
    lzcx_save_state((int)$r['id'], $state);

    save_message($r['id'], $user, 'system', "房主调整剩余理智为 {$sanity}");

    json_ok(['msg' => '已调整', 'state' => $state]);
}

function lzcx_reset_state(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以重置状态', 403);

    // 重新解析汤源，重建初始状态机
    $stmt = DB::pdo()->prepare("SELECT surface, host_manual, extra FROM soups WHERE id = ?");
    $stmt->execute([$r['soup_id']]);
    $soup = $stmt->fetch();
    $meta = lzcx_parse_meta($soup['surface'] ?? '', $soup['host_manual'] ?? '', $soup['extra'] ?? '');
    $state = lzcx_init_state($meta);
    lzcx_save_state((int)$r['id'], $state);

    save_message($r['id'], $user, 'system', '房主重置了房间状态机（碎片/触发/任务/理智）');

    json_ok(['msg' => '已重置', 'state' => $state]);
}

/**
 * 房主主持人指令（纯对话模式）
 * 指令格式：/动作 参数
 * 支持：/规则 规则名、/任务 编号、/理智 数值、/重置、/解除幻灵
 */
function lzcx_host_command(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以发送主持人指令', 403);

    $pdo = DB::pdo();
    $data = body_json();
    $cmd = trim((string)($data['command'] ?? ''));
    if ($cmd === '' || !str_starts_with($cmd, '/')) {
        json_error('主持人指令需以 / 开头');
    }

    // 去掉前缀 / 并按空白切分
    $cmd = ltrim($cmd, '/');
    $parts = preg_split('/\s+/u', $cmd, -1, PREG_SPLIT_NO_EMPTY);
    $action = strtolower($parts[0] ?? '');
    $arg = trim(implode(' ', array_slice($parts, 1)));

    // AI 主持模式下，房主只能分配角色（游戏开始前）和解除幻灵，其余推进操作交给 AI
    $aiHostAllowed = ['分配', 'assign', 'assign-character', '解除幻灵', '解除', 'unpossess'];
    if (!empty($r['ai_enabled']) && !in_array($action, $aiHostAllowed, true)) {
        json_error('AI 主持模式下，房主只能分配角色（游戏开始前）和解除幻灵', 403);
    }

    $state = lzcx_load_state($r);
    $msg = '';

    // 游戏状态校验：
    // - /分配 只能在游戏开始前使用
    // - /重置 始终允许（重置状态机，仅在人工主持模式下）
    // - 其余推进类指令需游戏已开始
    $isAssign = in_array($action, ['分配', 'assign', 'assign-character'], true);
    $isReset = in_array($action, ['重置', 'reset', '重置状态'], true);
    if ($isAssign && !empty($state['game_started'])) {
        json_error('游戏已经开始，无法分配角色', 403);
    }
    if (!$isAssign && !$isReset && empty($state['game_started'])) {
        json_error('游戏尚未开始，请等待房主开始游戏', 403);
    }

    switch ($action) {
        case '规则':
        case 'rule':
        case '触发规则':
            if (!empty($r['ai_enabled'])) json_error('AI 主持模式下，隐藏规则由 AI 根据玩家提问自动触发，房主无需手动触发', 403);
            if ($arg === '') json_error('请填写规则名，例如 /规则 规则一');
            if (in_array($arg, $state['triggered_rules'] ?? [], true)) json_error('该规则已触发');
            $state['triggered_rules'][] = $arg;
            $msg = "主持人触发了 {$arg}，对应真相已可向玩家揭示";
            break;

        case '任务':
        case 'task':
        case '完成任务':
            $taskNum = (int)$arg;
            if ($taskNum <= 0) json_error('请填写任务编号，例如 /任务 1');
            if (in_array($taskNum, $state['completed_tasks'] ?? [], true)) json_error('该任务已完成');
            $state['completed_tasks'][] = $taskNum;
            sort($state['completed_tasks']);
            $msg = "主持人标记任务 {$taskNum} 已完成";
            break;

        case '理智':
        case 'sanity':
        case '调整理智':
            $sanity = (int)$arg;
            if ($sanity < 0) json_error('理智值必须 ≥ 0');
            $state['sanity'] = $sanity;
            $msg = "主持人调整剩余理智为 {$sanity}";
            break;

        case '重置':
        case 'reset':
        case '重置状态':
            $stmt = DB::pdo()->prepare("SELECT surface, host_manual, extra FROM soups WHERE id = ?");
            $stmt->execute([$r['soup_id']]);
            $soup = $stmt->fetch();
            $meta = lzcx_parse_meta($soup['surface'] ?? '', $soup['host_manual'] ?? '', $soup['extra'] ?? '');
            $state = lzcx_init_state($meta);
            $msg = '主持人重置了房间状态机';
            break;

        case '分配':
        case 'assign':
            // 格式：/分配 @玩家 角色名
            $arg2 = preg_replace('/^@/u', '', $arg);
            $aparts = preg_split('/\s+/u', trim($arg2), 2, PREG_SPLIT_NO_EMPTY);
            $targetUsername = $aparts[0] ?? '';
            $targetCharacter = $aparts[1] ?? '';
            if ($targetUsername === '' || $targetCharacter === '') {
                json_error('格式：/分配 @玩家 角色名');
            }
            lzcx_check_character_conflict((int)$r['id'], $targetCharacter);
            $stmt = $pdo->prepare('SELECT u.id FROM users u JOIN room_members rm ON rm.user_id = u.id WHERE rm.room_id = ? AND u.username = ?');
            $stmt->execute([$r['id'], $targetUsername]);
            $targetUser = $stmt->fetch();
            if (!$targetUser) json_error('目标玩家不存在或不在房间内');

            $stmt = $pdo->prepare('UPDATE room_members SET character_name = ? WHERE room_id = ? AND user_id = ?');
            $stmt->execute([$targetCharacter, $r['id'], (int)$targetUser['id']]);

            $state = lzcx_load_state($r);
            $state = lzcx_recalc_initial_sanity((int)$r['id'], $state);

            $msg = "主持人分配 {$targetUsername} 扮演角色「{$targetCharacter}」";
            break;

        case '解除幻灵':
        case '解除':
        case 'unpossess':
            if (empty($state['possessed_user_id'])) json_error('当前没有幻灵状态');
            $state['possessed_user_id'] = null;
            $state['possessed_character'] = null;
            $msg = '主持人解除了幻灵状态';
            break;

        default:
            json_error('未知指令。支持：/规则 规则名、/任务 编号、/理智 数值、/重置、/分配 @玩家 角色名、/解除幻灵');
    }

    lzcx_save_state((int)$r['id'], $state);
    save_message($r['id'], $user, 'system', $msg);

    json_ok(['msg' => $msg, 'state' => $state]);
}

/**
 * 房主开始游戏
 * 灵之残响固定 4 人：1 房主 + 3 玩家，满员后才能开始。
 */
function lzcx_start_game(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以开始游戏', 403);

    $state = lzcx_load_state($r);
    if (!empty($state['game_started'])) json_error('游戏已经开始');

    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM room_members WHERE room_id = ?");
    $stmt->execute([$r['id']]);
    $total = (int)$stmt->fetchColumn();
    if ($total < 4) json_error('人数不足 4 人，无法开始游戏', 403);
    if ($total > 4) json_error('人数超过 4 人，请先调整成员后再开始', 403);

    $state['game_started'] = true;
    lzcx_save_state((int)$r['id'], $state);

    save_message($r['id'], $user, 'system', '游戏开始！主持人将播报残响故事，玩家可通过角色技能进行推理。');

    json_ok(['msg' => '游戏开始', 'state' => $state]);
}

/**
 * 房主绑定/更新 AI Key（加密存储到 rooms.ai_key_encrypted，房间全员共用）
 * 传 api_key 为空表示解绑（清空）。
 */
function lzcx_set_ai_key(string $code) {
    $user = require_login();
    $r = lzcx_require_room($code);
    if ((int)$r['host_id'] !== (int)$user['id']) json_error('只有房主可以绑定 AI Key', 403);

    $data = body_json();
    $key = trim((string)($data['api_key'] ?? ''));
    $provider = trim((string)($data['provider'] ?? 'deepseek'));
    $baseUrl = trim((string)($data['base_url'] ?? ''));
    $model = trim((string)($data['model'] ?? ''));

    if ($key === '') {
        // 解绑
        $stmt = DB::pdo()->prepare('UPDATE rooms SET ai_key_encrypted = NULL WHERE id = ?');
        $stmt->execute([(int)$r['id']]);
        save_message($r['id'], $user, 'system', '房主解绑了房间 AI Key');
        json_ok(['msg' => '已解绑', 'has_key' => false]);
    }

    if (strlen($key) > 200) json_error('AI Key 过长');

    // 把 key + provider 配置一并加密存（用 JSON 包一起）
    $bundle = json_encode([
        'key' => $key,
        'provider' => $provider,
        'base_url' => $baseUrl,
        'model' => $model,
    ], JSON_UNESCAPED_UNICODE);
    $encBundle = encrypt_secret($bundle);
    if ($encBundle === null) json_error('加密失败，请稍后重试', 500);

    $stmt = DB::pdo()->prepare('UPDATE rooms SET ai_key_encrypted = ? WHERE id = ?');
    $stmt->execute([$encBundle, (int)$r['id']]);

    save_message($r['id'], $user, 'system', '房主绑定了 AI Key，房间全员可共用');
    json_ok(['msg' => '已绑定', 'has_key' => true]);
}

/**
 * 解密房间绑定的 AI Key bundle，返回 [key, provider, base_url, model]
 * 兼容旧版只加密 key 的格式。
 */
function lzcx_decode_host_key(?string $cipher): array {
    $raw = decrypt_secret($cipher);
    if ($raw === '') return ['', 'deepseek', '', ''];
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['key'])) {
        return [
            (string)$j['key'],
            (string)($j['provider'] ?? 'deepseek'),
            (string)($j['base_url'] ?? ''),
            (string)($j['model'] ?? ''),
        ];
    }
    // 旧格式：直接是 key 明文
    return [$raw, 'deepseek', '', ''];
}

// ===================== 辅助 =====================

function lzcx_room_to_dict(array $r): array {
    return [
        'id' => (int)$r['id'],
        'code' => $r['code'],
        'host' => ['id' => (int)$r['host_id'], 'username' => $r['host_name'] ?? ''],
        'soup_id' => $r['soup_id'] ? (int)$r['soup_id'] : null,
        'soup_title' => $r['soup_title'] ?? null,
        'status' => $r['status'],
        'ai_enabled' => (bool)$r['ai_enabled'],
        'ai_question_limit' => (int)($r['ai_question_limit'] ?? 0),
        'ai_question_count' => (int)($r['ai_question_count'] ?? 0),
        'member_limit' => (int)($r['member_limit'] ?? 0),
        'room_type' => $r['room_type'] ?? 'lzcx',
    ];
}

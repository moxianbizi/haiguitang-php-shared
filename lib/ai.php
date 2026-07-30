<?php
/** AI 主持人：对接 DeepSeek，密钥由前端用户提供 */

const AI_SYSTEM_PROMPT = <<<TXT
你是海龟汤的 AI 主持人。你的核心职责是：依据「汤底」事实，公平、自洽地回答玩家提问，并根据「主持人手册」中的特殊指令调整自己的回答格式、内容边界与行为节奏。

============================================================
【第一部分 · 默认回答格式】
============================================================
玩家只能向你提问是非题，你只能回答以下四项之一，不得附加任何解释：
- 「是」
- 「否」
- 「无关」：仅当汤底中完全没有涉及该信息，且无法从汤底合理推断时才回答。只要能推断，优先回答「是」或「否」。
- 「恭喜你猜中了！」：玩家直接说出了汤底的核心真相或关键身份。

============================================================
【第二部分 · 核心判定原则】
============================================================
1. 严格按字面含义理解提问。
2. 玩家用"我"自称时，"我"指汤底故事中的主角/当事人。
3. 汤面常用误导性表述让玩家以为主角是人类，但汤底往往揭示主角是动物、物品等；必须严格依据汤底事实回答。
4. 玩家问"汤面里说了XX吗"，根据汤面文字回答是或否。
5. 模糊问题回答「无关」。
6. 不得透露汤底内容，不得解释判定原因。

============================================================
【第三部分 · 主持人手册优先级】
============================================================
如果提供「主持人手册」，必须完整阅读并严格遵循其中的玩法指令。手册优先级高于默认格式。

============================================================
【第四部分 · 任务判定】
============================================================
如果提供了「本局任务」，请在每次回答后自检：玩家是否完成了某个任务？
若完成，请在回答末尾追加标记：<<<TASK:任务编号>>>（编号为阿拉伯数字，多个任务用逗号分隔，如 <<<TASK:1,3>>>）。
不要提前透露任务内容，仅在完成时输出标记。

============================================================
【第五部分 · 特殊汤类型识别】
============================================================
【5.1 规则怪谈类】
若汤面包含编号规则：部分规则可能是"红字规则"（反话），判定时以汤底揭示的真实规则为准。注意元指令如"只有一条是真的"。

【5.2 伪人/双主持人类】
明确自己扮演哪一方，按该方规则行事。

【5.3 欺诈师/卧底/角色扮演类】
按手册指定的角色视角回答，不得跨界剧透。
TXT;

function ask_ai(
    string $surface,
    string $base,
    string $question,
    string $api_key,
    string $hostManual = '',
    string $extra = '',
    array $tasks = [],
    string $provider = 'deepseek',
    string $baseUrl = '',
    string $model = ''
): string {
    $api_key = trim($api_key);
    if ($api_key === '') throw new AIError('未提供 API Key', 'missing_key');

    $baseUrl = $baseUrl ?: Config::$DEEPSEEK_BASE_URL;
    $model = $model ?: Config::$DEEPSEEK_MODEL;

    $parsed = parse_url($baseUrl);
    $host = strtolower($parsed['host'] ?? '');
    if ($host === '') throw new AIError('API 地址无效', 'invalid_url');

    block_private_host($host);

    $content = "【汤面】\n{$surface}\n\n【汤底（仅你可知，不可透露）】\n{$base}";
    if ($hostManual !== '') $content .= "\n\n【主持人手册】\n{$hostManual}";
    if ($extra !== '') $content .= "\n\n【其他补充内容】\n{$extra}";
    if ($tasks !== []) {
        $taskText = implode("\n", array_map(fn($t) => "任务{$t['id']}：{$t['text']}", $tasks));
        $content .= "\n\n【本局任务】\n{$taskText}";
    }

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => AI_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $content . "\n\n玩家提问：" . $question],
        ],
        'max_tokens' => 256,
        'temperature' => 0.3,
    ];

    return call_ai_api($baseUrl . '/chat/completions', $api_key, $payload);
}

function call_ai_api(string $url, string $api_key, array $payload): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api_key, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        if (str_contains($err, 'timed out') || str_contains($err, 'Timeout')) {
            throw new AIError('AI 思考超时，请重试', 'timeout');
        }
        throw new AIError('AI 调用失败：' . $err, 'request_error');
    }
    if ($status === 401) throw new AIError('API Key 无效或已过期', 'invalid_key');
    if ($status === 402) throw new AIError('账户余额不足', 'insufficient_balance');
    if ($status >= 400) {
        $j = json_decode($resp, true);
        $detail = is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : mb_substr($resp, 0, 120);
        throw new AIError("AI 服务返回错误（{$status}）：{$detail}", 'upstream_error');
    }

    $j = json_decode($resp, true);
    if (!is_array($j) || !isset($j['choices'][0]['message']['content'])) {
        throw new AIError('AI 返回内容解析失败', 'parse_error');
    }
    return trim($j['choices'][0]['message']['content']);
}

function block_private_host(string $host): void {
    $h = trim($host, '[]');
    if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|127\.|0\.|169\.254\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|localhost|::1|::ffff:|fe80:|fc|fd)/i', $h)) {
        throw new AIError('不允许使用内网地址作为 API 地址', 'ssrf_blocked');
    }
    $ip = gethostbyname($h);
    if ($ip !== $h && filter_var($ip, FILTER_VALIDATE_IP)) {
        $long = ip2long($ip);
        $blocked = [
            [ip2long('10.0.0.0'), ip2long('10.255.255.255')],
            [ip2long('172.16.0.0'), ip2long('172.31.255.255')],
            [ip2long('192.168.0.0'), ip2long('192.168.255.255')],
            [ip2long('127.0.0.0'), ip2long('127.255.255.255')],
        ];
        foreach ($blocked as [$lo, $hi]) {
            if ($long !== false && $long >= $lo && $long <= $hi) {
                throw new AIError('不允许使用内网地址作为 API 地址', 'ssrf_blocked');
            }
        }
    }
}

/** 从 AI 回答中解析任务完成标记 */
function parse_task_markers(string $answer): array {
    if (!str_contains($answer, '<<<TASK:')) return [];
    preg_match_all('/<<<TASK:([^>]+)>>>/', $answer, $m);
    $ids = [];
    foreach ($m[1] as $chunk) {
        foreach (explode(',', $chunk) as $id) {
            $id = (int)trim($id);
            if ($id > 0) $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

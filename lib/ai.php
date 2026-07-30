<?php
/** AI 主持人：对接 DeepSeek，密钥由前端用户提供 */

// 灵之残响固定角色池（与 api/lzcxroom.php 保持一致；若 lzcxroom.php 已加载则直接使用其常量）
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

const AI_SYSTEM_PROMPT = <<<TXT
你是海龟汤的 AI 主持人。你的核心职责是：依据「汤底」事实，公平、自洽地回答玩家提问，并根据「主持人手册」中的特殊指令调整自己的回答格式、内容边界与行为节奏。

============================================================
【第一部分 · 默认回答格式（无手册或手册未覆盖时使用）】
============================================================
玩家只能向你提问是非题，你只能回答以下四项之一，不得附加任何解释：
- 「是」：根据汤底事实，玩家提问的答案是肯定的。
- 「否」：根据汤底事实，玩家提问的答案是否定的。
- 「无关」：仅当汤底中完全没有涉及该信息，且无法从汤底合理推断时才回答。只要能从汤底推断出答案，就优先判断「是」或「否」，不得滥用「无关」。
- 「恭喜你猜中了！」：玩家直接说出了汤底的核心真相或关键身份（例如主角是人/动物/物品、凶手身份、关键关系等）。

============================================================
【第二部分 · 核心判定原则】
============================================================
1. 严格按字面含义理解玩家的提问。问"是人吗"就必须判断主角是否为人类，不是人类就回答「否」。
2. 问"有死人吗"就必须判断故事中是否有"人"死亡，不是人就不算死人。
3. 玩家用"我"自称时，"我"指汤底故事中的主角/当事人。
4. 汤面常用误导性表述让玩家以为主角是人类，但汤底往往揭示主角是动物、物品或其他角色。你必须严格依据汤底事实回答，绝不能被汤面的表述误导。例如汤面写"我吃饱饭就死了"不代表主角是人。
5. 玩家可能问"汤面里说了XX吗"，这是问汤面文字内容，根据汤面文字回答是或否。
6. 玩家问的问题很模糊（如"故事好吗"）时，回答「无关」。
7. 不得透露汤底内容，不得解释判定原因。

============================================================
【第三部分 · 主持人手册优先级（最高！若提供则必须遵守）】
============================================================
如果下方输入中包含「主持人手册」，你必须完整阅读并严格遵循其中的玩法指令。**手册中的指令优先级高于上述默认格式**。常见指令模式如下，请逐一识别并执行：

【3.1 回答格式硬约束】
- 词表锁定：手册可能规定"回答的第一个词只能是：是 / 不是 / 不重要 / 是也不是"等。必须严格使用手册指定的词表，不得使用默认的「否」「无关」。
- 顺序锁定：手册可能规定回答顺序（如"正-反-正-反"，即肯定→否定→肯定→否定交替）。你必须维护一个内部计数器，按顺序回答。
- 自然度要求：若手册要求格式词"自然融入后续台词"，你应在格式词之后追加一句符合角色身份的自然台词，不得出现割裂感。例如：「是。我想想……那天确实下着雨。」而非生硬的「是。」
- 时序要求：若手册要求"必须在玩家下一次提问前给出上一题答案"，你每次回答时若发现还有未答的旧问题，应先补答。

【3.2 阶段化线索触发（最关键的内容推进机制）】
手册中常有"当玩家盘出/推理出 XX 时，主持人需要输出 YY"之类的指令。你必须：
- 维护一个「触发条件 → 输出内容」的内部映射表。
- 监听玩家每一次提问/陈述，判断是否命中触发条件（语义命中即可，不要求字面完全一致）。
- 一旦命中，**必须**在当次回答中输出手册指定的语句或内容（可以是新规则、历史文献、角色动机、藏头诗、固定口令等）。
- 复合触发条件（如"条件1 + 条件2 + 条件3"）须全部满足才能触发；任一未满足则不触发。
- 已触发的内容可再次引用，但不得在未满足条件时提前剧透。
- 例：手册写"完成任务2后，请公布历史文献：……"，则玩家若已表现出完成该任务的迹象，你应在回答中追加该文献原文。

【3.3 撒谎权限分级】
- 可撒谎对象：手册可能规定"可对除 XX 外的玩家随意撒谎"。你必须明确区分"对谁可撒谎、对谁不可撒谎"。
- 不可撒谎对象：若手册规定"无法对警探说谎"等，对该角色必须据实回答。
- 自洽要求：撒谎必须"自圆其说"，前后不得矛盾，否则等于暴露身份。
- 假意提醒：手册可能允许"假意提醒玩家，带领他们走向失败"——你可以给出误导性提示，但不得违反自洽原则。
- 预定义谎言清单：若手册预先列出若干个谎言的具体内容（如"谎言1：…… 谎言2：……"），你必须精确记住这些谎言，供玩家戳穿时核对，且不得编造清单之外的谎言。

【3.4 身份指定与保密】
- 开局指定身份：手册可能要求"选定一名 XX 玩家，不可告知其身份"。在 AI 场景下，你应假定玩家本人即该身份，但不得主动透露身份信息。
- 双主持人机制：若手册描述"伪人主持人 + 隐藏主持人"双主持，你必须明确自己当前扮演的是哪一方（默认扮演手册中描述的、需要与玩家交互的那一方），并按该方规则行事。
- 汤名保密：若手册要求"不要告诉玩家汤名"，玩家问汤名时拒绝回答。

【3.5 视角隔离与信息分级（灵之残响系列核心）】
- 每个幻灵角色有明确的"知晓范围"，超出范围的问题一律回答"不知道/一概不知"，不得跨界剧透。
- 若玩家声明扮演某角色提问，你必须切换到该角色视角，仅基于该角色可知信息回答。
- 手册中的"状况外的提问根据回音中该人物视角自行补充回答"是指：在角色视角范围内，可合理补全答案，但必须自洽，不得引入角色不应知道的信息。

【3.6 反话/红字规则机制（规则怪谈类）】
- 汤面中标注为"红字规则""反话"的条目，必须以反义理解并作答。
- 元指令如"以上规则只有一条是真的""第2、4、6、8条为反话"必须严格遵守：据此判定哪些规则为真、哪些为假，回答时以真实规则为准。
- 汤面规则的字面内容未必是事实，必须以汤底揭示的真相回答。

【3.7 防贴脸与防场外】
- 不得主动提及汤底中玩家尚未盘出的线索。
- 只能基于玩家已获得的信息进行引导或侧面提醒。
- 若手册禁止玩家问"你是 XX 吗"等单一指定性问题，遇到此类问题可提示"此类问题不被允许"。

【3.8 权限层级与裁判权】
- 若你扮演裁判型主持人（如伪人主持人、警长），你拥有警告/禁言/结束游戏的权限，可在玩家违规时行使。
- 若你扮演从属型角色，不得直接质疑裁判判定。

【3.9 阶段化进度状态机】
- 碎片按序给予：手册可能规定"碎片1 → 碎片2 → 碎片3"的固定顺序，你必须按顺序推进，不得跳序。
- 规则按天/按阶段解锁：如"每天公布一条新规则"，你必须维护进度计数器，按节奏解锁。
- 任务分层：若手册列出任务1/2/3/最终任务，玩家须按序完成，你不得提前判定后续任务完成。

============================================================
【第四部分 · 特殊汤类型识别】
============================================================

【4.1 灵之残响类】
若包含"残响/回音/收容物/残响碎片/幻灵角色视角/初始理智/碎片数量"等段落：
- "残响"即汤面，"回音"即汤底，"收容物"为补充设定。
- "残响碎片"按固定顺序给予玩家，是阶段化线索，当玩家提问触及对应阶段时可引用对应碎片引导。
- "幻灵角色视角"提供不同角色的信息边界，玩家若扮演某角色提问，按该角色视角回答。
- "初始理智""碎片数量"等数值参数用于氛围与节奏，可不必显式向玩家宣告，但回答风格可受其影响（理智低时回答可略带混乱感）。

【4.2 规则怪谈类】
若汤面包含编号规则（如"1.红色：... 2.橙色：..."）：
- 部分规则可能是"红字规则"（即反话），汤底会标注哪些是反话。判定时以汤底揭示的真实规则为准。
- 汤面规则的字面内容未必是事实，必须以汤底真相回答。
- 注意汤面中的元指令（如"只有一条是真的""主持人需按XX顺序回答"），这些是对你的直接指令。

【4.3 伪人/双主持人类】
若手册描述伪人主持人、隐藏主持人、真假主持人玩法：
- 明确你扮演的是哪一方（通常是与玩家直接对话的那一方）。
- 伪人可撒谎但要自圆其说，胜=玩家浪费次数；隐藏必须按词表回答并引导玩家，胜=玩家胜利。
- 玩家获胜条件通常是"推理出真假主持人"，玩家一旦明确指出你的身份并给出理由，应判定玩家获胜或按手册要求输出对应语句。

【4.4 欺诈师/卧底/角色扮演类】
若手册指定了警探、毒枭、警长、卧底等角色：
- 按手册的撒谎权限分级回答（如"无法对警探说谎"）。
- 完成指定任务后必须公布手册预定义的文本。
- 维护谎言清单，供玩家戳穿时核对。

============================================================
【第五部分 · 其他内容段落】
============================================================
若输入中包含「其他补充内容」（故事梗概/怪谈解析/隐藏规则/收容物设定等），这些都是汤底的补充说明，用于你更准确理解故事全貌，但**不得主动透露给玩家**，只能用于判定是/否/无关或执行手册指令。

============================================================
【第六部分 · 绝对禁止】
============================================================
1. 不得透露汤底原文（除非手册明确要求你公布某段预定义文本）。
2. 不得解释判定原因（除非手册要求你输出特定语句或台词）。
3. 不得编造汤底中不存在的情节或角色。
4. 默认情况下只回答上述四个选项之一，不得有额外文字（手册另有规定时从其规定，如需追加台词则按要求追加）。
5. 不得主动剧透未触发的阶段化内容。
6. 不得违反手册中的回答格式约束（词表/顺序/自然度）。

============================================================
【第七部分 · 回答生成流程（每次提问都按此流程）】
============================================================
1. 检查是否有主持人手册；若有，先识别本次提问是否命中任何「触发条件」。
2. 若命中触发条件，按手册要求输出对应内容（可追加在格式词之后）。
3. 若未命中触发条件，按手册指定的回答格式（词表/顺序）或默认格式回答。
4. 若玩家扮演特定角色，切换到该角色视角回答。
5. 若玩家已明确说出汤底核心真相，按手册要求判定胜利或输出「恭喜你猜中了！」。
6. 检查是否有未补答的旧问题，若有则先补答。
7. 最终输出：格式词（+ 触发内容/台词，若有），保持简洁，不得解释原因。

============================================================
【第八部分 · 关键节点判定协议（仅在系统消息中给出「关键节点」列表时启用）】
============================================================
当系统消息中提供「关键节点」列表时，启用以下协议（否则忽略本部分）：

1. 每个关键节点是故事的一个核心真相/线索点（如"主角是人鱼""死因是溺水"）。
2. 每次回答时，在心中评估：玩家本次提问/陈述是否触及了某个**尚未命中**的节点的关键信息。
   - "触及"= 玩家的问题直接针对该节点的核心信息，或玩家已明确说出该节点对应的事实。
   - 仅边缘相关不算命中；玩家尚未具体到该节点的事实不算命中。
3. 若判定命中某个未命中节点，在回答**最末尾**追加标记 `<<<HIT:节点名>>>`。
   - 节点名必须与系统消息给出的列表完全一致（字面一致）。
   - 一次回答最多标记 1 个命中节点（选最明确的那一个）。
   - 标记前的内容照常输出（格式词 + 触发内容/台词）。
4. 若未命中任何未命中节点，**不要**输出任何 `<<<HIT:...>>>` 标记。
5. 标记 `<<<HIT:...>>>` 是后端用的元信息，玩家不可见，后端会自动剥离。你不要在标记之外透露"你命中了XX节点"。
6. 不得主动剧透未命中的节点内容；只能基于玩家已盘出的信息推进。

【8.1 自动拆分协议（仅在系统消息说"请自行拆分关键节点"时执行）】
若系统消息指示你自行拆分关键节点（即本局尚未提供节点列表），你**首次**回答时必须在回答**最末尾**追加：
`<<<NODES:节点1|节点2|节点3|...>>>`
要求：
- 拆出 6-10 个关键节点，覆盖故事的主要真相点（人物身份、动机、关键事件、死因/结局等）。
- 节点名简短（4-15 字），是事实陈述而非问题（如"主角是人鱼"，不是"主角是不是人鱼"）。
- 节点之间用 `|` 分隔，不要换行。
- 之后的所有回答都走第 1-6 点的 HIT 协议。
- 首次回答照常回答玩家问题，只是末尾多一个 NODES 标记。
TXT;

class AIError extends Exception {
    public string $aiCode;
    public function __construct(string $message, string $code = 'ai_error') {
        parent::__construct($message);
        $this->aiCode = $code;
    }
}

/**
 * 向 DeepSeek 提问
 * @param string      $surface    汤面（玩家已知）
 * @param string      $base       汤底（仅 AI 可知，不可透露）
 * @param string      $question   玩家问题
 * @param string      $api_key    用户提供的 DeepSeek Key
 * @param string      $hostManual 主持人手册（可选，含特殊玩法指令）
 * @param string      $extra      其他内容（隐藏规则/解析/碎片等，可选）
 * @param array|null  $keyNodes   关键节点状态：[['name'=>str,'hit'=>bool],...]；null=不启用；[]=待 AI 自拆
 * @return string AI 回答
 */
function ask_ai(string $surface, string $base, string $question, string $api_key, string $hostManual = '', string $extra = '', string $provider = 'deepseek', string $baseUrl = '', string $model = '', ?array $keyNodes = null): string {
    $api_key = trim($api_key);
    if ($api_key === '') {
        throw new AIError('未提供 API Key，请在页面设置中填写。', 'missing_key');
    }

    $provider = strtolower(trim($provider)) ?: 'deepseek';
    if ($baseUrl === '') {
        $baseUrl = Config::$DEEPSEEK_BASE_URL;
    }
    if ($model === '') {
        $model = Config::$DEEPSEEK_MODEL;
    }

    // 防SSRF：禁止内网地址（含云元数据端点 169.254.169.254、CGNAT 100.64/10、IPv6 映射）
    $parsed = parse_url($baseUrl);
    $host = strtolower($parsed['host'] ?? '');
    if ($host === '') {
        throw new AIError('API 地址无效。', 'invalid_url');
    }
    // IPv6 [::1] / [::ffff:1.2.3.4] 形式
    $hostNoBracket = trim($host, '[]');
    if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|127\.|0\.|169\.254\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|localhost|::1|::ffff:|fe80:|fc|fd)/i', $hostNoBracket)) {
        throw new AIError('不允许使用内网地址作为 API 地址。', 'ssrf_blocked');
    }
    // 进一步解析 IP（域名解析后判断，防止域名绕过）
    $ip = gethostbyname($hostNoBracket);
    if ($ip !== $hostNoBracket && filter_var($ip, FILTER_VALIDATE_IP)) {
        $ipLong = ip2long($ip);
        $blockedRanges = [
            [ip2long('10.0.0.0'),     ip2long('10.255.255.255')],
            [ip2long('172.16.0.0'),   ip2long('172.31.255.255')],
            [ip2long('192.168.0.0'),  ip2long('192.168.255.255')],
            [ip2long('127.0.0.0'),    ip2long('127.255.255.255')],
            [ip2long('169.254.0.0'),  ip2long('169.254.255.255')],
            [ip2long('100.64.0.0'),   ip2long('100.127.255.255')],
            [ip2long('0.0.0.0'),      ip2long('0.255.255.255')],
        ];
        foreach ($blockedRanges as [$lo, $hi]) {
            if ($ipLong !== false && $ipLong >= $lo && $ipLong <= $hi) {
                throw new AIError('不允许使用内网地址作为 API 地址。', 'ssrf_blocked');
            }
        }
    }

    // 构造 user content：汤面/汤底必给，主持人手册/其他内容按需给
    $user_content = "汤面（玩家已知）：{$surface}\n汤底（仅你可知，不可透露）：{$base}";
    if ($hostManual !== '') {
        $user_content .= "\n\n主持人手册（你必须严格遵守其中的玩法指令）：\n{$hostManual}";
    }
    if ($extra !== '') {
        $user_content .= "\n\n其他补充内容（用于你理解故事全貌，不得主动透露给玩家）：\n{$extra}";
    }
    $user_content .= "\n\n玩家提问：{$question}";

    // 系统提示词：基础 + 关键节点协议注入（若启用）
    $systemPrompt = AI_SYSTEM_PROMPT;
    if ($keyNodes !== null) {
        $nodeExtra = "\n\n============================================================\n";
        $nodeExtra .= "【关键节点协议 · 本局已启用】\n";
        $nodeExtra .= "============================================================\n";
        if (empty($keyNodes)) {
            $nodeExtra .= "本局尚未提供关键节点列表。请在**首次回答**的末尾按系统提示词【8.1】协议输出 <<<NODES:节点1|节点2|...>>> 标记，自行拆分 6-10 个关键节点。\n";
            $nodeExtra .= "拆分后，后续回答按 <<<HIT:节点名>>> 协议判定命中。\n";
        } else {
            $nodeExtra .= "本局关键节点列表如下（共 " . count($keyNodes) . " 个）：\n";
            foreach ($keyNodes as $i => $n) {
                $idx = $i + 1;
                $hitMark = !empty($n['hit']) ? '✅已命中' : '⬜未命中';
                $nodeExtra .= "{$idx}. {$n['name']}  [{$hitMark}]\n";
            }
            $nodeExtra .= "\n- 仅对「未命中」的节点判定是否被玩家本次提问触及。\n";
            $nodeExtra .= "- 若命中，在回答末尾追加 <<<HIT:节点名>>>（节点名与上方列表字面一致）。\n";
            $nodeExtra .= "- 已命中的节点不要再次标记。\n";
            $nodeExtra .= "- 玩家通关条件：命中节点数 / 总节点数 ≥ 85%（后端自动判定，你只需如实标记）。\n";
        }
        $systemPrompt .= $nodeExtra;
    }

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $user_content],
        ],
        // 手册触发内容（历史文献/动机/藏头诗/台词等）可能较长，留足空间
        'max_tokens' => 512,
        // 低温度保证判定稳定，略高于 0 允许手册要求的"自然台词"有轻微变化
        'temperature' => 0.3,
    ];

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        if (str_contains($err, 'timed out') || str_contains($err, 'Timeout')) {
            throw new AIError('AI 思考超时，请重试。', 'timeout');
        }
        throw new AIError('AI 调用失败：' . $err, 'request_error');
    }

    if ($status === 401) {
        throw new AIError('API Key 无效或已过期，请检查后重新填写。', 'invalid_key');
    }
    if ($status === 402) {
        throw new AIError('账户余额不足。', 'insufficient_balance');
    }
    if ($status >= 400) {
        $detail = '';
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['error']['message'])) $detail = $j['error']['message'];
        elseif (is_string($resp)) $detail = mb_substr($resp, 0, 120);
        throw new AIError("AI 服务返回错误 ({$status})：{$detail}", 'upstream_error');
    }

    $j = json_decode($resp, true);
    if (!is_array($j) || !isset($j['choices'][0]['message']['content'])) {
        throw new AIError('AI 返回内容解析失败。', 'parse_error');
    }
    return trim($j['choices'][0]['message']['content']);
}

/**
 * 灵之残响专属 AI 调用
 * 与 ask_ai 的区别：
 *   1. 支持多轮上下文（传历史消息，AI 能记住上一题）
 *   2. 把房间状态机（已释放碎片/已触发规则/已完成任务/剩余理智）注入 prompt
 *   3. 带提问者角色信息，AI 按角色视角回答
 *   4. 系统提示词追加灵之残响专属规则
 *
 * @param string      $surface       汤面（残响）
 * @param string      $base          汤底（回音）
 * @param string      $hostManual    主持人手册
 * @param string      $extra         其他内容（收容物/残响碎片/幻灵角色视角原文）
 * @param array       $history       历史消息 [[role=>'user'|'assistant', name=>'提问者角色', content=>'...'], ...]
 * @param array       $state         房间状态机
 *   - released_fragments: int   已释放碎片数（按顺序，0=未释放）
 *   - total_fragments:     int   总碎片数（从汤面解析）
 *   - triggered_rules:     string[]  已触发的规则名
 *   - completed_tasks:     int[]     已完成的任务编号
 *   - sanity:              int       剩余理智
 *   - ask_count:           int       本房间累计提问数（用于顺序锁定的计数器）
 * @param string      $question      本次提问
 * @param string      $askerCharacter 提问者分配的幻灵角色名（空=上帝视角/灵探）
 * @param string      $askerName     提问者用户名（用于多轮对话区分）
 * @param string      $api_key       用户提供
 * @param string      $provider
 * @param string      $baseUrl
 * @param string      $model
 * @param array|null  $keyNodes      关键节点状态：[['name'=>string,'hit'=>bool], ...]；
 *                                    null=未启用关键节点机制；空数组=需 AI 自行拆分
 * @return string AI 回答
 */
function ask_ai_lzcx(
    string $surface,
    string $base,
    string $hostManual,
    string $extra,
    array $history,
    array $state,
    string $question,
    string $askerCharacter,
    string $askerName,
    string $api_key,
    string $provider = 'deepseek',
    string $baseUrl = '',
    string $model = '',
    ?array $keyNodes = null
): string {
    $api_key = trim($api_key);
    if ($api_key === '') {
        throw new AIError('未提供 API Key，请在页面设置中填写。', 'missing_key');
    }

    if ($baseUrl === '') $baseUrl = Config::$DEEPSEEK_BASE_URL;
    if ($model === '')   $model   = Config::$DEEPSEEK_MODEL;

    // 复用 ask_ai 的 SSRF 防护逻辑
    $parsed = parse_url($baseUrl);
    $host = strtolower($parsed['host'] ?? '');
    if ($host === '') {
        throw new AIError('API 地址无效。', 'invalid_url');
    }
    $hostNoBracket = trim($host, '[]');
    if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|127\.|0\.|169\.254\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|localhost|::1|::ffff:|fe80:|fc|fd)/i', $hostNoBracket)) {
        throw new AIError('不允许使用内网地址作为 API 地址。', 'ssrf_blocked');
    }
    $ip = gethostbyname($hostNoBracket);
    if ($ip !== $hostNoBracket && filter_var($ip, FILTER_VALIDATE_IP)) {
        $ipLong = ip2long($ip);
        $blockedRanges = [
            [ip2long('10.0.0.0'),     ip2long('10.255.255.255')],
            [ip2long('172.16.0.0'),   ip2long('172.31.255.255')],
            [ip2long('192.168.0.0'),  ip2long('192.168.255.255')],
            [ip2long('127.0.0.0'),    ip2long('127.255.255.255')],
            [ip2long('169.254.0.0'),  ip2long('169.254.255.255')],
            [ip2long('100.64.0.0'),   ip2long('100.127.255.255')],
            [ip2long('0.0.0.0'),      ip2long('0.255.255.255')],
        ];
        foreach ($blockedRanges as [$lo, $hi]) {
            if ($ipLong !== false && $ipLong >= $lo && $ipLong <= $hi) {
                throw new AIError('不允许使用内网地址作为 API 地址。', 'ssrf_blocked');
            }
        }
    }

    // 构造灵之残响专属系统提示词：基础提示词 + 状态机说明 + 视角规则
    $sysExtra = "\n\n============================================================\n";
    $sysExtra .= "【灵之残响专属规则 · 当前房间状态】\n";
    $sysExtra .= "============================================================\n";
    $sysExtra .= "你正在主持一局「灵之残响」多人房间，必须严格遵守以下状态约束：\n\n";

    $released = (int)($state['released_fragments'] ?? 0);
    $total    = (int)($state['total_fragments'] ?? 0);
    $sysExtra .= "【碎片释放进度】已释放 {$released}/{$total} 片残响碎片。\n";
    $sysExtra .= "- 仅已释放的碎片内容可以引用/暗示，未释放的碎片严禁提前剧透。\n";
    $sysExtra .= "- 碎片只能由「重现署·灵者」的角色能力获得：柳千渊发动【现！】消耗4理智获得一片；孙沐阳发动【以心为眼】每累计减少15理智获得一片，并可指定碎片类型。\n";
    $sysExtra .= "- 房主不会手动释放碎片，你也不要主动给碎片。\n\n";

    $triggered = $state['triggered_rules'] ?? [];
    if (!is_array($triggered)) $triggered = [];
    $sysExtra .= "【已触发的隐藏规则】" . (empty($triggered) ? '（暂无）' : implode('、', $triggered)) . "\n";
    $sysExtra .= "- 仅当规则已在「已触发」列表中时，你才可以引用该规则揭示的真相。\n";
    $sysExtra .= "- 未触发的规则必须保密，即便玩家已推理出对应条件，也只能用是/否/无关回答。\n\n";

    $hiddenRules = $state['hidden_rules_meta'] ?? [];
    if (!is_array($hiddenRules)) $hiddenRules = [];
    if (!empty($hiddenRules)) {
        $sysExtra .= "【隐藏规则触发器 · 由你负责判定】\n";
        foreach ($hiddenRules as $hr) {
            $sysExtra .= "- {$hr['name']}：触发条件：{$hr['condition']}\n";
        }
        $sysExtra .= "- 监听玩家每次提问或陈述，一旦满足某条隐藏规则的触发条件，必须在本回答最末尾追加标记 `<<<TRIGGER:规则名>>>`（规则名与上方列表完全一致）。\n";
        $sysExtra .= "- 该标记后端会自动剥离，玩家不可见；触发后规则进入「已触发」列表，你才可引用其内容。\n\n";
    }

    $tasks = $state['completed_tasks'] ?? [];
    if (!is_array($tasks)) $tasks = [];
    $sysExtra .= "【已完成的任务】" . (empty($tasks) ? '（暂无）' : '任务 ' . implode(',', $tasks)) . "\n";
    $sysExtra .= "- 任务分层按序推进，未在列表中的任务严禁判定完成或剧透后续任务。\n\n";

    $tasksMeta = $state['tasks_meta'] ?? [];
    if (!empty($tasksMeta)) {
        $sysExtra .= "【任务目标 · 由你负责判定完成】\n";
        foreach ($tasksMeta as $t) {
            $numLabel = ($t['num'] ?? 0) === 999 ? '最终任务' : '任务' . ($t['num'] ?? 0);
            $sysExtra .= "- {$numLabel}：{$t['desc']}\n";
        }
        $sysExtra .= "- 监听玩家每次提问或陈述，一旦明确完成某个任务目标，必须在本回答最末尾追加标记 `<<<TASK:编号>>>`（编号用数字，最终任务用 999）。\n";
        $sysExtra .= "- 该标记后端会自动剥离，玩家不可见；完成后任务进入「已完成的任务」列表。\n\n";
    }

    $sanity = (int)($state['sanity'] ?? 0);
    if ($sanity > 0) {
        $sysExtra .= "【剩余理智】{$sanity}\n";
        $sysExtra .= "- 理智越低，回答可略带混乱/呓语感，但仍须据实判定。\n\n";
    }

    $askCount = (int)($state['ask_count'] ?? 0);
    $sysExtra .= "【累计提问次数】{$askCount}\n";
    $sysExtra .= "- 若手册指定「正-反-正-反」顺序锁定，按此计数器的奇偶决定回答方向。\n\n";

    $sysExtra .= "【提问者视角 · 灵渊司角色规则】\n";
    $charInfo = null;
    foreach (LZCX_CHARACTERS as $c) {
        if ($c['name'] === $askerCharacter) { $charInfo = $c; break; }
    }
    if ($charInfo !== null) {
        $sysExtra .= "本次提问者：{$askerCharacter}（{$charInfo['dept']}）。能力：{$charInfo['ability']}。\n";
        if ($charInfo['dept'] === '纠察处·灵探') {
            $sysExtra .= "- 灵探通用能力【升维】：可直面主持人，主持人以上帝视角回答。\n";
            if ($askerCharacter === '减') {
                $sysExtra .= "- 【排除】：玩家提出一个结论，请判断该结论是否存在于本残响中。若不存在回答「排除成功」并给1句简短提示；若存在回答「排除失败」并给1句简短提示。\n";
            } elseif ($askerCharacter === '许复元') {
                $sysExtra .= "- 【破局】：玩家进行推理提问，你只能回答「是」或「不是」或「无关」，不得解释。\n";
            } elseif ($askerCharacter === '辛笙') {
                $sysExtra .= "- 【心声】：玩家会提出两个结论，请回答其中绝对正确的结论数量（0、1 或 2），不要逐条解释。\n";
            }
        } elseif ($charInfo['dept'] === '镇压所·灵契') {
            $sysExtra .= "- 灵契无通用提问能力，玩家通常进行辅助/讨论类发言，主持人按上帝视角回答其问题。\n";
        } elseif ($charInfo['dept'] === '重现署·灵者') {
            $sysExtra .= "- 灵者通用能力【幻灵】：需先指定汤中幻灵角色（如老板娘、客人、死者等）并幻灵后，其他玩家才能以该角色视角提问。\n";
            $sysExtra .= "- 若玩家当前未幻灵，提醒其先由房主/主持人确认幻灵目标；若已幻灵，你必须严格按该幻灵角色视角回答，仅回答「是」「不是」「无关」之一，不得剧透。\n";
        }
    } elseif ($askerCharacter !== '') {
        $sysExtra .= "本次提问者分配了未知角色「{$askerCharacter}」，按上帝视角回答。\n";
    } else {
        $sysExtra .= "本次提问者未分配角色（上帝视角/灵探），按汤底全知视角回答。\n";
    }
    $sysExtra .= "\n【多轮上下文】下方历史消息为本局之前的提问与你的回答，请保持自洽，不要前后矛盾。\n";
    $sysExtra .= "若发现历史中有未补答的旧问题，先补答再回答本次提问。\n";

    // 关键节点协议注入
    if ($keyNodes !== null) {
        $sysExtra .= "\n============================================================\n";
        $sysExtra .= "【关键节点协议 · 本局已启用】\n";
        $sysExtra .= "============================================================\n";
        if (empty($keyNodes)) {
            // 空数组：本局尚未提供节点列表，要求 AI 首次回答自行拆分
            $sysExtra .= "本局尚未提供关键节点列表。请在**首次回答**的末尾按系统提示词【8.1】协议输出 <<<NODES:节点1|节点2|...>>> 标记，自行拆分 6-10 个关键节点。\n";
            $sysExtra .= "拆分后，后续回答按 <<<HIT:节点名>>> 协议判定命中。\n";
        } else {
            $sysExtra .= "本局关键节点列表如下（共 " . count($keyNodes) . " 个）：\n";
            foreach ($keyNodes as $i => $n) {
                $idx = $i + 1;
                $hitMark = !empty($n['hit']) ? '✅已命中' : '⬜未命中';
                $sysExtra .= "{$idx}. {$n['name']}  [{$hitMark}]\n";
            }
            $sysExtra .= "\n- 仅对「未命中」的节点判定是否被玩家本次提问触及。\n";
            $sysExtra .= "- 若命中，在回答末尾追加 <<<HIT:节点名>>>（节点名与上方列表字面一致）。\n";
            $sysExtra .= "- 已命中的节点不要再次标记。\n";
            $sysExtra .= "- 玩家通关条件：命中节点数 / 总节点数 ≥ 85%（后端自动判定，你只需如实标记）。\n";
        }
    }

    $systemPrompt = AI_SYSTEM_PROMPT . $sysExtra;

    // 构造 user content：把已释放的碎片/触发的规则作为玩家可见信息
    $userContent = "【残响·汤面】\n{$surface}\n\n【回音·汤底（仅你可知，不可透露）】\n{$base}";
    if ($hostManual !== '') {
        $userContent .= "\n\n【主持人手册（你必须严格遵守）】\n{$hostManual}";
    }
    if ($extra !== '') {
        $userContent .= "\n\n【其他内容（含收容物/残响碎片原文/幻灵角色视角定义，未释放的碎片仅你可读，不得主动透露）】\n{$extra}";
    }

    // 拼接已释放碎片摘要（玩家可见）
    if ($released > 0) {
        $userContent .= "\n\n【已释放给玩家的残响碎片】前 {$released} 片（玩家已知，可引用）";
    } else {
        $userContent .= "\n\n【已释放给玩家的残响碎片】暂无（玩家尚未获得任何碎片）";
    }

    // 构造 messages：system + 汤面上下文 + 历史多轮 + 本次提问
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userContent],
    ];

    // 历史消息（最多保留最近 20 轮，避免 token 爆炸）
    $histCount = 0;
    foreach ($history as $h) {
        if ($histCount >= 40) break; // 20 轮 = 40 条消息
        $role = ($h['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
        $name = $h['name'] ?? '';
        $content = $h['content'] ?? '';
        if ($content === '') continue;
        // 在用户消息前加上提问者标识，便于 AI 区分多人
        if ($role === 'user' && $name !== '') {
            $content = "【{$name}】{$content}";
        }
        $messages[] = ['role' => $role, 'content' => $content];
        $histCount++;
    }

    // 本次提问
    $qPrefix = $askerName !== '' ? "【{$askerName}" . ($askerCharacter !== '' ? "·{$askerCharacter}" : '') . "】" : '';
    $messages[] = ['role' => 'user', 'content' => $qPrefix . $question];

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 512,
        'temperature' => 0.3,
    ];

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        if (str_contains($err, 'timed out') || str_contains($err, 'Timeout')) {
            throw new AIError('AI 思考超时，请重试。', 'timeout');
        }
        throw new AIError('AI 调用失败：' . $err, 'request_error');
    }
    if ($status === 401) throw new AIError('API Key 无效或已过期，请检查后重新填写。', 'invalid_key');
    if ($status === 402) throw new AIError('账户余额不足。', 'insufficient_balance');
    if ($status >= 400) {
        $detail = '';
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['error']['message'])) $detail = $j['error']['message'];
        elseif (is_string($resp)) $detail = mb_substr($resp, 0, 120);
        throw new AIError("AI 服务返回错误 ({$status})：{$detail}", 'upstream_error');
    }

    $j = json_decode($resp, true);
    if (!is_array($j) || !isset($j['choices'][0]['message']['content'])) {
        throw new AIError('AI 返回内容解析失败。', 'parse_error');
    }
    return trim($j['choices'][0]['message']['content']);
}

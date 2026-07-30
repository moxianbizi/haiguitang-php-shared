<?php
/**
 * 解析海龟汤 Markdown 文件（颜色版格式）
 *
 * 支持的段落标记：
 *   - surface      汤面 / 残响（汤面）/ 残响 / 汤面规则
 *   - base         汤底 / 回音（汤底）/ 回音 / 怪谈解析
 *   - host_manual  主持人手册
 *   - extra        收容物 / 残响碎片 / 幻灵角色视角 / 通关条件 / 隐藏规则 / 玩家获胜条件 / 胜利条件 / 提问次数 / 背景设定 / 附录 / 备注
 *
 * 图片路径转换：./海龟汤图片/xxx.jpeg → /soups-img/xxx.jpeg
 */

/**
 * 规则类汤面整理：
 *  1. 编号条目分行：汤面含 3+ 个数字编号条目（1. 2. 3. 或 1． 2． 或 1、2、）时，
 *     每条独占一行（marked breaks:true 把单换行转 <br> 渲染多行）
 *  2. 红字规则标记：若汤面尾部有「X、X为红字规则」说明，对应条目用 <em> 包裹
 *     （前端 em 渲染为红色规则），并移除说明括号
 *
 * 不处理的情形：
 *  - 编号是日期（如"10.1日"）或版本号等非条目：要求编号后紧跟中文内容
 *  - 条目少于 3 个：避免误拆普通正文
 *
 * @param string $surface 汤面原文
 * @return string 处理后的汤面
 */
function apply_red_rule_marker(string $surface): string {
    if ($surface === '') return $surface;

    // 先识别红字规则说明（如有），提取红字编号集合并移除说明括号
    $redNumbers = [];
    if (preg_match('/（[^）]*?([\d，、,\s]+)\s*为\s*红字规则[^）]*）/u', $surface, $m)) {
        if (preg_match_all('/\d+/u', $m[1], $nm)) {
            $redNumbers = array_unique($nm[0]);
        }
        $surface = preg_replace('/（[^）]*?[\d，、,\s]+\s*为\s*红字规则[^）]*）/u', '', $surface);
    }

    // 按条目编号切分：支持「1.」「1．」「1、」三种分隔符
    // 排除日期/版本号：编号后不能紧跟数字（如"10.1日"的".1"不匹配）
    // 允许编号后跟空格或直接跟中文/字母
    $pattern = '/(\d+)[.．、](?!\d)/u';
    if (!preg_match_all($pattern, $surface, $matches, PREG_OFFSET_CAPTURE)) {
        return $surface;
    }
    // 至少 3 个编号才算规则类汤面，避免误拆普通正文
    if (count($matches[0]) < 3) {
        return $surface;
    }

    // 安全护栏：若汤面含 ** 加粗或 | 表格语法，直接拆行会破坏 marked 的跨块闭合
    // （如「**通关条件：1. …；2. …；3. …**」单行被拆成多行后，** 跨段落/列表无法闭合，
    //   渲染出字面 **；表格单元格被拆成多行会破坏表格结构）。
    // 此时放弃分行与 <em> 红字标记，保留原文（红字说明已在上面移除）。
    if (str_contains($surface, '**') || str_contains($surface, '|')) {
        return $surface;
    }

    // 按编号位置切分成条目：每条 = 该编号到下一个编号之间的内容
    $items = [];
    $positions = $matches[0];
    $posCount = count($positions);
    // 保留第一个编号前的前导文字（如"我们...发现了一张纸条："）
    $preamble = trim(substr($surface, 0, $positions[0][1]));
    if ($preamble !== '') {
        $items[] = ['num' => '', 'text' => $preamble];
    }
    for ($i = 0; $i < $posCount; $i++) {
        $start = $positions[$i][1];
        $end = ($i + 1 < $posCount) ? $positions[$i + 1][1] : strlen($surface);
        $item = substr($surface, $start, $end - $start);
        // 提取编号数字
        preg_match('/^(\d+)/', $positions[$i][0], $im);
        $num = $im[1];
        $items[] = ['num' => $num, 'text' => trim($item)];
    }

    // 重建汤面：每条独占一行，命中红字编号的用 <em> 包裹
    $lines = [];
    foreach ($items as $item) {
        $text = $item['text'];
        if ($text === '') continue;
        if (in_array($item['num'], $redNumbers, true)) {
            $lines[] = "<em>{$text}</em>";
        } else {
            $lines[] = $text;
        }
    }
    return implode("\n", $lines);
}

function parse_md(string $filename, string $content): array {
    // 统一换行
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", rtrim($content));

    // 标题：首个 # 行，或第一行纯文本（颜色版格式无 # 前缀）
    $title = preg_replace('/\.md$/', '', $filename);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') continue;
        if (str_starts_with($t, '# ')) {
            $title = trim(substr($t, 2));
        } else {
            // 颜色版第一行就是标题（如 "S3E16《白雪公主规则怪谈》"）
            $title = $t;
        }
        // 剥离所有 HTML 标签（标题在前端按纯文本展示，
        // 颜色版可能用 <span style="color: blue;"> 包裹标题行，如 S3E41）
        $title = strip_tags($title);
        // 去掉书名号
        $title = preg_replace('/^《(.+)》$/', '$1', $title);
        // 去掉首尾的 ** 加粗标记（如 "**S3E42《教室》**" → "S3E42《教室》"）
        $title = preg_replace('/^\*\*(.+)\*\*$/', '$1', $title);
        break;
    }

    // 段落标记识别规则（顺序敏感：先匹配更具体的标记）
    // 颜色版格式：标记后可能直接跟内容，也可能用"（）"括号说明
    // 注意：通关条件/残响难度 紧跟汤面，作为 surface 的一部分，不单独识别
    $markers = [
        'surface'     => '/^(?:#{0,2}\s*)?(?:\*\*)?(?:汤面规则|残响\s*[（(]\s*汤面\s*[)）]|汤面(?=[：:（(\s]|$)|残响(?=[：:（(\s]|$))(?:\*\*)?(?:[（(][^)）]*[)）])?[：:\s]*/u',
        'base'        => '/^(?:#{0,2}\s*)?(?:\*\*)?(?:回音\s*[（(]\s*汤底\s*[)）]|怪谈解析|故事梗概|汤底|回音(?=[：:（(\s]|$))(?:\*\*)?(?:[（(][^)）]*[)）])?[：:\s]*/u',
        'host_manual' => '/^(?:#{0,2}\s*)?(?:\*\*)?(?:主持人手册|主持人须知|玩法说明)(?:\*\*)?(?:[（(][^)）]*[)）])?[：:\s]*/u',
        'extra'       => '/^(?:#{0,2}\s*)?(?:\*\*)?(?:残响碎片|幻灵角色视角|收容物|隐藏规则|玩家获胜条件|胜利条件|提问次数|背景设定|附录|备注|规则解析)(?:\*\*)?(?:[（(][^)）]*[)）])?[：:\s]*/u',
    ];

    // 预处理：把「汤面+汤底」合并标记拆成两行
    $lines = array_map(function($line) {
        $t = trim($line);
        if (preg_match('/^(#{0,2}\s*)汤面\s*[+＋&与和及]\s*汤底(.*)$/u', $t, $m)) {
            $prefix = $m[1] ?? '';
            $rest = $m[2] ?? '';
            return [$prefix . '汤面', $prefix . '汤底' . ltrim($rest)];
        }
        return [$line];
    }, $lines);
    $flat = [];
    foreach ($lines as $arr) foreach ($arr as $l) $flat[] = $l;
    $lines = $flat;

    // 按行扫描
    $sections = ['surface' => [], 'base' => [], 'host_manual' => [], 'extra' => []];
    $current = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        // 跳过标题行
        if (str_starts_with($trimmed, '# ') || $trimmed === $title) continue;
        if ($trimmed === '') {
            if ($current !== null) $sections[$current][] = '';
            continue;
        }

        // 尝试匹配段落起始标记
        $matched = false;
        // 先剥离行首的 <span ...> 标签（颜色版可能用蓝色 span 包裹标记，
        // 如 S3E41：<span style="color: blue;">汤面 第一天...</span>必须...）
        $spanOpen = '';
        if (preg_match('/^<span[^>]*>/u', $trimmed, $sm)) {
            $spanOpen = $sm[0];
        }
        $stripped = preg_replace('/^<span[^>]*>\s*/u', '', $trimmed);
        foreach ($markers as $key => $pattern) {
            if (preg_match($pattern, $stripped)) {
                $current = $key;
                // 标记行本身也可能带内容（如「汤面 我吃饱饭就死了」）
                $rest = preg_replace($pattern, '', $stripped);
                // 用正则去掉行首的标点/空白（ltrim 按 byte 处理，会破坏以 e3 80 开头的多字节字符如《）
                $rest = preg_replace('/^[\s：:）)*　]+/u', '', $rest);
                // 若原行首有 <span> 标签，把开标签加回去，保留完整的 span 结构用于颜色渲染
                // （span 可能只包裹标记行的一部分，中间有 </span> 闭合，需保留原结构）
                if ($rest !== '' && $spanOpen !== '') {
                    $rest = $spanOpen . $rest;
                }
                if ($rest !== '') $sections[$key][] = $rest;
                $matched = true;
                break;
            }
        }
        if ($matched) continue;

        // 未匹配标记的行：归属当前段落
        if ($current !== null) {
            $sections[$current][] = $line;
        } else {
            // 标题下方、首个标记前的内容，归到 extra（如"> 选自..."引用块）
            $sections['extra'][] = $line;
        }
    }

    // 清理首尾空行
    $clean = function (array $arr): string {
        return trim(implode("\n", $arr));
    };

    $surface    = $clean($sections['surface']);
    $base       = $clean($sections['base']);
    $hostManual = $clean($sections['host_manual']);
    $extra      = $clean($sections['extra']);

    // 兜底0：表格型汤（如 S3E42《教室》整篇就是一个 markdown 表格，
    // 表头行含"汤面"，数据行含"汤底"）。优先于通用兜底处理，
    // 避免被通用正则切成残缺片段。
    if ($surface === '' && $base === '' && $extra !== '' && preg_match('/^\s*\|/m', $extra)) {
        // 提取"汤面"行：| 汤面XXX | cell | cell |
        if (preg_match('/^\s*\|[^|\n]*汤面[^|\n]*\|(.+?)\|\s*$/mu', $extra, $ms)) {
            $surface = trim($ms[1], "| \t\n");
        }
        // 提取"汤底"行：| 汤底 | cell | cell |
        if (preg_match('/^\s*\|[^|\n]*汤底[^|\n]*\|(.+?)\|\s*$/mu', $extra, $mb)) {
            $base = trim($mb[1], "| \t\n");
        }
        // 提取"主持人手册"行（如有）
        if (preg_match('/^\s*\|[^|\n]*主持人手册[^|\n]*\|(.+?)\|\s*$/mu', $extra, $mh)) {
            $hostManual = trim($mh[1], "| \t\n");
        }
        // 若成功提取出 surface 或 base，清空 extra（避免重复展示整张表）
        if ($surface !== '' || $base !== '') {
            $extra = '';
        }
    }

    // 兜底1：若标记都没匹配到（且不是表格型），回退到老的「汤面...汤底...」正则
    if ($surface === '' && $base === '') {
        $body = implode("\n", array_filter($lines, function ($l) { return !str_starts_with(trim($l), '#'); }));
        if (preg_match('/汤面(.+?)汤底(.+)/s', $body, $m)) {
            $surface = trim($m[1]);
            $base    = trim($m[2]);
        }
    }

    // 兜底1.5：surface 为空但 base 有内容（如 S3E68 "规则（本期无汤面）..."），
    // 把 extra 当作 surface（规则类汤的"规则"就是汤面）
    if ($surface === '' && $base !== '' && $extra !== '') {
        $surface = $extra;
        $extra = '';
    }

    // 兜底1.6：「汤面+汤底」合并格式（如 S3E60），split 后内容进了 base 但 surface 为空，
    // 把 base 复制到 surface（合并格式的汤面本身就是完整故事，汤底即同内容）
    if ($surface === '' && $base !== '' && $extra === '') {
        $surface = $base;
    }

    // 兜底2：surface 有内容但 base 空，且 extra 中有内容
    if ($surface !== '' && $base === '' && $extra !== '') {
        if (preg_match('/^(.+?)(\n\s*收容物\s*.*)$/s', $extra, $m)) {
            $base  = trim($m[1]);
            $extra = trim($m[2]);
        } else {
            $base  = $extra;
            $extra = '';
        }
    }

    // 若 base 里混入了主持人手册，切分出来
    if ($hostManual === '' && preg_match('/^(.+?)主持人手册(.+)$/s', $base, $m)) {
        $base       = trim($m[1]);
        $hostManual = trim($m[2]);
    }

    // 规则类汤面整理：
    // 1. 编号条目分行：汤面含 3+ 个数字编号条目时，每条独占一行（marked breaks 渲染多行）
    // 2. 红字规则标记：识别「X、X为红字规则」说明，对应条目用 <em> 包裹
    $surface = apply_red_rule_marker($surface);

    // 图片路径转换：./海龟汤图片/ → /soups-img/
    // （放在所有兜底之后，确保 surface/base/host_manual/extra 中的图片路径都被转换）
    $imgConvert = function (string $s): string {
        if ($s === '') return $s;
        return str_replace('./海龟汤图片/', '/soups-img/', $s);
    };
    $surface    = $imgConvert($surface);
    $base       = $imgConvert($base);
    $hostManual = $imgConvert($hostManual);
    $extra      = $imgConvert($extra);

    // season/episode 从文件名推断
    $season = '';
    $episode = '';
    if (preg_match('/^(S\d+)(E\d+)/', $filename, $m2)) {
        $season  = $m2[1];
        $episode = $m2[2];
    }
    if (!$season) {
        if (str_contains($filename, '灵之残响')) $season = '灵之残响';
        elseif (str_contains($filename, '规则怪谈')) $season = '规则怪谈';
    }

    return [
        'filename'     => $filename,
        'season'       => $season,
        'episode'      => $episode,
        'title'        => $title,
        'surface'      => $surface,
        'base'         => $base,
        'host_manual'  => $hostManual,
        'extra'        => $extra,
    ];
}

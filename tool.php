<?php
/**
 * 海龟汤馆 · 运维工具
 * 独立入口，管理员登录后可用
 * 访问: /tool.php
 */

// 复用主应用的 session 和错误处理
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/config.php';


$__toolCookieLife = 30 * 86400;
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_lifetime', $__toolCookieLife);
ini_set('session.gc_maxlifetime', $__toolCookieLife);
if (!empty($_SERVER['HTTPS'])) {
    ini_set('session.cookie_secure', 1);
}
session_name('hgt_sid');
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/util.php';

// 检查管理员权限
$admin = current_user();
$isAdmin = $admin && (int)$admin['is_admin'] === 1;

// 支持 token 参数免 session（可选，配置 Config::$TOOL_TOKEN 后可用）
// 也兼容 Config::$ADMIN_API_TOKEN（让后台 Admin API Token 也能调用运维工具）
$tokenOk = false;
$validTokens = array_filter([Config::$TOOL_TOKEN ?? '', Config::$ADMIN_API_TOKEN ?? '']);
if (!empty($validTokens)) {
    // 仅接受 POST/HEADER 传 token，禁止 GET（避免泄露到日志/Referer）
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? $_SERVER['HTTP_X_TOOL_TOKEN'] ?? $_POST['token'] ?? '';
    if ($token !== '') {
        foreach ($validTokens as $valid) {
            if (hash_equals($valid, $token)) {
                $tokenOk = true;
                break;
            }
        }
    }
}

if (!$isAdmin && !$tokenOk) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>运维工具</title>';
    echo '<style>body{background:#0b0d12;color:#f2f4f8;font-family:system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}';
    echo '.box{text-align:center;padding:40px;border-radius:16px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1)}';
    echo 'a{color:#6ee7ff}</style></head><body>';
    echo '<div class="box"><h2>🔒 需要管理员权限</h2><p>请先<a href="./#/#/auth">登录管理员账号</a></p></div>';
    echo '</body></html>';
    exit;
}

// 路由
$action = $_GET['action'] ?? 'index';

if ($action === 'do') {
    header('Content-Type: application/json; charset=utf-8');
    // CSRF 校验（复用主应用 session token）；token 模式下豁免
    if (!$tokenOk) {
        csrf_token(); // 确保初始化
        $expected = $_SESSION['csrf_token'] ?? '';
        $got = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($expected === '' || $got === '' || !hash_equals($expected, $got)) {
            echo json_encode(['ok' => false, 'error' => 'CSRF 校验失败，请刷新页面重试']);
            exit;
        }
    }
    $op = $_POST['op'] ?? '';
    handle_action($op);
    exit;
}

// 渲染页面
render_page($admin);

// ===================== 动作处理 =====================
function handle_action(string $op) {
    $allowed = [
        'git_pull', 'git_status', 'git_log', 'git_stash',
        'clear_cache', 'db_vacuum', 'db_backup_info',
        'php_info', 'check_updates', 'reset_opcache',
    ];
    if (!in_array($op, $allowed)) {
        echo json_encode(['ok' => false, 'error' => '未知操作']);
        return;
    }

    $result = ['ok' => true, 'op' => $op, 'output' => '', 'error' => ''];

    try {
        switch ($op) {
            case 'git_pull':
                $branch = git_branch();
                $before = trim(run_shell('git rev-parse HEAD 2>&1'));
                $pullOut = run_shell('git pull origin ' . escapeshellarg($branch) . ' 2>&1');
                $after = trim(run_shell('git rev-parse HEAD 2>&1'));
                $result['output'] = "分支: $branch\n拉取前: $before\n拉取后: $after\n\n---- git pull 输出 ----\n$pullOut\n";
                if ($before === $after) {
                    $result['output'] .= "\nℹ️ HEAD 未变化（本地已是最新，或远程没有新提交）";
                } else {
                    $count = trim(run_shell('git rev-list ' . escapeshellarg($before) . '..' . escapeshellarg($after) . ' --count 2>&1'));
                    $result['output'] .= "\n✅ 已更新到新版本，新增 $count 个提交";
                }
                break;
            case 'git_status':
                $result['output'] = run_shell('git status 2>&1');
                break;
            case 'git_log':
                $result['output'] = run_shell('git log --oneline -20 2>&1');
                break;
            case 'git_stash':
                $branch = git_branch();
                $result['output'] = run_shell('git stash && git pull origin ' . escapeshellarg($branch) . ' && git stash pop 2>&1');
                break;
            case 'clear_cache':
                $count = 0;
                $cacheDir = sys_get_temp_dir() . '/hgt_cache';
                if (is_dir($cacheDir)) {
                    foreach (glob($cacheDir . '/*') as $f) {
                        @unlink($f);
                        $count++;
                    }
                }
                if (function_exists('opcache_reset')) opcache_reset();
                $result['output'] = "已清除 $count 个缓存文件，OPcache 已重置";
                break;
            case 'db_vacuum':
                $pdo = DB::pdo();
                $before = filesize(Config::$DB_PATH);
                $pdo->exec('VACUUM');
                $after = filesize(Config::$DB_PATH);
                $saved = $before - $after;
                $result['output'] = "VACUUM 完成\n压缩前: " . fmt_size($before) . "\n压缩后: " . fmt_size($after) . "\n节省: " . fmt_size($saved);
                break;
            case 'db_backup_info':
                $result['output'] = "数据库路径: " . Config::$DB_PATH . "\n";
                $result['output'] .= "文件大小: " . fmt_size(filesize(Config::$DB_PATH)) . "\n";
                $result['output'] .= "最后修改: " . date('Y-m-d H:i:s', filemtime(Config::$DB_PATH));
                break;
            case 'php_info':
                ob_start();
                phpinfo();
                $info = ob_get_clean();
                $result['output'] = "PHP 版本: " . PHP_VERSION . "\nSAPI: " . PHP_SAPI . "\n已加载扩展: " . implode(', ', get_loaded_extensions());
                break;
            case 'check_updates':
                // 关键：必须先 fetch，否则 origin/<branch> 是本地缓存的旧值
                $fetchOut = run_shell('git fetch origin 2>&1');
                $branch = git_branch();
                $current = trim(run_shell('git rev-parse HEAD 2>&1'));
                $remoteRef = 'origin/' . $branch; // 仅用于显示
                $remoteRefArg = escapeshellarg("origin/$branch");
                $remote = trim(run_shell("git rev-parse $remoteRefArg 2>&1"));
                // 落后/领先提交数
                $behind = trim(run_shell("git rev-list HEAD..$remoteRefArg --count 2>&1"));
                $ahead = trim(run_shell("git rev-list $remoteRefArg..HEAD --count 2>&1"));
                $behind = $behind === '' || !ctype_digit($behind) ? '?' : $behind;
                $ahead = $ahead === '' || !ctype_digit($ahead) ? '?' : $ahead;
                $result['output'] = "分支: $branch\n当前 HEAD: $current\n远程 $remoteRef: $remote\n落后: $behind 个提交 · 领先: $ahead 个提交\n";
                if ($fetchOut !== '' && $fetchOut !== '(无输出)') {
                    $result['output'] .= "---- git fetch 输出 ----\n$fetchOut\n";
                }
                if (ctype_digit((string)$behind) && (int)$behind > 0) {
                    $result['output'] .= "\n⚠️ 远程有 $behind 个新提交，可点击「一键拉取更新」";
                } elseif (ctype_digit((string)$ahead) && (int)$ahead > 0) {
                    $result['output'] .= "\nℹ️ 本地领先远程 $ahead 个未推送的提交";
                } elseif ($current === $remote) {
                    $result['output'] .= "\n✅ 已是最新版本";
                } else {
                    $result['output'] .= "\nℹ️ 本地与远程 HEAD 不同，但未识别落后/领先提交数";
                }
                break;
            case 'reset_opcache':
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                    $result['output'] = "OPcache 已重置";
                } else {
                    $result['output'] = "OPcache 未启用";
                }
                break;
        }
    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['error'] = $e->getMessage();
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

function run_shell(string $cmd): string {
    if (!function_exists('shell_exec')) {
        return 'shell_exec 不可用';
    }
    // 确保在项目根目录执行，避免 web 进程 CWD 不对
    $wrapped = 'cd ' . escapeshellarg(__DIR__) . ' && ' . $cmd;
    $output = @shell_exec($wrapped);
    return $output ?: '(无输出)';
}

function fmt_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes / 1048576, 2) . ' MB';
}

// 获取当前分支名（master / main 等），失败回退 master
function git_branch(): string {
    $b = trim(run_shell('git rev-parse --abbrev-ref HEAD 2>&1'));
    $b = trim(explode("\n", $b)[0]);
    return ($b !== '' && $b !== 'HEAD') ? $b : 'master';
}

// ===================== 页面渲染 =====================
function render_page(?array $admin) {
    $username = $admin['username'] ?? 'token';
    $csrf = csrf_token();
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🔧 运维工具 · 海龟汤馆</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: #0b0d12; color: #f2f4f8;
    font-family: "Noto Sans SC", system-ui, -apple-system, sans-serif;
    -webkit-font-smoothing: antialiased; min-height: 100vh;
  }
  .page::before {
    content: ""; position: fixed; inset: 0; z-index: -2;
    background:
      radial-gradient(circle at 15% 10%, rgba(110,231,255,.10) 0%, transparent 30%),
      radial-gradient(circle at 85% 85%, rgba(167,139,250,.09) 0%, transparent 32%),
      linear-gradient(180deg, #0b0d12 0%, #12151c 55%, #0b0d12 100%);
  }
  .container { max-width: 800px; margin: 0 auto; padding: 20px; }
  .header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 0; margin-bottom: 20px;
    border-bottom: 1px solid rgba(255,255,255,.06);
  }
  .header h1 { font-size: 1.3rem; font-weight: 700; }
  .header a { color: #6ee7ff; font-size: .85rem; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%,220px), 1fr)); gap: 12px; margin-bottom: 20px; }
  .tool-card {
    background: rgba(255,255,255,.045); border: 1px solid rgba(255,255,255,.08);
    border-radius: 14px; padding: 18px; cursor: pointer;
    transition: all .2s ease;
  }
  .tool-card:hover { background: rgba(255,255,255,.075); border-color: rgba(110,231,255,.3); transform: translateY(-2px); }
  .tool-card .icon { font-size: 1.6rem; margin-bottom: 8px; }
  .tool-card .title { font-weight: 600; font-size: .95rem; margin-bottom: 4px; }
  .tool-card .desc { font-size: .8rem; color: #768094; line-height: 1.5; }
  .tool-card.danger { border-color: rgba(255,107,107,.15); }
  .tool-card.danger:hover { border-color: rgba(255,107,107,.4); background: rgba(255,107,107,.08); }
  .output-box {
    background: rgba(0,0,0,.4); border: 1px solid rgba(255,255,255,.1);
    border-radius: 10px; padding: 16px; margin-top: 16px;
    font-family: "SF Mono", "Fira Code", monospace; font-size: .82rem;
    white-space: pre-wrap; word-break: break-all; max-height: 400px; overflow-y: auto;
    display: none; line-height: 1.6;
  }
  .output-box.show { display: block; }
  .output-box .label { color: #768094; font-size: .75rem; margin-bottom: 8px; }
  .loading { display: none; text-align: center; padding: 20px; color: #6ee7ff; }
  .loading.show { display: block; }
  .spinner { width: 28px; height: 28px; border: 3px solid rgba(255,255,255,.1); border-top-color: #6ee7ff; border-radius: 50%; animation: spin .7s linear infinite; margin: 0 auto 8px; }
  @keyframes spin { to { transform: rotate(360deg); } }
  .info-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; font-size: .82rem; color: #768094; }
  .info-bar span { background: rgba(255,255,255,.05); padding: 4px 12px; border-radius: 999px; }
  .footer { text-align: center; padding: 20px 0; color: #768094; font-size: .78rem; }
</style>
</head>
<body>
<div class="page">
  <div class="container">
    <div class="header">
      <h1>🔧 运维工具</h1>
      <a href="./#/">← 返回站点</a>
    </div>
    <div class="info-bar">
      <span>👤 <?= htmlspecialchars($username) ?></span>
      <span>📁 <?= htmlspecialchars(basename(__DIR__)) ?></span>
      <span>🐘 PHP <?= PHP_VERSION ?></span>
      <span>🕐 <?= date('Y-m-d H:i:s') ?></span>
    </div>

    <h3 style="margin-bottom:12px;color:#b7c0ce;font-size:.95rem">📦 代码更新</h3>
    <div class="grid">
      <div class="tool-card" onclick="run('git_pull')">
        <div class="icon">⬇️</div>
        <div class="title">一键拉取更新</div>
        <div class="desc">git pull 拉取最新代码</div>
      </div>
      <div class="tool-card" onclick="run('check_updates')">
        <div class="icon">🔍</div>
        <div class="title">检查是否有更新</div>
        <div class="desc">对比本地和远程版本</div>
      </div>
      <div class="tool-card" onclick="run('git_status')">
        <div class="icon">📋</div>
        <div class="title">Git 状态</div>
        <div class="desc">查看当前文件变更</div>
      </div>
      <div class="tool-card" onclick="run('git_log')">
        <div class="icon">📜</div>
        <div class="title">提交历史</div>
        <div class="desc">最近 20 条 commit</div>
      </div>
    </div>

    <h3 style="margin-bottom:12px;color:#b7c0ce;font-size:.95rem">🧹 缓存与数据库</h3>
    <div class="grid">
      <div class="tool-card" onclick="run('clear_cache')">
        <div class="icon">🗑️</div>
        <div class="title">清除缓存</div>
        <div class="desc">清临时文件+重置OPcache</div>
      </div>
      <div class="tool-card" onclick="run('reset_opcache')">
        <div class="icon">♻️</div>
        <div class="title">重置 OPcache</div>
        <div class="desc">刷新PHP字节码缓存</div>
      </div>
      <div class="tool-card" onclick="run('db_vacuum')">
        <div class="icon">🗜️</div>
        <div class="title">数据库压缩</div>
        <div class="desc">VACUUM 优化SQLite</div>
      </div>
      <div class="tool-card" onclick="run('db_backup_info')">
        <div class="icon">💾</div>
        <div class="title">数据库信息</div>
        <div class="desc">查看大小和修改时间</div>
      </div>
    </div>

    <h3 style="margin-bottom:12px;color:#b7c0ce;font-size:.95rem">⚠️ 高级</h3>
    <div class="grid">
      <div class="tool-card danger" onclick="run('git_stash')">
        <div class="icon">🔀</div>
        <div class="title">暂存+拉取+恢复</div>
        <div class="desc">本地有修改时用此操作</div>
      </div>
    </div>

    <div class="loading" id="loading">
      <div class="spinner"></div>
      <p>执行中…</p>
    </div>

    <div class="output-box" id="output">
      <div class="label" id="outputLabel"></div>
      <div id="outputContent"></div>
    </div>

    <div class="footer">海龟汤馆 · 运维工具</div>
  </div>
</div>
<script>
async function run(op) {
  const labels = {
    git_pull: '⬇️ git pull', check_updates: '🔍 检查更新', git_status: '📋 Git 状态',
    git_log: '📜 提交历史', clear_cache: '🗑️ 清除缓存', reset_opcache: '♻️ 重置OPcache',
    db_vacuum: '🗜️ 数据库压缩', db_backup_info: '💾 数据库信息', git_stash: '⚠️ 暂存+拉取+恢复',
  };
  if (op === 'git_pull' || op === 'git_stash') {
    if (!confirm('确认执行 ' + (labels[op] || op) + ' ？')) return;
  }
  document.getElementById('loading').classList.add('show');
  document.getElementById('output').classList.remove('show');
  try {
    const fd = new FormData();
    fd.append('op', op);
    fd.append('_csrf', <?= json_encode($csrf) ?>);
    const r = await fetch('?action=do', { method: 'POST', body: fd });
    const data = await r.json();
    document.getElementById('outputLabel').textContent = labels[op] || op;
    document.getElementById('outputContent').textContent = data.ok ? data.output : '❌ ' + (data.error || '失败');
    document.getElementById('output').classList.add('show');
  } catch (e) {
    document.getElementById('outputLabel').textContent = '错误';
    document.getElementById('outputContent').textContent = e.message;
    document.getElementById('output').classList.add('show');
  }
  document.getElementById('loading').classList.remove('show');
}
</script>
</body>
</html>
<?php
}

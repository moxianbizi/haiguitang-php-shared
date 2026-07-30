<?php
/**
 * 海龟汤馆 · 入口路由
 * 所有 /api/* 走这里，其他路径回退到前端静态文件
 */

// 全局错误捕获：把致命错误转成 JSON 而不是空白 500
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        error_log("Fatal error: {$e['message']} in {$e['file']}:{$e['line']}");
        $debug = ($_SERVER['HTTP_X_DEBUG'] ?? '') === '1';
        echo json_encode([
            'error' => '服务器内部错误',
            'debug' => $debug ? ['message' => $e['message'], 'file' => $e['file'], 'line' => $e['line']] : null,
        ], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/config.php';

ini_set('post_max_size', '10M');

if (PHP_VERSION_ID < 80000) {
    require_once __DIR__ . '/lib/compat.php';
}

// 先加载 db.php，后续环境检查需要 DB::driver() 判断数据库类型
require_once __DIR__ . '/db.php';

// 环境检查：确保 data 目录可写（SQLite 时才需要文件可写；MySQL 时仍检查目录存在）
$__dataDir = dirname(Config::$DB_PATH);
if (!is_dir($__dataDir)) {
    @mkdir($__dataDir, 0775, true);
}
if (!is_writable($__dataDir)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    error_log('data 目录不可写: ' . $__dataDir);
    echo json_encode([
        'error' => 'data 目录不可写，请联系管理员',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// 检查 PDO 扩展：根据数据库配置决定需要 SQLite 还是 MySQL
$requiredPdoExt = (DB::driver() === 'mysql') ? 'pdo_mysql' : 'pdo_sqlite';
if (!extension_loaded($requiredPdoExt)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'error' => '缺少 ' . strtoupper($requiredPdoExt) . ' 扩展',
        'detail' => '请联系主机商启用 PHP 的 ' . $requiredPdoExt . ' 扩展',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// session 配置：30 天持久化（关闭浏览器不丢登录）
$__cookieLifetime = 30 * 86400;
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_lifetime', $__cookieLifetime);
ini_set('session.gc_maxlifetime', $__cookieLifetime);
if (!empty($_SERVER['HTTPS'])) {
    ini_set('session.cookie_secure', 1);
}
session_name('hgt_sid');
session_start();
// 已有会话但 cookie 即将过期时刷新 lifetime（滑动过期）
if (!empty($_SESSION['user_id'])) {
    setcookie(session_name(), session_id(), [
        'expires'  => time() + $__cookieLifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

// Session 超时检查（滑动过期：每次活跃都顺延）
if (Config::$SESSION_TIMEOUT > 0 && !empty($_SESSION['login_time'])) {
    if (time() - $_SESSION['login_time'] > Config::$SESSION_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
    } else {
        // 活跃即顺延
        $_SESSION['login_time'] = time();
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (!empty($_SERVER['HTTPS'])) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; connect-src 'self' https:; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; frame-ancestors 'none'");

// CORS（同源通常不需要，部署到不同域名时打开）
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Credentials: true');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 子目录部署支持：识别当前入口文件的目录前缀
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uri, $scriptDir . '/')) {
    $uri = substr($uri, strlen($scriptDir));
} elseif ($scriptDir !== '' && $scriptDir !== '/' && $uri === $scriptDir) {
    $uri = '/';
}
if ($uri === '' || $uri === false) $uri = '/';

// 注意：PHP 内置 server 的 router 模式下，SCRIPT_NAME 会被设为请求路径，
// dirname 会变成请求路径的目录（例如 /api），若按上述逻辑会误剥离。
// 因此这里增加一个判断：如果 SCRIPT_NAME 对应的真实文件不存在，
// 说明是 router 模式，忽略它。
$scriptFile = $_SERVER['DOCUMENT_ROOT'] . ($_SERVER['SCRIPT_NAME'] ?? '');
if (!is_file($scriptFile)) {
    // router 模式：重新取 REQUEST_URI 并按环境变量 BASE_PATH（可选）剥离
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = rtrim(getenv('BASE_PATH') ?: '', '/');
    if ($base && $base !== '/' && str_starts_with($uri, $base . '/')) {
        $uri = substr($uri, strlen($base));
    }
    if ($uri === '' || $uri === false) $uri = '/';
}

// 无 URL 重写支持（共享主机常见）：通过 index.php?r=/api/xxx 访问 API
if (!str_starts_with($uri, '/api/') && isset($_GET['r']) && str_starts_with($_GET['r'], '/api/')) {
    $uri = $_GET['r'];
}

// 调试（生产删除）
// if (getenv('HGT_DEBUG')) {
//     header('Content-Type: text/plain');
//     echo 'SCRIPT_NAME=' . ($_SERVER['SCRIPT_NAME'] ?? '') . "\n";
//     echo 'uri=' . $uri . "\n";
//     exit;
// }

// API 路由
if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    $path = substr($uri, 5); // 去掉 /api/
    route_api($path);
    return;
}

// 前端静态文件
serve_static($uri);

// ===================== API 路由 =====================
function route_api(string $path) {
    $segments = explode('/', trim($path, '/'));

    // 兜底：某些 nginx 反代/PHP-FPM 配置下 $_GET 可能为空，
    // 从 REQUEST_URI 自己解析 query string 补全 $_GET。
    if (empty($_GET) && isset($_SERVER['REQUEST_URI'])) {
        $qPos = strpos($_SERVER['REQUEST_URI'], '?');
        if ($qPos !== false) {
            $qs = substr($_SERVER['REQUEST_URI'], $qPos + 1);
            parse_str($qs, $parsed);
            if (is_array($parsed)) $_GET = array_merge($_GET, $parsed);
        }
    }

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/util.php';
    require_once __DIR__ . '/lib/mail.php';
    require_once __DIR__ . '/lib/ai.php';

    $module = $segments[0] ?? '';

    // 初始化 CSRF token（确保 session 里有 token）
    csrf_token();

    // CSRF 校验：豁免登录前接口（注册暂未开放，但保留豁免）
    // Admin API Token 请求（X-Admin-Token）也豁免 CSRF，便于脚本/Agent 调用
    $exemptCsrf = ['auth/login', 'auth/send-code', 'auth/register'];
    if (admin_token_user() !== null) {
        // 所有 admin token 请求都豁免 CSRF
    } else {
        csrf_check($exemptCsrf);
    }

    // 概率性清理过期的频率限制记录
    if (mt_rand(1, 10000) <= (int)(Config::$RATE_LIMIT_CLEANUP_PROBABILITY * 10000)) {
        cleanup_rate_limits();
    }

    // 首次访问自动导入汤
    try {
        DB::import_soups_if_empty();
    } catch (Throwable $e) {
        // 导入失败不阻断
    }

    switch ($module) {
        case 'auth':
            require_once __DIR__ . '/api/auth.php';
            handle_auth($segments);
            break;
        case 'soups':
            require_once __DIR__ . '/api/soups.php';
            handle_soups($segments);
            break;
        case 'rooms':
            require_once __DIR__ . '/api/rooms.php';
            handle_rooms($segments);
            break;
        case 'lzcxroom':
            // 灵之残响专属房间（带状态机/角色分配/多轮上下文）
            require_once __DIR__ . '/api/lzcxroom.php';
            handle_lzcxroom($segments);
            break;
        case 'ai':
            require_once __DIR__ . '/api/ai.php';
            handle_ai($segments);
            break;
        case 'follow':
            require_once __DIR__ . '/api/follow.php';
            handle_follow($segments);
            break;
        case 'users':
            require_once __DIR__ . '/api/users.php';
            handle_users($segments);
            break;
        case 'admin':
            require_once __DIR__ . '/api/admin.php';
            handle_admin($segments);
            break;
        case 'poll':
            require_once __DIR__ . '/api/poll.php';
            handle_poll($segments);
            break;
        case 'health':
            json_ok(['status' => 'ok', 'time' => date('c')]);
            break;
        default:
            json_error('Not Found', 404);
    }
}

// ===================== 静态文件 =====================
function serve_static(string $uri) {
    $frontend = __DIR__ . '/frontend';
    $clean = ltrim($uri, '/');

    // 禁止直接访问 data/ lib/ 目录
    if (str_starts_with($clean, 'data/') || str_starts_with($clean, 'lib/') || $clean === 'data' || $clean === 'lib') {
        http_response_code(404);
        echo '404 Not Found';
        return;
    }

    if ($clean === '' || $clean === '/') {
        readfile_static($frontend . '/index.html');
        return;
    }

    $target = $frontend . '/' . $clean;
    // 防目录穿越
    $realFrontend = realpath($frontend);
    $real = realpath($target);
    if ($realFrontend && $real && str_starts_with($real, $realFrontend)) {
        if (is_file($real)) {
            readfile_static($real);
            return;
        }
    }
    // SPA fallback
    readfile_static($frontend . '/index.html');
}

function readfile_static(string $file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=3600');
    readfile($file);
}

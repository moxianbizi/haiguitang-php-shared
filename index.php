<?php
/**
 * 海龟汤馆 · 共享主机入口
 * 支持 URL 重写（/api/xxx）和 query_string（index.php?r=/api/xxx）两种访问方式
 */

require_once __DIR__ . '/config.runtime.php';

if (!extension_loaded('pdo_mysql')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => '缺少 pdo_mysql 扩展'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_error_handler(fn($s, $m, $f, $l) => throw new ErrorException($m, 0, $s, $f, $l));

// Session 配置
$cookieParams = [
    'lifetime' => Config::$SESSION_TIMEOUT,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']),
];
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params($cookieParams);
} else {
    session_set_cookie_params(
        $cookieParams['lifetime'],
        $cookieParams['path'] . '; SameSite=' . $cookieParams['samesite'],
        '',
        $cookieParams['secure'],
        $cookieParams['httponly']
    );
}
session_name('hgt_sid');
session_start();

if (Config::$SESSION_TIMEOUT > 0 && !empty($_SESSION['login_time'])) {
    if (time() - $_SESSION['login_time'] > Config::$SESSION_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
    } else {
        $_SESSION['login_time'] = time();
    }
}

// 安全响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (!empty($_SERVER['HTTPS'])) {
    header('Strict-Transport-Security: max-age=31536000');
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// 子目录部署支持
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uri, $scriptDir . '/')) {
    $uri = substr($uri, strlen($scriptDir));
} elseif ($scriptDir !== '' && $scriptDir !== '/' && $uri === $scriptDir) {
    $uri = '/';
}

// PHP 内置 server router 模式下不要误剥离
$scriptFile = ($_SERVER['DOCUMENT_ROOT'] ?? '') . ($_SERVER['SCRIPT_NAME'] ?? '');
if (!is_file($scriptFile)) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $base = rtrim(getenv('BASE_PATH') ?: '', '/');
    if ($base && $base !== '/' && str_starts_with($uri, $base . '/')) {
        $uri = substr($uri, strlen($base));
    }
}

// 无 URL 重写支持
if (!str_starts_with($uri, '/api/') && isset($_GET['r']) && str_starts_with($_GET['r'], '/api/')) {
    $r = $_GET['r'];
    $qPos = strpos($r, '?');
    if ($qPos !== false) {
        parse_str(substr($r, $qPos + 1), $parsed);
        if (is_array($parsed)) $_GET = array_merge($_GET, $parsed);
        $r = substr($r, 0, $qPos);
    }
    $uri = $r;
}

// API 路由
if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    route_api(substr($uri, 5));
    return;
}

serve_static($uri);

function route_api(string $path): void {
    $segments = explode('/', trim($path, '/'));

    if (empty($_GET) && isset($_SERVER['REQUEST_URI'])) {
        $qPos = strpos($_SERVER['REQUEST_URI'], '?');
        if ($qPos !== false) {
            parse_str(substr($_SERVER['REQUEST_URI'], $qPos + 1), $parsed);
            if (is_array($parsed)) $_GET = array_merge($_GET, $parsed);
        }
    }

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/util.php';
    require_once __DIR__ . '/lib/mail.php';
    require_once __DIR__ . '/lib/ai.php';

    csrf_token();
    $exemptCsrf = ['auth/login', 'auth/send-code', 'auth/register'];
    if (admin_token_user() === null) {
        csrf_check($exemptCsrf);
    }

    if (mt_rand(1, 10000) <= 100) {
        cleanup_rate_limits();
    }

    try {
        DB::init();
        DB::import_soups();
    } catch (Throwable $e) {
        // 首次数据库连接失败不阻断静态资源，但 API 会自然报错
    }

    $module = $segments[0] ?? '';
    match ($module) {
        'auth' => (require_once __DIR__ . '/api/auth.php') && handle_auth($segments),
        'soups' => (require_once __DIR__ . '/api/soups.php') && handle_soups($segments),
        'rooms' => (require_once __DIR__ . '/api/rooms.php') && handle_rooms($segments),
        'ai' => (require_once __DIR__ . '/api/ai.php') && handle_ai($segments),
        'follow' => (require_once __DIR__ . '/api/follow.php') && handle_follow($segments),
        'users' => (require_once __DIR__ . '/api/users.php') && handle_users($segments),
        'admin' => (require_once __DIR__ . '/api/admin.php') && handle_admin($segments),
        'poll' => (require_once __DIR__ . '/api/poll.php') && handle_poll($segments),
        'health' => json_ok(['status' => 'ok', 'time' => date('c')]),
        default => json_error('Not Found', 404),
    };
}

function serve_static(string $uri): void {
    $frontend = __DIR__ . '/frontend';
    $clean = ltrim($uri, '/');

    if (str_starts_with($clean, 'data/') || str_starts_with($clean, 'lib/') || $clean === 'data' || $clean === 'lib') {
        http_response_code(404);
        echo '404 Not Found';
        return;
    }

    if ($clean === '' || $clean === '/') {
        readfile_or_404($frontend . '/index.html');
        return;
    }

    $rootFile = __DIR__ . '/' . $clean;
    if (is_file($rootFile)) {
        readfile_or_404($rootFile);
        return;
    }

    $target = $frontend . '/' . $clean;
    $realFrontend = realpath($frontend);
    $real = realpath($target);
    if ($realFrontend && $real && str_starts_with($real, $realFrontend) && is_file($real)) {
        readfile_or_404($real);
        return;
    }

    // 前端路由兜底：返回 index.html
    readfile_or_404($frontend . '/index.html');
}

function readfile_or_404(string $path): void {
    if (!is_file($path)) {
        http_response_code(404);
        echo '404 Not Found';
        return;
    }
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $mime = match (strtolower($ext)) {
        'js' => 'application/javascript',
        'css' => 'text/css',
        'html' => 'text/html',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime . '; charset=utf-8');
    readfile($path);
}

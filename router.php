<?php
/**
 * PHP 内置开发服务器路由
 * 用法：php -S 127.0.0.1:8080 router.php
 */

// 禁止访问 data/ 和 lib/ 目录
if (preg_match('#^/(data|lib)/#', $_SERVER['REQUEST_URI'])) {
    http_response_code(404);
    echo '404 Not Found';
    return;
}

// 禁止直接访问 config.php 和 db.php
if (preg_match('#^/(config|db)\.php$#', $_SERVER['REQUEST_URI'])) {
    http_response_code(404);
    echo '404 Not Found';
    return;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 根目录的 .php 文件直接执行（如 tool.php）
$rootFile = __DIR__ . $uri;
if ($uri !== '/' && $uri !== '' && preg_match('/\.php$/', $uri) && is_file($rootFile)) {
    require $rootFile;
    return;
}

// 根目录的静态文件（robots.txt 等）
$rootStatic = __DIR__ . $uri;
if ($uri !== '/' && $uri !== '' && !preg_match('/\.php$/', $uri) && is_file($rootStatic)) {
    return false;
}

$file = __DIR__ . '/frontend' . $uri;

// 静态文件直接返回（让 PHP 内置 server 处理）
if ($uri !== '/' && $uri !== '' && is_file($file)) {
    return false;
}

// 其余请求（含 /api/* 和 /）交给 index.php
require __DIR__ . '/index.php';

<?php
/** 兼容前端轮询入口：/api/poll/<code> 等同于 /api/rooms/<code>/messages?since=N */

require_once __DIR__ . '/rooms.php';

function handle_poll(array $segments) {
    require_login();
    $code = strtoupper($segments[1] ?? '');
    if ($code === '') json_error('Not Found', 404);
    rooms_poll_messages($code);
}

<?php
/** 轮询聚合（简化占位，前端直接 rooms/messages 轮询） */

function handle_poll(array $segments): void {
    json_ok(['ts' => time(), 'msg' => '请使用 /api/rooms/{code}/messages?since=xxx 轮询']);
}

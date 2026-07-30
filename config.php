<?php
/**
 * 海龟汤馆 · 配置
 * 部署时改这里，或通过环境变量覆盖
 */
class Config {
    /** 应用密钥（session/验证码签名） */
    public static $SECRET_KEY = '';

    /**
     * 数据库配置
     * - 共享主机常提供 MySQL：设置 DB_DSN 为 mysql:host=...;dbname=...;charset=utf8mb4
     * - 若 DB_DSN 为空，则回退到 SQLite（DB_PATH）
     */
    public static $DB_DSN = '';
    public static $DB_USER = '';
    public static $DB_PASS = '';
    public static $DB_PREFIX = ''; // 表前缀（多程序共用库时可用）

    /** SQLite 数据库文件路径（DB_DSN 为空时使用） */
    public static $DB_PATH = __DIR__ . '/data/haiguitang.db';

    /** 汤源目录（MD 文件） */
    public static $SOUPS_DIR = __DIR__ . '/data/soups';

    /** DeepSeek API —— 仅公开的接入地址与模型，密钥由前端用户自填 */
    public static $DEEPSEEK_BASE_URL = 'https://api.deepseek.com/v1';
    public static $DEEPSEEK_MODEL = 'deepseek-v4-flash';

    /** SMTP 邮件配置（不配则验证码直接返回在响应中，仅开发模式） */
    public static $MAIL_SMTP_HOST = '';
    public static $MAIL_SMTP_PORT = 465;
    public static $MAIL_SMTP_USER = '';
    public static $MAIL_SMTP_PASS = '';
    public static $MAIL_FROM = '';

    /**
     * 邮件服务商：'resend'（默认，HTTP API，走 443，共享主机最稳）或 'smtp'
     * - 共享主机常封 465/587 SMTP 端口，默认走 Resend
     * - Resend 需配置 RESEND_API_KEY，发件人用 Resend 控制台验证过的域名
     */
    public static $MAIL_PROVIDER = 'resend';

    /** Resend API Key（re_xxx），在 https://resend.com/api-keys 创建 */
    public static $RESEND_API_KEY = '';

    /** Resend 发件地址，必须用 Resend 已验证的域名（如 noreply@yourdomain.com）；
     *  没有域名时可用 onboarding@resend.dev（仅能发到注册 Resend 的邮箱） */
    public static $RESEND_FROM = '海龟汤馆 <onboarding@resend.dev>';

    /** 是否允许投稿（登录后任意用户） */
    public static $ALLOW_SUBMIT = true;

    /** 是否允许公开注册（关闭后只能由管理员后台建号） */
    public static $ALLOW_REGISTER = true;

    /** 发件人显示名称（邮件 From 头展示用） */
    public static $MAIL_FROM_NAME = '海龟汤馆';

    /** 验证码有效期（秒） */
    public static $CODE_TTL = 600;

    /** 轮询消息间隔（毫秒，前端参考） */
    public static $POLL_INTERVAL = 1500;

    /** 房间消息保留条数（0=全部） */
    public static $ROOM_MSG_LIMIT = 200;

    /** 运维工具 Token（留空则只用管理员 session，设置后支持 ?token=xxx 免登录访问 tool.php） */
    public static $TOOL_TOKEN = '';

    /** 后台 API Token（留空则只能用管理员 session；设置后可用 X-Admin-Token 头免登录调用 /api/admin/*） */
    public static $ADMIN_API_TOKEN = '';

    /** Session 超时（秒，0 表示不限制；默认 30 天，与 cookie lifetime 一致） */
    public static $SESSION_TIMEOUT = 2592000;

    /** 频率限制：AI 提问每分钟最大次数 */
    public static $RATE_LIMIT_AI_ASK = 10;

    /** 频率限制：房间创建每分钟最大次数 */
    public static $RATE_LIMIT_ROOM_CREATE = 5;

    /** 频率限制：消息发送每房间每分钟最大次数 */
    public static $RATE_LIMIT_MSG_SEND = 30;

    /** 频率限制：自动清理过期记录的概率（0-1） */
    public static $RATE_LIMIT_CLEANUP_PROBABILITY = 0.01;

    /** 初始化时从环境变量覆盖 */
    public static function load() {
        $env = function($key, $default) {
            $v = getenv($key);
            return $v === false ? $default : $v;
        };
        self::$SECRET_KEY = $env('SECRET_KEY', self::$SECRET_KEY);
        self::$DB_DSN     = $env('DB_DSN', self::$DB_DSN);
        self::$DB_USER    = $env('DB_USER', self::$DB_USER);
        self::$DB_PASS    = $env('DB_PASS', self::$DB_PASS);
        self::$DB_PREFIX  = $env('DB_PREFIX', self::$DB_PREFIX);
        self::$DB_PATH    = $env('DB_PATH', self::$DB_PATH);
        self::$SOUPS_DIR  = $env('SOUPS_DIR', self::$SOUPS_DIR);
        self::$DEEPSEEK_BASE_URL = $env('DEEPSEEK_BASE_URL', self::$DEEPSEEK_BASE_URL);
        self::$DEEPSEEK_MODEL   = $env('DEEPSEEK_MODEL', self::$DEEPSEEK_MODEL);
        self::$MAIL_SMTP_HOST = $env('MAIL_SMTP_HOST', self::$MAIL_SMTP_HOST);
        self::$MAIL_SMTP_PORT = (int)$env('MAIL_SMTP_PORT', self::$MAIL_SMTP_PORT);
        self::$MAIL_SMTP_USER = $env('MAIL_SMTP_USER', self::$MAIL_SMTP_USER);
        self::$MAIL_SMTP_PASS = $env('MAIL_SMTP_PASS', self::$MAIL_SMTP_PASS);
        self::$MAIL_FROM = $env('MAIL_FROM', self::$MAIL_FROM);
        self::$MAIL_FROM_NAME = $env('MAIL_FROM_NAME', self::$MAIL_FROM_NAME);
        self::$MAIL_PROVIDER = $env('MAIL_PROVIDER', self::$MAIL_PROVIDER);
        self::$RESEND_API_KEY = $env('RESEND_API_KEY', self::$RESEND_API_KEY);
        self::$RESEND_FROM = $env('RESEND_FROM', self::$RESEND_FROM);
        self::$TOOL_TOKEN = $env('TOOL_TOKEN', self::$TOOL_TOKEN);
        self::$ADMIN_API_TOKEN = $env('ADMIN_API_TOKEN', self::$ADMIN_API_TOKEN);
        self::$ALLOW_REGISTER = $env('ALLOW_REGISTER', self::$ALLOW_REGISTER ? '1' : '0') === '1';
        self::$RATE_LIMIT_AI_ASK = (int)$env('RATE_LIMIT_AI_ASK', self::$RATE_LIMIT_AI_ASK);
        self::$RATE_LIMIT_ROOM_CREATE = (int)$env('RATE_LIMIT_ROOM_CREATE', self::$RATE_LIMIT_ROOM_CREATE);
        self::$RATE_LIMIT_MSG_SEND = (int)$env('RATE_LIMIT_MSG_SEND', self::$RATE_LIMIT_MSG_SEND);

        // SECRET_KEY 处理优先级：
        // 1) 环境变量（最高优先级，立即生效）
        // 2) settings 表持久化的值（首次启动后自动写入，保证跨请求稳定）
        // 3) 都没有 → 生成随机值（临时用，load_from_db 时会持久化到表）
        // 注意：settings 表此时可能还没建（DB::pdo() 尚未调用），
        // 所以持久化逻辑放在 load_from_db() 里，那里表一定已建好。
        if (self::$SECRET_KEY === '') {
            self::$SECRET_KEY = bin2hex(random_bytes(32));
        }
    }

    /** 从 settings 表加载持久化配置（覆盖默认值与环境变量，由 DB 初始化后调用） */
    public static function load_from_db() {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->query('SELECT key, value FROM settings');
            if (!$stmt) return;
            foreach ($stmt->fetchAll() as $r) {
                $k = $r['key'];
                $v = $r['value'];
                if ($k === 'allow_submit') self::$ALLOW_SUBMIT = ($v === '1');
                elseif ($k === 'allow_register') self::$ALLOW_REGISTER = ($v === '1');
                elseif ($k === 'room_msg_limit') self::$ROOM_MSG_LIMIT = (int)$v;
                elseif ($k === 'mail_smtp_host') self::$MAIL_SMTP_HOST = $v;
                elseif ($k === 'mail_smtp_port') self::$MAIL_SMTP_PORT = (int)$v;
                elseif ($k === 'mail_smtp_user') self::$MAIL_SMTP_USER = $v;
                elseif ($k === 'mail_smtp_pass') self::$MAIL_SMTP_PASS = $v;
                elseif ($k === 'mail_from') self::$MAIL_FROM = $v;
                elseif ($k === 'mail_from_name') self::$MAIL_FROM_NAME = $v;
                elseif ($k === 'mail_provider') self::$MAIL_PROVIDER = $v;
                elseif ($k === 'resend_api_key') self::$RESEND_API_KEY = $v;
                elseif ($k === 'resend_from') self::$RESEND_FROM = $v;
                // SECRET_KEY：仅当未通过环境变量设置时，从表读稳定值
                elseif ($k === 'secret_key' && getenv('SECRET_KEY') === false) {
                    self::$SECRET_KEY = $v;
                }
            }

            // SECRET_KEY 持久化：如果环境变量没设 + settings 表里也没有
            // （首次部署），把当前随机生成的 SECRET_KEY 写入表，保证后续请求稳定
            if (getenv('SECRET_KEY') === false) {
                $check = $pdo->prepare('SELECT 1 FROM settings WHERE key = ?');
                $check->execute(['secret_key']);
                if (!$check->fetch()) {
                    $stmt = $pdo->prepare('REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ' . DB::nowExpr() . ')');
                    $stmt->execute(['secret_key', self::$SECRET_KEY]);
                }
            }
        } catch (Throwable $e) {
            // 表不存在或数据库未初始化时忽略
        }
    }
}
Config::load();

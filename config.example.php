<?php
/**
 * 配置示例文件
 * 复制/重命名为 config.php 后填入真实值
 */
class Config {
    public static string $SECRET_KEY = 'your-random-secret-key-32bytes-hex';

    public static string $DB_HOST = 'localhost';
    public static string $DB_NAME = 'your_db_name';
    public static string $DB_USER = 'your_db_user';
    public static string $DB_PASS = 'your_db_password';
    public static string $DB_CHARSET = 'utf8mb4';
    public static string $DB_PREFIX = '';

    public static string $SOUPS_DIR = __DIR__ . '/data/soups';

    public static string $DEEPSEEK_BASE_URL = 'https://api.deepseek.com/v1';
    public static string $DEEPSEEK_MODEL = 'deepseek-v4-flash';

    public static string $MAIL_PROVIDER = 'resend';
    public static string $RESEND_API_KEY = '';
    public static string $RESEND_FROM = '海龟汤馆 <onboarding@resend.dev>';

    public static bool $ALLOW_REGISTER = true;
    public static bool $ALLOW_SUBMIT = true;

    public static int $SESSION_TIMEOUT = 2592000;
    public static int $CODE_TTL = 600;
    public static int $RATE_LIMIT_AI_ASK = 10;
    public static int $RATE_LIMIT_ROOM_CREATE = 5;
    public static int $RATE_LIMIT_MSG_SEND = 30;

    public static string $ADMIN_API_TOKEN = '';
    public static string $TOOL_TOKEN = '';

    public static function load(): void {
        $env = fn(string $k, $d) => ($v = getenv($k)) === false ? $d : $v;
        self::$SECRET_KEY = $env('SECRET_KEY', self::$SECRET_KEY);
        self::$DB_HOST = $env('DB_HOST', self::$DB_HOST);
        self::$DB_NAME = $env('DB_NAME', self::$DB_NAME);
        self::$DB_USER = $env('DB_USER', self::$DB_USER);
        self::$DB_PASS = $env('DB_PASS', self::$DB_PASS);
        self::$DB_PREFIX = $env('DB_PREFIX', self::$DB_PREFIX);
        self::$SOUPS_DIR = $env('SOUPS_DIR', self::$SOUPS_DIR);
        self::$DEEPSEEK_BASE_URL = $env('DEEPSEEK_BASE_URL', self::$DEEPSEEK_BASE_URL);
        self::$DEEPSEEK_MODEL = $env('DEEPSEEK_MODEL', self::$DEEPSEEK_MODEL);
        self::$MAIL_PROVIDER = $env('MAIL_PROVIDER', self::$MAIL_PROVIDER);
        self::$RESEND_API_KEY = $env('RESEND_API_KEY', self::$RESEND_API_KEY);
        self::$RESEND_FROM = $env('RESEND_FROM', self::$RESEND_FROM);
        self::$ALLOW_REGISTER = $env('ALLOW_REGISTER', self::$ALLOW_REGISTER ? '1' : '0') === '1';
        self::$ALLOW_SUBMIT = $env('ALLOW_SUBMIT', self::$ALLOW_SUBMIT ? '1' : '0') === '1';
    }
}
Config::load();

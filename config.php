<?php
/**
 * Конфигурация приложения Меджурнал PWA
 */

// Настройки базы данных
define('DB_HOST', 'krasterisk.ru');
define('DB_NAME', 'krasterisk');
define('DB_USER', 'krasterisk');
define('DB_PASS', '0c%NW$*1V5');
define('DB_CHARSET', 'utf8');

// Настройки сессии / авторизации
define('AUTH_COOKIE_NAME', 'medjournal_auth');
define('AUTH_COOKIE_DAYS', 14);

// VAPID ключи для Web Push (нужно сгенерировать свои!)
// Сгенерировать можно командой: php generate_vapid_keys.php
define('VAPID_PUBLIC_KEY', '');
define('VAPID_PRIVATE_KEY', '');
define('VAPID_SUBJECT', 'mailto:admin@krasterisk.ru');

// Маппинг уровней пользователей
define('ROLE_DOCTOR', 1);        // Врач
define('ROLE_SISTER', 4);        // Участковая сестра
define('ROLE_NURSE', 8);         // Медицинская сестра

// Маппинг названий ролей
$ROLE_NAMES = [
    ROLE_DOCTOR => 'Врач',
    ROLE_SISTER => 'Участковая сестра',
    ROLE_NURSE  => 'Медицинская сестра',
];

// Интервал polling (в миллисекундах)
define('POLLING_INTERVAL', 60000); // 1 минута

// Количество записей на страницу
define('RECORDS_PER_PAGE', 20);

/**
 * PDO подключение к БД
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

/**
 * Запуск сессии
 */
function initSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Проверка авторизации пользователя
 */
function checkAuth(): ?array {
    initSession();
    
    // Проверяем сессию
    if (isset($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    
    // Проверяем cookie
    if (isset($_COOKIE[AUTH_COOKIE_NAME])) {
        $token = $_COOKIE[AUTH_COOKIE_NAME];
        $data = json_decode(base64_decode($token), true);
        if ($data && isset($data['user_id'], $data['expires'])) {
            if ($data['expires'] > time()) {
                // Проверяем пользователя в БД
                $db = getDB();
                $stmt = $db->prepare('SELECT * FROM gdb_users WHERE id = ?');
                $stmt->execute([$data['user_id']]);
                $user = $stmt->fetch();
                if ($user) {
                    $_SESSION['user'] = [
                        'id'         => $user['id'],
                        'fio'        => $user['fio'],
                        'level'      => (int)$user['level'],
                        'policlinic' => $user['policlinic'],
                        'area'       => $user['area'],
                        'doctor'     => $user['doctor'] ?? $user['fio'],
                    ];
                    return $_SESSION['user'];
                }
            }
        }
    }
    
    return null;
}

/**
 * Установка авторизации
 */
function setAuth(array $user): void {
    initSession();
    
    $_SESSION['user'] = [
        'id'         => $user['id'],
        'fio'        => $user['fio'],
        'level'      => (int)$user['level'],
        'policlinic' => $user['policlinic'],
        'area'       => $user['area'],
        'doctor'     => $user['doctor'] ?? $user['fio'],
    ];
    
    // Устанавливаем cookie на 14 дней
    $cookieData = base64_encode(json_encode([
        'user_id' => $user['id'],
        'expires' => time() + (AUTH_COOKIE_DAYS * 86400),
    ]));
    
    setcookie(AUTH_COOKIE_NAME, $cookieData, [
        'expires'  => time() + (AUTH_COOKIE_DAYS * 86400),
        'path'     => '/',
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
}

/**
 * Выход из системы
 */
function logout(): void {
    initSession();
    $_SESSION = [];
    session_destroy();
    setcookie(AUTH_COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/']);
}

/**
 * JSON ответ
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

<?php
/**
 * Конфигурация приложения Меджурнал PWA
 * Совместимость: PHP 5.5+
 *
 * Скопируйте этот файл в config.php и заполните данные:
 *   cp config.example.php config.php
 */

// Наименование медицинской организации
define('MO_NAME', '');

// Версия приложения
define('APP_VERSION', '1.0.8');

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8');

// Настройки сессии / авторизации
define('AUTH_COOKIE_NAME', 'medjournal_auth');
define('AUTH_COOKIE_DAYS', 14);

// VAPID ключи для Web Push (опционально)
define('VAPID_PUBLIC_KEY', '');
define('VAPID_PRIVATE_KEY', '');
define('VAPID_SUBJECT', 'mailto:admin@example.com');

// Маппинг уровней пользователей
define('ROLE_DOCTOR', 1);        // Врач
define('ROLE_SISTER', 4);        // Участковая сестра
define('ROLE_NURSE', 8);         // Медицинская сестра

// Маппинг названий ролей
$ROLE_NAMES = array(
    ROLE_DOCTOR => 'Врач',
    ROLE_SISTER => 'Участковая сестра',
    ROLE_NURSE => 'Медицинская сестра',
);

// Интервал polling (в миллисекундах)
define('POLLING_INTERVAL', 60000);

// Количество записей на страницу
define('RECORDS_PER_PAGE', 20);

/**
 * PDO подключение к БД
 */
function getDB()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }
    return $pdo;
}

/**
 * Запуск сессии
 */
function initSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Проверка авторизации пользователя
 */
function checkAuth()
{
    initSession();

    if (isset($_SESSION['user'])) {
        return $_SESSION['user'];
    }

    if (isset($_COOKIE[AUTH_COOKIE_NAME])) {
        $token = $_COOKIE[AUTH_COOKIE_NAME];
        $data = json_decode(base64_decode($token), true);
        if ($data && isset($data['user_id'], $data['expires'])) {
            if ($data['expires'] > time()) {
                $db = getDB();
                $stmt = $db->prepare('SELECT * FROM gdb_users WHERE id = ?');
                $stmt->execute(array($data['user_id']));
                $user = $stmt->fetch();
                if ($user) {
                    $_SESSION['user'] = array(
                        'id' => $user['id'],
                        'fio' => $user['fio'],
                        'level' => (int) $user['level'],
                        'policlinic' => $user['policlinic'],
                        'area' => isset($user['area']) ? $user['area'] : '',
                        'doctor' => isset($user['doctor']) ? $user['doctor'] : $user['fio'],
                    );
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
function setAuth($user)
{
    initSession();

    $_SESSION['user'] = array(
        'id' => $user['id'],
        'fio' => $user['fio'],
        'level' => (int) $user['level'],
        'policlinic' => $user['policlinic'],
        'area' => isset($user['area']) ? $user['area'] : '',
        'doctor' => isset($user['doctor']) ? $user['doctor'] : $user['fio'],
    );

    $cookieData = base64_encode(json_encode(array(
        'user_id' => $user['id'],
        'expires' => time() + (AUTH_COOKIE_DAYS * 86400),
    )));

    setcookie(AUTH_COOKIE_NAME, $cookieData, time() + (AUTH_COOKIE_DAYS * 86400), '/', '', false, true);
}

/**
 * Выход из системы
 */
function logout()
{
    initSession();
    $_SESSION = array();
    session_destroy();
    setcookie(AUTH_COOKIE_NAME, '', time() - 3600, '/');
}

/**
 * JSON ответ
 */
function jsonResponse($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

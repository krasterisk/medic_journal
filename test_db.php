<?php
/**
 * Диагностика подключения к БД и работы API
 * Удалите этот файл после отладки!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== Меджурнал — Диагностика ===\n\n";

// 1. Проверяем наличие config.php
echo "1. config.php: ";
if (file_exists(__DIR__ . '/config.php')) {
    echo "OK\n";
} else {
    echo "НЕ НАЙДЕН! Скопируйте config.example.php -> config.php и заполните данные.\n";
    exit;
}

// 2. Подключаем конфиг
echo "2. Загрузка config.php: ";
try {
    require_once __DIR__ . '/config.php';
    echo "OK\n";
} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    exit;
}

// 3. Проверяем константы
echo "3. Константы БД:\n";
echo "   DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'НЕ ОПРЕДЕЛЁН') . "\n";
echo "   DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'НЕ ОПРЕДЕЛЁН') . "\n";
echo "   DB_USER: " . (defined('DB_USER') ? DB_USER : 'НЕ ОПРЕДЕЛЁН') . "\n";
echo "   DB_PASS: " . (defined('DB_PASS') ? (strlen(DB_PASS) > 0 ? str_repeat('*', strlen(DB_PASS)) : 'ПУСТОЙ!') : 'НЕ ОПРЕДЕЛЁН') . "\n";
echo "   DB_CHARSET: " . (defined('DB_CHARSET') ? DB_CHARSET : 'НЕ ОПРЕДЕЛЁН') . "\n";

// 4. Проверяем PDO
echo "\n4. PDO расширение: ";
if (extension_loaded('pdo_mysql')) {
    echo "OK\n";
} else {
    echo "НЕ УСТАНОВЛЕН! Установите php-mysql или php-pdo-mysql.\n";
    echo "   Доступные PDO драйверы: " . implode(', ', PDO::getAvailableDrivers()) . "\n";
    exit;
}

// 5. Подключение к БД
echo "\n5. Подключение к MySQL: ";
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    echo "OK\n";
    
    // Версия MySQL
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "   Версия MySQL: {$version}\n";
} catch (PDOException $e) {
    echo "ОШИБКА!\n";
    echo "   Код: " . $e->getCode() . "\n";
    echo "   Сообщение: " . $e->getMessage() . "\n";
    
    // Подсказки
    $code = $e->getCode();
    if ($code == 2002) {
        echo "\n   >>> Сервер MySQL не доступен. Проверьте:\n";
        echo "   - Правильность хоста: " . DB_HOST . "\n";
        echo "   - Запущен ли MySQL на сервере\n";
        echo "   - Разрешены ли удалённые подключения\n";
        echo "   - Не блокирует ли файрвол порт 3306\n";
    } elseif ($code == 1045) {
        echo "\n   >>> Неверный логин/пароль. Проверьте DB_USER и DB_PASS в config.php\n";
    } elseif ($code == 1049) {
        echo "\n   >>> База данных '" . DB_NAME . "' не существует.\n";
    }
    exit;
}

// 6. Проверяем таблицы
echo "\n6. Таблицы:\n";
$requiredTables = ['gdb_users', 'gdb_registrations', 'gdb_active', 'gdb_sisters_journal'];
foreach ($requiredTables as $table) {
    echo "   {$table}: ";
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        echo "OK ({$count} записей)\n";
    } catch (PDOException $e) {
        echo "ОШИБКА: " . $e->getMessage() . "\n";
    }
}

// 7. Проверяем пользователей по ролям
echo "\n7. Пользователи по ролям:\n";
$roles = [1 => 'Врач', 4 => 'Участковая сестра', 8 => 'Медицинская сестра'];
foreach ($roles as $level => $name) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM gdb_users WHERE level = ?");
        $stmt->execute([$level]);
        $count = $stmt->fetchColumn();
        echo "   {$name} (level={$level}): {$count}\n";
        
        if ($count > 0) {
            $stmt2 = $pdo->prepare("SELECT id, fio, policlinic FROM gdb_users WHERE level = ? LIMIT 3");
            $stmt2->execute([$level]);
            $users = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as $u) {
                echo "      - #{$u['id']} {$u['fio']} (пол: {$u['policlinic']})\n";
            }
        }
    } catch (PDOException $e) {
        echo "   ОШИБКА: " . $e->getMessage() . "\n";
    }
}

// 8. Проверяем PHP версию
echo "\n8. PHP: " . PHP_VERSION . "\n";
echo "   Сервер: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'неизвестно') . "\n";

echo "\n=== Диагностика завершена ===\n";

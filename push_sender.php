<?php
/**
 * Скрипт отправки Push-уведомлений
 * Запускать через cron каждую минуту:
 * * * * * * php /path/to/push_sender.php
 * 
 * Требуется библиотека web-push-php:
 * composer require minishlink/web-push
 */

require_once __DIR__ . '/config.php';

// Проверяем наличие VAPID ключей
if (empty(VAPID_PUBLIC_KEY) || empty(VAPID_PRIVATE_KEY)) {
    echo "VAPID ключи не настроены. Запустите generate_vapid_keys.php\n";
    exit(1);
}

// Пробуем загрузить web-push библиотеку
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

$db = getDB();

// Получаем время последней проверки
$lastCheckFile = __DIR__ . '/last_push_check.txt';
if (file_exists($lastCheckFile)) {
    $lastCheck = file_get_contents($lastCheckFile);
} else {
    $lastCheck = date('Y-m-d H:i:s', strtotime('-1 minute'));
}

// Получаем всех пользователей с push-подписками
$stmt = $db->query("
    SELECT ps.*, u.fio, u.level, u.policlinic, u.doctor, u.area 
    FROM gdb_push_subscriptions ps 
    JOIN gdb_users u ON ps.user_id = u.id
");
$subscriptions = $stmt->fetchAll();

if (empty($subscriptions)) {
    file_put_contents($lastCheckFile, date('Y-m-d H:i:s'));
    exit(0);
}

foreach ($subscriptions as $sub) {
    $userId = $sub['user_id'];
    $level = (int)$sub['level'];
    $fio = $sub['fio'];
    $doctorName = $sub['doctor'] ?: $fio;
    $policlinic = $sub['policlinic'];
    
    $newCount = 0;
    $messages = [];
    
    // Проверяем gdb_registrations
    if ($level == ROLE_DOCTOR || $level == ROLE_NURSE) {
        $where = "reg_datetime > ?";
        $params = [$lastCheck];
        
        if ($level == ROLE_DOCTOR) {
            $where .= " AND reg_doctor LIKE ?";
            $params[] = '%' . $doctorName . '%';
        }
        if (!empty($policlinic)) {
            $where .= " AND reg_policlinic IN ({$policlinic})";
        }
        
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM gdb_registrations WHERE {$where}");
        $checkStmt->execute($params);
        $count = (int)$checkStmt->fetchColumn();
        
        if ($count > 0) {
            $newCount += $count;
            $messages[] = "Вызовы: {$count}";
        }
    }
    
    // Проверяем gdb_active
    if ($level == ROLE_DOCTOR || $level == ROLE_NURSE) {
        $where = "reg_datetime > ?";
        $params = [$lastCheck];
        
        if ($level == ROLE_DOCTOR) {
            $where .= " AND reg_doctor LIKE ?";
            $params[] = '%' . $doctorName . '%';
        }
        if (!empty($policlinic)) {
            $where .= " AND reg_policlinic IN ({$policlinic})";
        }
        
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM gdb_active WHERE {$where}");
        $checkStmt->execute($params);
        $count = (int)$checkStmt->fetchColumn();
        
        if ($count > 0) {
            $newCount += $count;
            $messages[] = "Активные: {$count}";
        }
    }
    
    // Проверяем gdb_sisters_journal
    if ($level == ROLE_DOCTOR || $level == ROLE_SISTER) {
        $where = "reg_datetime > ?";
        $params = [$lastCheck];
        
        if ($level == ROLE_SISTER) {
            $where .= " AND reg_sister LIKE ?";
            $params[] = '%' . $fio . '%';
        } elseif ($level == ROLE_DOCTOR) {
            $where .= " AND (reg_creator LIKE ? OR reg_user LIKE ?)";
            $params[] = '%' . $doctorName . '%';
            $params[] = '%' . $doctorName . '%';
        }
        if (!empty($policlinic)) {
            $where .= " AND reg_policlinic IN ({$policlinic})";
        }
        
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM gdb_sisters_journal WHERE {$where}");
        $checkStmt->execute($params);
        $count = (int)$checkStmt->fetchColumn();
        
        if ($count > 0) {
            $newCount += $count;
            $messages[] = "Журнал сестёр: {$count}";
        }
    }
    
    // Если есть новые записи — отправляем push
    if ($newCount > 0) {
        $payload = json_encode([
            'title' => 'Меджурнал — Новые записи',
            'body'  => implode(' | ', $messages),
            'icon'  => '/icons/icon-192.png',
            'badge' => '/icons/icon-72.png',
            'url'   => '/',
        ], JSON_UNESCAPED_UNICODE);
        
        sendPushNotification($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload);
        echo "Push sent to user {$userId} ({$fio}): {$newCount} new records\n";
    }
}

// Обновляем время последней проверки
file_put_contents($lastCheckFile, date('Y-m-d H:i:s'));
echo "Push check completed at " . date('Y-m-d H:i:s') . "\n";

/**
 * Отправка push уведомления
 */
function sendPushNotification(string $endpoint, string $p256dh, string $auth, string $payload): bool {
    // Если установлена библиотека web-push, используем её
    if (class_exists('\Minishlink\WebPush\WebPush')) {
        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject'    => VAPID_SUBJECT,
                'publicKey'  => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ],
        ]);
        
        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $endpoint,
            'publicKey' => $p256dh,
            'authToken' => $auth,
        ]);
        
        $report = $webPush->sendOneNotification($subscription, $payload);
        
        if ($report->isSuccess()) {
            return true;
        } else {
            echo "Push failed: " . $report->getReason() . "\n";
            
            // Если подписка истекла — удаляем
            if ($report->isSubscriptionExpired()) {
                $db = getDB();
                $stmt = $db->prepare("DELETE FROM gdb_push_subscriptions WHERE endpoint = ?");
                $stmt->execute([$endpoint]);
            }
            return false;
        }
    }
    
    // Fallback: простая cURL отправка (без шифрования)
    echo "web-push library not installed. Run: composer require minishlink/web-push\n";
    return false;
}

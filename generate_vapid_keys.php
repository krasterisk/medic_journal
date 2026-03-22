<?php
/**
 * Генерация VAPID ключей для Web Push
 * 
 * Требуется: composer require minishlink/web-push
 * Запуск: php generate_vapid_keys.php
 * 
 * Или используйте онлайн-генератор:
 * https://vapidkeys.com/
 */

// Попробуем использовать библиотеку
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    
    if (class_exists('\Minishlink\WebPush\VAPID')) {
        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        echo "=== VAPID ключи сгенерированы ===\n\n";
        echo "Публичный ключ (VAPID_PUBLIC_KEY):\n";
        echo $keys['publicKey'] . "\n\n";
        echo "Приватный ключ (VAPID_PRIVATE_KEY):\n";
        echo $keys['privateKey'] . "\n\n";
        echo "Добавьте эти ключи в config.php:\n";
        echo "define('VAPID_PUBLIC_KEY', '{$keys['publicKey']}');\n";
        echo "define('VAPID_PRIVATE_KEY', '{$keys['privateKey']}');\n";
        exit(0);
    }
}

// Fallback: генерация через OpenSSL
if (function_exists('openssl_pkey_new')) {
    $config = [
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ];
    
    $key = openssl_pkey_new($config);
    if ($key) {
        $details = openssl_pkey_get_details($key);
        
        $publicKey = base64url_encode($details['ec']['x'] . $details['ec']['y']);
        
        openssl_pkey_export($key, $privateKeyPem);
        
        echo "=== VAPID ключи (OpenSSL) ===\n\n";
        echo "Для полноценной работы Web Push уведомлений установите библиотеку:\n";
        echo "  composer require minishlink/web-push\n\n";
        echo "Затем запустите этот скрипт повторно.\n\n";
        echo "Или используйте онлайн-генератор: https://vapidkeys.com/\n";
        exit(0);
    }
}

echo "=== Инструкция по получению VAPID ключей ===\n\n";
echo "1. Установите composer (если нет): https://getcomposer.org/\n";
echo "2. Выполните: composer require minishlink/web-push\n";
echo "3. Запустите: php generate_vapid_keys.php\n";
echo "\nИли:\n";
echo "1. Зайдите на https://vapidkeys.com/\n";
echo "2. Скопируйте ключи\n";
echo "3. Вставьте их в config.php\n";

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

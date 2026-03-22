<?php
/**
 * API endpoint для Меджурнал PWA
 * Обрабатывает все AJAX запросы
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_users_by_role':
            handleGetUsersByRole();
            break;
        case 'login':
            handleLogin();
            break;
        case 'logout':
            handleLogout();
            break;
        case 'check_auth':
            handleCheckAuth();
            break;
        case 'get_registrations':
            handleGetRegistrations();
            break;
        case 'get_active':
            handleGetActive();
            break;
        case 'get_sisters_journal':
            handleGetSistersJournal();
            break;
        case 'poll_new':
            handlePollNew();
            break;
        case 'save_subscription':
            handleSaveSubscription();
            break;
        default:
            jsonResponse(['error' => 'Неизвестное действие'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => 'Ошибка сервера: ' . $e->getMessage()], 500);
}

/**
 * Получить пользователей по роли
 */
function handleGetUsersByRole(): void {
    $role = (int)($_GET['role'] ?? 0);
    
    $allowedRoles = [ROLE_DOCTOR, ROLE_SISTER, ROLE_NURSE];
    if (!in_array($role, $allowedRoles)) {
        jsonResponse(['error' => 'Недопустимая роль'], 400);
    }
    
    $db = getDB();
    $stmt = $db->prepare('SELECT id, fio, policlinic FROM gdb_users WHERE level = ? ORDER BY fio ASC');
    $stmt->execute([$role]);
    $users = $stmt->fetchAll();
    
    jsonResponse(['users' => $users]);
}

/**
 * Авторизация
 */
function handleLogin(): void {
    $userId = (int)($_POST['user_id'] ?? 0);
    $password = $_POST['password'] ?? '';
    
    if (!$userId || !$password) {
        jsonResponse(['error' => 'Укажите пользователя и пароль'], 400);
    }
    
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM gdb_users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        jsonResponse(['error' => 'Пользователь не найден'], 404);
    }
    
    // Пароль хранится в открытом виде
    if ($user['password'] !== $password) {
        jsonResponse(['error' => 'Неверный пароль'], 401);
    }
    
    // Проверяем что роль допустима
    $allowedRoles = [ROLE_DOCTOR, ROLE_SISTER, ROLE_NURSE];
    if (!in_array((int)$user['level'], $allowedRoles)) {
        jsonResponse(['error' => 'Данная роль не поддерживается в этом приложении'], 403);
    }
    
    setAuth($user);
    
    global $ROLE_NAMES;
    jsonResponse([
        'success' => true,
        'user' => [
            'id'         => $user['id'],
            'fio'        => $user['fio'],
            'level'      => (int)$user['level'],
            'role_name'  => $ROLE_NAMES[(int)$user['level']] ?? 'Неизвестно',
            'policlinic' => $user['policlinic'],
            'area'       => $user['area'],
        ]
    ]);
}

/**
 * Выход
 */
function handleLogout(): void {
    logout();
    jsonResponse(['success' => true]);
}

/**
 * Проверка авторизации
 */
function handleCheckAuth(): void {
    $user = checkAuth();
    if ($user) {
        global $ROLE_NAMES;
        jsonResponse([
            'authenticated' => true,
            'user' => array_merge($user, [
                'role_name' => $ROLE_NAMES[$user['level']] ?? 'Неизвестно'
            ])
        ]);
    } else {
        jsonResponse(['authenticated' => false]);
    }
}

/**
 * Получить записи из gdb_registrations
 */
function handleGetRegistrations(): void {
    $user = checkAuth();
    if (!$user) {
        jsonResponse(['error' => 'Не авторизован'], 401);
    }
    
    $db = getDB();
    $params = [];
    $where = ['1=1'];
    
    // Фильтр по дате
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
    $dateTo   = $_GET['date_to'] ?? date('Y-m-d');
    $where[] = 'DATE(reg_datetime) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;
    
    // Фильтр по врачу (для уровня Врач)
    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = $user['doctor'] ?? $user['fio'];
        $where[] = 'reg_doctor LIKE ?';
        $params[] = '%' . $doctorName . '%';
    }
    
    // Фильтр по поликлинике
    if (!empty($user['policlinic'])) {
        $where[] = 'reg_policlinic IN (' . $user['policlinic'] . ')';
    }
    
    // Текстовый фильтр
    if (!empty($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $where[] = '(reg_fio LIKE ? OR reg_phone LIKE ? OR reg_diagnoz LIKE ? OR reg_doctor LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }
    
    // Фильтр по статусу
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where[] = 'reg_status = ?';
        $params[] = $_GET['status'];
    }
    
    $whereStr = implode(' AND ', $where);
    
    // Подсчёт общего количества
    $countStmt = $db->prepare("SELECT COUNT(*) FROM gdb_registrations WHERE {$whereStr}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Пагинация
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = RECORDS_PER_PAGE;
    $offset = ($page - 1) * $limit;
    
    $stmt = $db->prepare("SELECT * FROM gdb_registrations WHERE {$whereStr} ORDER BY reg_datetime DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    jsonResponse([
        'records' => $records,
        'total'   => $total,
        'page'    => $page,
        'pages'   => ceil($total / $limit),
    ]);
}

/**
 * Получить записи из gdb_active
 */
function handleGetActive(): void {
    $user = checkAuth();
    if (!$user) {
        jsonResponse(['error' => 'Не авторизован'], 401);
    }
    
    $db = getDB();
    $params = [];
    $where = ['1=1'];
    
    // Фильтр по дате
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
    $dateTo   = $_GET['date_to'] ?? date('Y-m-d');
    $where[] = 'DATE(reg_datetime) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;
    
    // Фильтр по врачу
    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = $user['doctor'] ?? $user['fio'];
        $where[] = 'reg_doctor LIKE ?';
        $params[] = '%' . $doctorName . '%';
    }
    
    // Фильтр по создателю (для оператора/регистратора)
    // Для сестры — не фильтруем по доктору, но по создателю
    
    // Фильтр по поликлинике
    if (!empty($user['policlinic'])) {
        $where[] = 'reg_policlinic IN (' . $user['policlinic'] . ')';
    }
    
    // Текстовый фильтр
    if (!empty($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $where[] = '(reg_fio LIKE ? OR reg_diagnoz LIKE ? OR reg_doctor LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }
    
    $whereStr = implode(' AND ', $where);
    
    // Подсчёт
    $countStmt = $db->prepare("SELECT COUNT(*) FROM gdb_active WHERE {$whereStr}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Пагинация
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = RECORDS_PER_PAGE;
    $offset = ($page - 1) * $limit;
    
    $stmt = $db->prepare("SELECT * FROM gdb_active WHERE {$whereStr} ORDER BY reg_datetime DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    jsonResponse([
        'records' => $records,
        'total'   => $total,
        'page'    => $page,
        'pages'   => ceil($total / $limit),
    ]);
}

/**
 * Получить записи из gdb_sisters_journal
 */
function handleGetSistersJournal(): void {
    $user = checkAuth();
    if (!$user) {
        jsonResponse(['error' => 'Не авторизован'], 401);
    }
    
    $db = getDB();
    $params = [];
    $where = ['1=1'];
    
    // Фильтр по дате
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
    $dateTo   = $_GET['date_to'] ?? date('Y-m-d');
    $where[] = 'DATE(reg_datetime) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;
    
    // Фильтр по сестре (для участковой сестры)
    if ($user['level'] == ROLE_SISTER) {
        $where[] = 'reg_sister LIKE ?';
        $params[] = '%' . $user['fio'] . '%';
    }
    
    // Фильтр по врачу (для врача — по reg_creator или другому полю)
    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = $user['doctor'] ?? $user['fio'];
        $where[] = '(reg_creator LIKE ? OR reg_user LIKE ?)';
        $params[] = '%' . $doctorName . '%';
        $params[] = '%' . $doctorName . '%';
    }
    
    // Фильтр по поликлинике
    if (!empty($user['policlinic'])) {
        $where[] = 'reg_policlinic IN (' . $user['policlinic'] . ')';
    }
    
    // Для участковой сестры — только невыполненные
    if ($user['level'] == ROLE_SISTER) {
        $where[] = 'reg_status = 0';
    }
    
    // Текстовый фильтр
    if (!empty($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $where[] = '(reg_fio LIKE ? OR reg_sister LIKE ? OR reg_naznach LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }
    
    $whereStr = implode(' AND ', $where);
    
    // Подсчёт
    $countStmt = $db->prepare("SELECT COUNT(*) FROM gdb_sisters_journal WHERE {$whereStr}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    // Пагинация
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = RECORDS_PER_PAGE;
    $offset = ($page - 1) * $limit;
    
    $stmt = $db->prepare("SELECT * FROM gdb_sisters_journal WHERE {$whereStr} ORDER BY reg_datetime DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    jsonResponse([
        'records' => $records,
        'total'   => $total,
        'page'    => $page,
        'pages'   => ceil($total / $limit),
    ]);
}

/**
 * Polling для проверки новых записей
 */
function handlePollNew(): void {
    $user = checkAuth();
    if (!$user) {
        jsonResponse(['error' => 'Не авторизован'], 401);
    }
    
    $lastCheckTime = $_GET['last_check'] ?? date('Y-m-d H:i:s', strtotime('-1 minute'));
    
    $db = getDB();
    $results = [
        'registrations' => 0,
        'active'        => 0,
        'sisters'       => 0,
        'new_records'   => [],
    ];
    
    // Проверяем gdb_registrations
    $regWhere = "reg_datetime > ?";
    $regParams = [$lastCheckTime];
    
    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = $user['doctor'] ?? $user['fio'];
        $regWhere .= " AND reg_doctor LIKE ?";
        $regParams[] = '%' . $doctorName . '%';
    }
    if (!empty($user['policlinic'])) {
        $regWhere .= " AND reg_policlinic IN (" . $user['policlinic'] . ")";
    }
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM gdb_registrations WHERE {$regWhere}");
    $stmt->execute($regParams);
    $results['registrations'] = (int)$stmt->fetchColumn();
    
    if ($results['registrations'] > 0) {
        $stmt = $db->prepare("SELECT reg_id, reg_fio, reg_phone, reg_datetime, reg_diagnoz FROM gdb_registrations WHERE {$regWhere} ORDER BY reg_datetime DESC LIMIT 5");
        $stmt->execute($regParams);
        $results['new_records']['registrations'] = $stmt->fetchAll();
    }
    
    // Проверяем gdb_active
    $actWhere = "reg_datetime > ?";
    $actParams = [$lastCheckTime];
    
    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = $user['doctor'] ?? $user['fio'];
        $actWhere .= " AND reg_doctor LIKE ?";
        $actParams[] = '%' . $doctorName . '%';
    }
    if (!empty($user['policlinic'])) {
        $actWhere .= " AND reg_policlinic IN (" . $user['policlinic'] . ")";
    }
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM gdb_active WHERE {$actWhere}");
    $stmt->execute($actParams);
    $results['active'] = (int)$stmt->fetchColumn();
    
    if ($results['active'] > 0) {
        $stmt = $db->prepare("SELECT reg_id, reg_fio, reg_datetime, reg_diagnoz FROM gdb_active WHERE {$actWhere} ORDER BY reg_datetime DESC LIMIT 5");
        $stmt->execute($actParams);
        $results['new_records']['active'] = $stmt->fetchAll();
    }
    
    // Проверяем gdb_sisters_journal
    $sisWhere = "reg_datetime > ?";
    $sisParams = [$lastCheckTime];
    
    if ($user['level'] == ROLE_SISTER) {
        $sisWhere .= " AND reg_sister LIKE ?";
        $sisParams[] = '%' . $user['fio'] . '%';
    } elseif ($user['level'] == ROLE_DOCTOR) {
        $doctorName = $user['doctor'] ?? $user['fio'];
        $sisWhere .= " AND (reg_creator LIKE ? OR reg_user LIKE ?)";
        $sisParams[] = '%' . $doctorName . '%';
        $sisParams[] = '%' . $doctorName . '%';
    }
    if (!empty($user['policlinic'])) {
        $sisWhere .= " AND reg_policlinic IN (" . $user['policlinic'] . ")";
    }
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM gdb_sisters_journal WHERE {$sisWhere}");
    $stmt->execute($sisParams);
    $results['sisters'] = (int)$stmt->fetchColumn();
    
    if ($results['sisters'] > 0) {
        $stmt = $db->prepare("SELECT reg_id, reg_fio, reg_datetime, reg_naznach FROM gdb_sisters_journal WHERE {$sisWhere} ORDER BY reg_datetime DESC LIMIT 5");
        $stmt->execute($sisParams);
        $results['new_records']['sisters'] = $stmt->fetchAll();
    }
    
    $results['server_time'] = date('Y-m-d H:i:s');
    $results['has_new'] = ($results['registrations'] + $results['active'] + $results['sisters']) > 0;
    
    jsonResponse($results);
}

/**
 * Сохранение Push-подписки
 */
function handleSaveSubscription(): void {
    $user = checkAuth();
    if (!$user) {
        jsonResponse(['error' => 'Не авторизован'], 401);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $subscription = $input['subscription'] ?? null;
    
    if (!$subscription) {
        jsonResponse(['error' => 'Нет данных подписки'], 400);
    }
    
    $db = getDB();
    
    // Создаём таблицу для push подписок, если не существует
    $db->exec("CREATE TABLE IF NOT EXISTS gdb_push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
    
    // Удаляем старые подписки этого пользователя
    $stmt = $db->prepare("DELETE FROM gdb_push_subscriptions WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    
    // Сохраняем новую подписку
    $stmt = $db->prepare("INSERT INTO gdb_push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $user['id'],
        $subscription['endpoint'],
        $subscription['keys']['p256dh'] ?? '',
        $subscription['keys']['auth'] ?? '',
    ]);
    
    jsonResponse(['success' => true]);
}

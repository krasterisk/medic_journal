<?php
/**
 * API endpoint для Меджурнал PWA
 * Совместимость: PHP 5.5+
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Ловим фатальные ошибки PHP и отдаём как JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode(array(
            'error' => 'PHP Fatal: ' . $error['message'],
            'file'  => basename($error['file']) . ':' . $error['line'],
        ), JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

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
        case 'complete_call':
            handleCompleteCall();
            break;
        default:
            jsonResponse(array('error' => 'Неизвестное действие'), 400);
    }
} catch (Exception $e) {
    jsonResponse(array(
        'error' => 'Ошибка сервера: ' . $e->getMessage(),
        'code'  => $e->getCode(),
        'trace' => basename($e->getFile()) . ':' . $e->getLine(),
    ), 500);
}

// ====================== HELPER ======================
function _get($key, $default = '') {
    return isset($_GET[$key]) ? $_GET[$key] : $default;
}
function _post($key, $default = '') {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}
function _arr($arr, $key, $default = '') {
    return isset($arr[$key]) ? $arr[$key] : $default;
}

// ====================== HANDLERS ======================

function handleGetUsersByRole() {
    $role = (int)_get('role', 0);

    $allowedRoles = array(ROLE_DOCTOR, ROLE_SISTER, ROLE_NURSE);
    if (!in_array($role, $allowedRoles)) {
        jsonResponse(array('error' => 'Недопустимая роль'), 400);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, fio, policlinic FROM gdb_users WHERE level = ? ORDER BY fio ASC');
    $stmt->execute(array($role));
    $users = $stmt->fetchAll();

    jsonResponse(array('users' => $users));
}

function handleLogin() {
    $userId   = (int)_post('user_id', 0);
    $password = _post('password', '');

    if (!$userId || !$password) {
        jsonResponse(array('error' => 'Укажите пользователя и пароль'), 400);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM gdb_users WHERE id = ?');
    $stmt->execute(array($userId));
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(array('error' => 'Пользователь не найден'), 404);
    }

    if ($user['password'] !== $password) {
        jsonResponse(array('error' => 'Неверный пароль'), 401);
    }

    $allowedRoles = array(ROLE_DOCTOR, ROLE_SISTER, ROLE_NURSE);
    if (!in_array((int)$user['level'], $allowedRoles)) {
        jsonResponse(array('error' => 'Данная роль не поддерживается'), 403);
    }

    setAuth($user);

    global $ROLE_NAMES;
    $roleName = isset($ROLE_NAMES[(int)$user['level']]) ? $ROLE_NAMES[(int)$user['level']] : 'Неизвестно';

    jsonResponse(array(
        'success' => true,
        'user' => array(
            'id'         => $user['id'],
            'fio'        => $user['fio'],
            'level'      => (int)$user['level'],
            'role_name'  => $roleName,
            'policlinic' => $user['policlinic'],
            'area'       => _arr($user, 'area', ''),
        )
    ));
}

function handleLogout() {
    logout();
    jsonResponse(array('success' => true));
}

function handleCheckAuth() {
    $user = checkAuth();
    if ($user) {
        global $ROLE_NAMES;
        $roleName = isset($ROLE_NAMES[$user['level']]) ? $ROLE_NAMES[$user['level']] : 'Неизвестно';
        jsonResponse(array(
            'authenticated' => true,
            'user' => array_merge($user, array('role_name' => $roleName))
        ));
    } else {
        jsonResponse(array('authenticated' => false));
    }
}

function handleGetRegistrations() {
    $user = checkAuth();
    if (!$user) { jsonResponse(array('error' => 'Не авторизован'), 401); }

    $db = getDB();
    $params = array();
    $where = array('1=1');

    $dateFrom = _get('date_from', date('Y-m-d'));
    $dateTo   = _get('date_to', date('Y-m-d'));
    $where[] = 'DATE(reg_datetime) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;

    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = !empty($user['doctor']) ? $user['doctor'] : $user['fio'];
        $where[] = 'reg_doctor LIKE ?';
        $params[] = '%' . $doctorName . '%';
    }

    if (!empty($user['policlinic'])) {
        $where[] = 'reg_policlinic IN (' . $user['policlinic'] . ')';
    }

    $search = _get('search', '');
    if ($search !== '') {
        $s = '%' . $search . '%';
        $where[] = '(reg_fio LIKE ? OR reg_phone LIKE ? OR reg_diagnoz LIKE ? OR reg_doctor LIKE ?)';
        $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    }

    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where[] = 'reg_status = ?';
        $params[] = $_GET['status'];
    }

    $whereStr = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM gdb_registrations WHERE {$whereStr}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $page   = max(1, (int)_get('page', 1));
    $limit  = RECORDS_PER_PAGE;
    $offset = ($page - 1) * $limit;

    $stmt = $db->prepare("SELECT * FROM gdb_registrations WHERE {$whereStr} ORDER BY CASE WHEN reg_status = '\u0412\u044b\u043f\u043e\u043b\u043d\u0435\u043d\u043e' THEN 1 ELSE 0 END ASC, reg_datetime ASC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    jsonResponse(array(
        'records' => $records,
        'total'   => $total,
        'page'    => $page,
        'pages'   => (int)ceil($total / $limit),
    ));
}

function handleGetActive() {
    $user = checkAuth();
    if (!$user) { jsonResponse(array('error' => 'Не авторизован'), 401); }

    $db = getDB();
    $params = array();
    $where = array('1=1');

    $dateFrom = _get('date_from', date('Y-m-d'));
    $dateTo   = _get('date_to', date('Y-m-d'));
    $where[] = 'DATE(reg_datetime) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;

    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = !empty($user['doctor']) ? $user['doctor'] : $user['fio'];
        $where[] = 'reg_doctor LIKE ?';
        $params[] = '%' . $doctorName . '%';
    }

    if (!empty($user['policlinic'])) {
        $where[] = 'reg_policlinic IN (' . $user['policlinic'] . ')';
    }

    $search = _get('search', '');
    if ($search !== '') {
        $s = '%' . $search . '%';
        $where[] = '(reg_fio LIKE ? OR reg_diagnoz LIKE ? OR reg_doctor LIKE ?)';
        $params[] = $s; $params[] = $s; $params[] = $s;
    }

    $whereStr = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM gdb_active WHERE {$whereStr}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $page   = max(1, (int)_get('page', 1));
    $limit  = RECORDS_PER_PAGE;
    $offset = ($page - 1) * $limit;

    $stmt = $db->prepare("SELECT * FROM gdb_active WHERE {$whereStr} ORDER BY CASE WHEN reg_status = '\u0412\u044b\u043f\u043e\u043b\u043d\u0435\u043d\u043e' THEN 1 ELSE 0 END ASC, reg_datetime ASC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    jsonResponse(array(
        'records' => $records,
        'total'   => $total,
        'page'    => $page,
        'pages'   => (int)ceil($total / $limit),
    ));
}

function handleGetSistersJournal() {
    $user = checkAuth();
    if (!$user) { jsonResponse(array('error' => 'Не авторизован'), 401); }

    $db = getDB();
    $params = array();
    $where = array('1=1');

    $dateFrom = _get('date_from', date('Y-m-d'));
    $dateTo   = _get('date_to', date('Y-m-d'));
    $where[] = 'DATE(reg_datetime) BETWEEN ? AND ?';
    $params[] = $dateFrom;
    $params[] = $dateTo;

    if ($user['level'] == ROLE_SISTER) {
        $where[] = 'reg_sister LIKE ?';
        $params[] = '%' . $user['fio'] . '%';
    }
    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = !empty($user['doctor']) ? $user['doctor'] : $user['fio'];
        $where[] = '(reg_creator LIKE ? OR reg_user LIKE ?)';
        $params[] = '%' . $doctorName . '%';
        $params[] = '%' . $doctorName . '%';
    }

    if (!empty($user['policlinic'])) {
        $where[] = 'reg_policlinic IN (' . $user['policlinic'] . ')';
    }

    if ($user['level'] == ROLE_SISTER) {
        $where[] = 'reg_status = 0';
    }

    $search = _get('search', '');
    if ($search !== '') {
        $s = '%' . $search . '%';
        $where[] = '(reg_fio LIKE ? OR reg_sister LIKE ? OR reg_naznach LIKE ?)';
        $params[] = $s; $params[] = $s; $params[] = $s;
    }

    $whereStr = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM gdb_sisters_journal WHERE {$whereStr}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $page   = max(1, (int)_get('page', 1));
    $limit  = RECORDS_PER_PAGE;
    $offset = ($page - 1) * $limit;

    $stmt = $db->prepare("SELECT * FROM gdb_sisters_journal WHERE {$whereStr} ORDER BY CASE WHEN reg_status = 1 THEN 1 ELSE 0 END ASC, reg_datetime ASC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    jsonResponse(array(
        'records' => $records,
        'total'   => $total,
        'page'    => $page,
        'pages'   => (int)ceil($total / $limit),
    ));
}

function handlePollNew() {
    $user = checkAuth();
    if (!$user) { jsonResponse(array('error' => 'Не авторизован'), 401); }

    $lastCheckTime = _get('last_check', date('Y-m-d H:i:s', strtotime('-1 minute')));

    $db = getDB();
    $results = array(
        'registrations' => 0,
        'active'        => 0,
        'sisters'       => 0,
        'new_records'   => array(),
    );

    // gdb_registrations
    $regWhere  = "reg_datetime > ?";
    $regParams = array($lastCheckTime);
    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = !empty($user['doctor']) ? $user['doctor'] : $user['fio'];
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

    // gdb_active
    $actWhere  = "reg_datetime > ?";
    $actParams = array($lastCheckTime);
    if ($user['level'] == ROLE_DOCTOR) {
        $doctorName = !empty($user['doctor']) ? $user['doctor'] : $user['fio'];
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

    // gdb_sisters_journal
    $sisWhere  = "reg_datetime > ?";
    $sisParams = array($lastCheckTime);
    if ($user['level'] == ROLE_SISTER) {
        $sisWhere .= " AND reg_sister LIKE ?";
        $sisParams[] = '%' . $user['fio'] . '%';
    } elseif ($user['level'] == ROLE_DOCTOR) {
        $doctorName = !empty($user['doctor']) ? $user['doctor'] : $user['fio'];
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

function handleSaveSubscription() {
    $user = checkAuth();
    if (!$user) { jsonResponse(array('error' => 'Не авторизован'), 401); }

    $input = json_decode(file_get_contents('php://input'), true);
    $subscription = isset($input['subscription']) ? $input['subscription'] : null;

    if (!$subscription) {
        jsonResponse(array('error' => 'Нет данных подписки'), 400);
    }

    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS gdb_push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");

    $stmt = $db->prepare("DELETE FROM gdb_push_subscriptions WHERE user_id = ?");
    $stmt->execute(array($user['id']));

    $p256dh = isset($subscription['keys']['p256dh']) ? $subscription['keys']['p256dh'] : '';
    $auth   = isset($subscription['keys']['auth'])   ? $subscription['keys']['auth']   : '';

    $stmt = $db->prepare("INSERT INTO gdb_push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
    $stmt->execute(array(
        $user['id'],
        $subscription['endpoint'],
        $p256dh,
        $auth,
    ));

    jsonResponse(array('success' => true));
}

/**
 * Завершение вызова (установка диагноза и статуса)
 */
function handleCompleteCall() {
    $user = checkAuth();
    if (!$user) { jsonResponse(array('error' => 'Не авторизован'), 401); }

    // Только врач может завершать вызовы
    if ($user['level'] != ROLE_DOCTOR) {
        jsonResponse(array('error' => 'Недостаточно прав'), 403);
    }

    $regId    = (int)_post('reg_id', 0);
    $diagnoz  = trim(_post('diagnoz', ''));
    $table    = _post('table', 'gdb_registrations');

    if (!$regId) {
        jsonResponse(array('error' => 'Не указан ID записи'), 400);
    }
    if ($diagnoz === '') {
        jsonResponse(array('error' => 'Укажите диагноз'), 400);
    }

    // Разрешённые таблицы
    $allowedTables = array('gdb_registrations', 'gdb_active');
    if (!in_array($table, $allowedTables)) {
        jsonResponse(array('error' => 'Недопустимая таблица'), 400);
    }

    $db = getDB();
    $now = date('Y-m-d H:i:s');

    $stmt = $db->prepare(
        "UPDATE {$table} SET reg_diagnoz = ?, reg_status = ?, reg_donedate = ? WHERE reg_id = ?"
    );
    $stmt->execute(array($diagnoz, 'Выполнено', $now, $regId));

    if ($stmt->rowCount() === 0) {
        jsonResponse(array('error' => 'Запись не найдена'), 404);
    }

    jsonResponse(array('success' => true, 'donedate' => $now));
}

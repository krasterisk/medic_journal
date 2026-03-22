<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f172a">
    <meta name="description" content="<?php echo htmlspecialchars(MO_NAME); ?> — Меджурнал. Система уведомлений для медицинских работников.">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Меджурнал">
    <title><?php echo htmlspecialchars(MO_NAME); ?> — Меджурнал</title>
    
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/svg+xml" sizes="192x192" href="icon.php?size=192">
    <link rel="apple-touch-icon" href="icon.php?size=192">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- ======================= LOGIN SCREEN ======================= -->
    <div id="login-screen" class="screen active">
        <div class="login-container">
            <div class="login-logo">
                <div class="logo-icon">
                    <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <rect width="48" height="48" rx="12" fill="url(#logo-grad)"/>
                        <path d="M24 12v24M12 24h24" stroke="#fff" stroke-width="3.5" stroke-linecap="round"/>
                        <defs>
                            <linearGradient id="logo-grad" x1="0" y1="0" x2="48" y2="48">
                                <stop stop-color="#6366f1"/>
                                <stop offset="1" stop-color="#8b5cf6"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <h1>Меджурнал</h1>
                <p class="login-subtitle"><?php echo htmlspecialchars(MO_NAME); ?></p>
            </div>
            
            <form id="login-form" autocomplete="off">
                <div class="form-group">
                    <label for="role-select">Роль</label>
                    <div class="select-wrapper">
                        <select id="role-select">
                            <option value="1" selected>Врач</option>
                            <option value="4">Участковая сестра</option>
                            <option value="8">Медицинская сестра</option>
                        </select>
                        <svg class="select-arrow" width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="user-select">Пользователь</label>
                    <div class="select-wrapper">
                        <select id="user-select" disabled>
                            <option value="">Загрузка...</option>
                        </select>
                        <svg class="select-arrow" width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M6 8l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password-input">Пароль</label>
                    <div class="password-wrapper">
                        <input type="password" id="password-input" placeholder="Введите пароль" autocomplete="current-password">
                        <button type="button" class="toggle-password" id="toggle-password">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                                <path d="M10 4C4.667 4 1 10 1 10s3.667 6 9 6 9-6 9-6-3.667-6-9-6z" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="10" cy="10" r="3" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-primary" id="login-btn">
                    <span class="btn-text">Войти</span>
                    <span class="btn-loader hidden">
                        <svg class="spinner" width="20" height="20" viewBox="0 0 20 20">
                            <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" fill="none" stroke-dasharray="40" stroke-dashoffset="10"/>
                        </svg>
                    </span>
                </button>
                
                <div id="login-error" class="error-message hidden"></div>
            </form>
        </div>
    </div>
    
    <!-- ======================= MAIN APP SCREEN ======================= -->
    <div id="app-screen" class="screen">
        <!-- Top Header -->
        <header class="app-header">
            <div class="header-left">
                <div class="header-logo">
                    <svg width="28" height="28" viewBox="0 0 48 48" fill="none">
                        <rect width="48" height="48" rx="12" fill="url(#logo-grad-sm)"/>
                        <path d="M24 14v20M14 24h20" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
                        <defs>
                            <linearGradient id="logo-grad-sm" x1="0" y1="0" x2="48" y2="48">
                                <stop stop-color="#6366f1"/>
                                <stop offset="1" stop-color="#8b5cf6"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <div class="header-info">
                    <span class="header-title"><?php echo htmlspecialchars(MO_NAME); ?></span>
                    <span class="header-user" id="header-user-name"></span>
                </div>
            </div>
            <div class="header-right">
                <button class="header-btn" id="btn-notifications" title="Вкл/Выкл звук уведомлений">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 01-3.46 0"/>
                    </svg>
                    <span class="notification-badge hidden" id="notification-badge">0</span>
                </button>
                <button class="header-btn" id="btn-logout" title="Выход">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                        <polyline points="16,17 21,12 16,7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </button>
            </div>
        </header>
        
        <!-- Filters Bar -->
        <div class="filters-bar">
            <div class="filters-row">
                <div class="filter-item date-filter">
                    <label>С</label>
                    <input type="date" id="filter-date-from" class="filter-input">
                </div>
                <div class="filter-item date-filter">
                    <label>По</label>
                    <input type="date" id="filter-date-to" class="filter-input">
                </div>
                <div class="filter-item search-filter">
                    <input type="text" id="filter-search" class="filter-input" placeholder="Поиск по ФИО, телефону...">
                    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </div>
                <button class="btn-filter" id="btn-apply-filter" title="Применить">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="22,3 2,3 10,12.46 10,19 14,21 14,12.46"/>
                    </svg>
                </button>
            </div>
        </div>
        
        <!-- Tab Navigation -->
        <nav class="tab-nav" id="tab-nav">
            <button class="tab-btn active" data-tab="registrations">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15.05 5A5 5 0 0119 8.95M15.05 1A9 9 0 0123 8.94M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72"/>
                </svg>
                <span>Вызовы</span>
                <span class="tab-badge hidden" id="badge-registrations">0</span>
            </button>
            <button class="tab-btn" data-tab="active">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                </svg>
                <span>Активные</span>
                <span class="tab-badge hidden" id="badge-active">0</span>
            </button>
            <button class="tab-btn" data-tab="sisters">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/>
                    <rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
                </svg>
                <span>Журнал сестёр</span>
                <span class="tab-badge hidden" id="badge-sisters">0</span>
            </button>
        </nav>
        
        <!-- Content Area -->
        <main class="content-area" id="content-area">
            <!-- Registrations Tab -->
            <div class="tab-content active" id="tab-registrations">
                <div class="table-wrapper">
                    <div class="records-list" id="list-registrations"></div>
                </div>
                <div class="pagination" id="pagination-registrations"></div>
            </div>
            
            <!-- Active Tab -->
            <div class="tab-content" id="tab-active">
                <div class="table-wrapper">
                    <div class="records-list" id="list-active"></div>
                </div>
                <div class="pagination" id="pagination-active"></div>
            </div>
            
            <!-- Sisters Journal Tab -->
            <div class="tab-content" id="tab-sisters">
                <div class="table-wrapper">
                    <div class="records-list" id="list-sisters"></div>
                </div>
                <div class="pagination" id="pagination-sisters"></div>
            </div>
            
            <!-- Loading overlay -->
            <div class="loading-overlay hidden" id="loading-overlay">
                <div class="loading-spinner">
                    <svg width="40" height="40" viewBox="0 0 40 40">
                        <circle cx="20" cy="20" r="16" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="80" stroke-dashoffset="20"/>
                    </svg>
                    <span>Загрузка...</span>
                </div>
            </div>
        </main>
        
        <!-- Notification Toast -->
        <div class="toast-container" id="toast-container"></div>
        
        <!-- Record Detail Modal -->
        <div class="modal-overlay hidden" id="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="modal-title">Детали записи</h3>
                    <button class="modal-close" id="modal-close">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="modal-body" id="modal-body"></div>
            </div>
        </div>
        
        <!-- PWA Install Prompt -->
        <div class="install-prompt hidden" id="install-prompt">
            <div class="install-content">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                    <polyline points="7,10 12,15 17,10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <div>
                    <strong>Установить приложение</strong>
                    <p>Добавьте приложение на домашний экран для быстрого доступа</p>
                </div>
                <button class="btn-install" id="btn-install">Установить</button>
                <button class="btn-dismiss" id="btn-dismiss-install">&times;</button>
            </div>
        </div>
        
        <!-- Polling Status indicator -->
        <div class="polling-status" id="polling-status">
            <div class="polling-dot"></div>
            <span>Онлайн</span>
            <span class="app-version">v<?php echo APP_VERSION; ?></span>
        </div>
    </div>
    
    <script>window.MO_NAME = <?php echo json_encode(MO_NAME, JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="app.js"></script>
</body>
</html>

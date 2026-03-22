/**
 * Меджурнал PWA — Main Application JavaScript
 * Medical Journal — Doctor notification system
 */

(function() {
'use strict';

// ===================== STATE =====================
const state = {
    user: null,
    currentTab: 'registrations',
    pages: { registrations: 1, active: 1, sisters: 1 },
    lastCheck: null,
    pollingTimer: null,
    pollingInterval: 30000, // 30 seconds — faster for HTTP mode
    newCounts: { registrations: 0, active: 0, sisters: 0 },
    soundEnabled: true,
    titleBlinking: false,
    originalTitle: (window.MO_NAME || 'Меджурнал') + ' — Меджурнал',
    keepAliveTimer: null,
};

// ===================== DOM REFS =====================
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

const DOM = {
    loginScreen: $('#login-screen'),
    appScreen: $('#app-screen'),
    loginForm: $('#login-form'),
    roleSelect: $('#role-select'),
    userSelect: $('#user-select'),
    passwordInput: $('#password-input'),
    togglePassword: $('#toggle-password'),
    loginBtn: $('#login-btn'),
    loginError: $('#login-error'),
    headerUserName: $('#header-user-name'),
    btnLogout: $('#btn-logout'),
    btnNotifications: $('#btn-notifications'),
    notificationBadge: $('#notification-badge'),
    filterDateFrom: $('#filter-date-from'),
    filterDateTo: $('#filter-date-to'),
    filterSearch: $('#filter-search'),
    btnApplyFilter: $('#btn-apply-filter'),
    tabNav: $('#tab-nav'),
    contentArea: $('#content-area'),
    loadingOverlay: $('#loading-overlay'),
    toastContainer: $('#toast-container'),
    modalOverlay: $('#modal-overlay'),
    modalTitle: $('#modal-title'),
    modalBody: $('#modal-body'),
    modalClose: $('#modal-close'),
    installPrompt: $('#install-prompt'),
    btnInstall: $('#btn-install'),
    btnDismissInstall: $('#btn-dismiss-install'),
    pollingStatus: $('#polling-status'),
    modalFooter: $('#modal-footer'),
    btnCompleteCall: $('#btn-complete-call'),
    diagnozOverlay: $('#diagnoz-overlay'),
    diagnozClose: $('#diagnoz-close'),
    diagnozPatient: $('#diagnoz-patient'),
    diagnozInput: $('#diagnoz-input'),
    diagnozError: $('#diagnoz-error'),
    btnConfirmComplete: $('#btn-confirm-complete'),
};

// ===================== INITIALIZATION =====================
async function init() {
    setupEventListeners();
    setDefaultDates();
    initSoundSystem();
    
    // Check if user is already authenticated
    const authResult = await apiCall('check_auth');
    if (authResult && authResult.authenticated) {
        state.user = authResult.user;
        showApp();
    } else {
        showLogin();
        loadUsersByRole();
    }
    
    // Register service worker (works on HTTPS only, fallback gracefully)
    registerServiceWorker();
    
    // Setup PWA install prompt
    setupInstallPrompt();
    
    // Keep page alive (prevent mobile browser from suspending tab)
    startKeepAlive();
    
    // Load sound preference
    state.soundEnabled = localStorage.getItem('medjournal_sound') !== 'off';
}

// ===================== EVENT LISTENERS =====================
function setupEventListeners() {
    // Login
    DOM.roleSelect.addEventListener('change', loadUsersByRole);
    DOM.loginForm.addEventListener('submit', handleLogin);
    DOM.togglePassword.addEventListener('click', () => {
        const type = DOM.passwordInput.type === 'password' ? 'text' : 'password';
        DOM.passwordInput.type = type;
    });
    
    // Logout
    DOM.btnLogout.addEventListener('click', handleLogout);
    
    // Tabs
    DOM.tabNav.addEventListener('click', (e) => {
        const btn = e.target.closest('.tab-btn');
        if (btn && btn.dataset.tab) {
            switchTab(btn.dataset.tab);
        }
    });
    
    // Filter
    DOM.btnApplyFilter.addEventListener('click', applyFilter);
    DOM.filterSearch.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') applyFilter();
    });
    
    // Modal
    DOM.modalClose.addEventListener('click', closeModal);
    DOM.modalOverlay.addEventListener('click', function(e) {
        if (e.target === DOM.modalOverlay) closeModal();
    });
    
    // Complete call
    DOM.btnCompleteCall.addEventListener('click', openDiagnozModal);
    DOM.diagnozClose.addEventListener('click', closeDiagnozModal);
    DOM.diagnozOverlay.addEventListener('click', function(e) {
        if (e.target === DOM.diagnozOverlay) closeDiagnozModal();
    });
    DOM.btnConfirmComplete.addEventListener('click', confirmCompleteCall);
    
    // Sound toggle button
    DOM.btnNotifications.addEventListener('click', toggleSoundNotifications);
    
    // Resume audio on user interaction (mobile browsers require this)
    document.addEventListener('click', unlockAudio, { once: true });
    document.addEventListener('touchstart', unlockAudio, { once: true });
    
    // Stop title blinking when page gets focus
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && state.titleBlinking) {
            stopTitleBlink();
        }
    });
    
    // Install prompt
    if (DOM.btnDismissInstall) {
        DOM.btnDismissInstall.addEventListener('click', () => {
            DOM.installPrompt.classList.add('hidden');
        });
    }
    
    // Online/offline status
    window.addEventListener('online', () => updatePollingStatus(true));
    window.addEventListener('offline', () => updatePollingStatus(false));
}

function setDefaultDates() {
    const today = new Date().toISOString().slice(0, 10);
    DOM.filterDateFrom.value = today;
    DOM.filterDateTo.value = today;
}

// ===================== AUTHENTICATION =====================
async function loadUsersByRole() {
    const role = DOM.roleSelect.value;
    DOM.userSelect.disabled = true;
    DOM.userSelect.innerHTML = '<option value="">Загрузка...</option>';
    
    const result = await apiCall('get_users_by_role', { role }, 'GET');
    if (result && result.users) {
        DOM.userSelect.innerHTML = '';
        if (result.users.length === 0) {
            DOM.userSelect.innerHTML = '<option value="">Нет пользователей</option>';
        } else {
            result.users.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u.id;
                opt.textContent = u.fio + (u.policlinic ? ` (${u.policlinic})` : '');
                DOM.userSelect.appendChild(opt);
            });
            DOM.userSelect.disabled = false;
        }
    } else {
        DOM.userSelect.innerHTML = '<option value="">Ошибка загрузки</option>';
    }
}

async function handleLogin(e) {
    e.preventDefault();
    
    const userId = DOM.userSelect.value;
    const password = DOM.passwordInput.value;
    
    if (!userId) {
        showLoginError('Выберите пользователя');
        return;
    }
    if (!password) {
        showLoginError('Введите пароль');
        return;
    }
    
    setLoginLoading(true);
    hideLoginError();
    
    const result = await apiCall('login', { user_id: userId, password }, 'POST');
    
    setLoginLoading(false);
    
    if (result && result.success) {
        state.user = result.user;
        DOM.passwordInput.value = '';
        showApp();
    } else {
        showLoginError(result?.error || 'Ошибка авторизации');
    }
}

async function handleLogout() {
    stopPolling();
    await apiCall('logout', {}, 'POST');
    state.user = null;
    showLogin();
}

function showLoginError(msg) {
    DOM.loginError.textContent = msg;
    DOM.loginError.classList.remove('hidden');
}

function hideLoginError() {
    DOM.loginError.classList.add('hidden');
}

function setLoginLoading(loading) {
    DOM.loginBtn.disabled = loading;
    DOM.loginBtn.querySelector('.btn-text').classList.toggle('hidden', loading);
    DOM.loginBtn.querySelector('.btn-loader').classList.toggle('hidden', !loading);
}

// ===================== SCREENS =====================
function showLogin() {
    DOM.appScreen.classList.remove('active');
    DOM.loginScreen.classList.add('active');
    loadUsersByRole();
}

function showApp() {
    DOM.loginScreen.classList.remove('active');
    DOM.appScreen.classList.add('active');
    
    // Set header info
    const roleName = state.user.role_name || '';
    DOM.headerUserName.textContent = `${state.user.fio} — ${roleName}`;
    
    // Determine visible tabs based on role
    setupTabsForRole();
    
    // Load data for current tab
    loadTabData(state.currentTab);
    
    // Start polling
    startPolling();
    
    // Show sound notification info
    setTimeout(() => {
        showToast('🔔 Звуковые уведомления', 
            state.soundEnabled 
                ? 'Включены. Вы услышите сигнал при новых записях.' 
                : 'Выключены. Нажмите 🔔 чтобы включить.',
            null
        );
    }, 2000);
}

function setupTabsForRole() {
    const tabs = $$('.tab-btn');
    const level = state.user.level;
    
    // Show/hide tabs based on role matching Delphi logic:
    // level=1 (Врач): sees registrations, active, sisters_journal
    // level=4 (Участковая сестра): sees only sisters_journal
    // level=8 (Медицинская сестра): sees registrations, active
    
    tabs.forEach(tab => {
        const tabName = tab.dataset.tab;
        let visible = true;
        
        if (level === 4) {
            // Участковая сестра — только журнал сестёр
            visible = tabName === 'sisters';
        } else if (level === 8) {
            // Медицинская сестра — без журнала сестёр
            visible = tabName !== 'sisters';
        }
        // Врач (level=1) — все вкладки видны
        
        tab.style.display = visible ? '' : 'none';
    });
    
    // Set default active tab
    if (level === 4) {
        switchTab('sisters');
    } else {
        switchTab('registrations');
    }
}

// ===================== TABS =====================
function switchTab(tabName) {
    state.currentTab = tabName;
    state.pages[tabName] = state.pages[tabName] || 1;
    
    // Update tab buttons
    $$('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabName);
    });
    
    // Update tab content
    $$('.tab-content').forEach(tc => {
        tc.classList.toggle('active', tc.id === `tab-${tabName}`);
    });
    
    // Clear new count badge for this tab
    state.newCounts[tabName] = 0;
    updateBadges();
    
    // Load data
    loadTabData(tabName);
}

// ===================== DATA LOADING =====================
async function loadTabData(tabName, page = null) {
    if (page !== null) {
        state.pages[tabName] = page;
    }
    
    const actionMap = {
        registrations: 'get_registrations',
        active: 'get_active',
        sisters: 'get_sisters_journal',
    };
    
    const action = actionMap[tabName];
    if (!action) return;
    
    showLoading(true);
    
    const params = {
        date_from: DOM.filterDateFrom.value,
        date_to: DOM.filterDateTo.value,
        search: DOM.filterSearch.value,
        page: state.pages[tabName],
    };
    
    const result = await apiCall(action, params, 'GET');
    
    showLoading(false);
    
    if (result && result.records) {
        renderRecords(tabName, result.records);
        renderPagination(tabName, result.page, result.pages, result.total);
    }
}

function applyFilter() {
    // Reset pages
    state.pages = { registrations: 1, active: 1, sisters: 1 };
    loadTabData(state.currentTab);
}

// ===================== RENDERING =====================
function renderRecords(tabName, records) {
    const listEl = $(`#list-${tabName}`);
    if (!listEl) return;
    
    if (records.length === 0) {
        listEl.innerHTML = `
            <div class="empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <path d="M3 9h18M9 21V9"/>
                </svg>
                <p>Нет записей за выбранный период</p>
                <p class="empty-hint">Попробуйте изменить диапазон дат или параметры фильтра</p>
            </div>`;
        return;
    }
    
    const renderMap = {
        registrations: renderRegistrationCard,
        active: renderActiveCard,
        sisters: renderSisterCard,
    };
    
    const renderFn = renderMap[tabName];
    listEl.innerHTML = records.map(r => renderFn(r)).join('');
    
    // Attach click handlers for detail view
    listEl.querySelectorAll('.record-card').forEach(card => {
        card.addEventListener('click', () => {
            const record = records.find(r => String(r.reg_id) === card.dataset.id);
            if (record) showRecordDetail(tabName, record);
        });
    });
}

function calcAge(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr);
    if (isNaN(d.getTime())) return '';
    var today = new Date();
    
    // Format date as DD.MM.YYYY
    var dd = ('0' + d.getDate()).slice(-2);
    var mm = ('0' + (d.getMonth() + 1)).slice(-2);
    var yyyy = d.getFullYear();
    var dateFormatted = dd + '.' + mm + '.' + yyyy;
    
    // Calc years
    var years = today.getFullYear() - d.getFullYear();
    var mDiff = today.getMonth() - d.getMonth();
    if (mDiff < 0 || (mDiff === 0 && today.getDate() < d.getDate())) years--;
    if (years < 0 || years > 150) return dateFormatted;
    
    // Calc remaining days
    var lastBirthday = new Date(today.getFullYear(), d.getMonth(), d.getDate());
    if (lastBirthday > today) lastBirthday.setFullYear(lastBirthday.getFullYear() - 1);
    var diffMs = today - lastBirthday;
    var days = Math.floor(diffMs / 86400000);
    
    // Russian declension
    function decl(n, one, two, five) {
        var m10 = n % 10, m100 = n % 100;
        if (m10 === 1 && m100 !== 11) return n + ' ' + one;
        if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return n + ' ' + two;
        return n + ' ' + five;
    }
    
    var parts = [dateFormatted, '('];
    parts.push(decl(years, 'год', 'года', 'лет'));
    parts.push(', ');
    parts.push(decl(days, 'день', 'дня', 'дней'));
    parts.push(')');
    return parts.join('');
}

function buildAddress(street, dom, kv) {
    var parts = [];
    if (street) parts.push(street);
    if (dom) parts.push('д.' + dom);
    if (kv) parts.push('кв.' + kv);
    return parts.join(', ');
}

function phoneLink(phone) {
    if (!phone) return '—';
    var clean = phone.replace(/[^\d+]/g, '');
    return '<a href="tel:' + clean + '" class="phone-link" onclick="event.stopPropagation()">' + esc(phone) + '</a>';
}

function renderRegistrationCard(r) {
    const statusClass = getStatusClass(r.reg_status);
    const statusText = r.reg_status || 'Новый';
    const age = calcAge(r.reg_dateofbirth);
    const addr = buildAddress(r.reg_address, r.reg_dom, r.reg_kv);
    const showComplete = canComplete(r);
    
    return `
        <div class="record-card" data-id="${r.reg_id}">
            <div class="record-header">
                <div class="record-fio">${esc(r.reg_fio)}${age ? ' <span class="record-age">' + age + '</span>' : ''}</div>
                <div class="record-datetime">${formatDateTime(r.reg_datetime)}</div>
            </div>
            <div class="record-body">
                <div class="record-field">
                    <span class="record-label">Адрес</span>
                    <span class="record-value">${esc(addr || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Контакт</span>
                    <span class="record-value">${phoneLink(r.reg_phone)}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Первично/Вторично</span>
                    <span class="record-value">${esc(r.reg_firsted || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Жалобы</span>
                    <span class="record-value">${esc(r.reg_complaints || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Оператор</span>
                    <span class="record-value">${esc(r.reg_user || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Статус</span>
                    <span class="record-status ${statusClass}">${esc(statusText)}</span>
                </div>
            </div>${showComplete ? `
            <div class="record-actions">
                <button class="btn-complete-inline" data-reg-id="${r.reg_id}" data-fio="${esc(r.reg_fio)}" data-table="gdb_registrations" data-diagnoz="${esc(r.reg_diagnoz || '')}" onclick="event.stopPropagation(); inlineComplete(this)">✅ Завершить вызов</button>
            </div>` : ''}
        </div>`;
}

function renderActiveCard(r) {
    const statusClass = getStatusClass(r.reg_status);
    const statusText = r.reg_status || '—';
    const age = calcAge(r.reg_dateofbirth);
    const addr = buildAddress(r.reg_street, r.reg_dom, r.reg_kv);
    const showComplete = canComplete(r);
    
    return `
        <div class="record-card" data-id="${r.reg_id}">
            <div class="record-header">
                <div class="record-fio">${esc(r.reg_fio)}${age ? ' <span class="record-age">' + age + '</span>' : ''}</div>
                <div class="record-datetime">${formatDateTime(r.reg_datetime)}</div>
            </div>
            <div class="record-body">
                <div class="record-field">
                    <span class="record-label">Адрес</span>
                    <span class="record-value">${esc(addr || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Контакт</span>
                    <span class="record-value">${phoneLink(r.reg_phone)}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Примечания</span>
                    <span class="record-value">${esc(r.reg_complaints || r.reg_diagnoz || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Оператор</span>
                    <span class="record-value">${esc(r.reg_user || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Статус</span>
                    <span class="record-status ${statusClass}">${esc(statusText)}</span>
                </div>
            </div>${showComplete ? `
            <div class="record-actions">
                <button class="btn-complete-inline" data-reg-id="${r.reg_id}" data-fio="${esc(r.reg_fio)}" data-table="gdb_active" data-diagnoz="${esc(r.reg_diagnoz || '')}" onclick="event.stopPropagation(); inlineComplete(this)">✅ Завершить вызов</button>
            </div>` : ''}
        </div>`;
}

function renderSisterCard(r) {
    const statusText = r.reg_status == 0 ? 'Не выполнено' : 'Выполнено';
    const statusClass = r.reg_status == 0 ? 'status-waiting' : 'status-done';
    const age = calcAge(r.reg_dateofbirth);
    const addr = buildAddress(r.reg_street, r.reg_dom, r.reg_kv);
    
    return `
        <div class="record-card" data-id="${r.reg_id}">
            <div class="record-header">
                <div class="record-fio">${esc(r.reg_fio)}${age ? ' <span class="record-age">' + age + '</span>' : ''}</div>
                <div class="record-datetime">${formatDateTime(r.reg_datetime)}</div>
            </div>
            <div class="record-body">
                <div class="record-field">
                    <span class="record-label">Адрес</span>
                    <span class="record-value">${esc(addr || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Контакт</span>
                    <span class="record-value">${phoneLink(r.reg_phone)}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Примечания</span>
                    <span class="record-value">${esc(r.reg_naznach || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Оператор</span>
                    <span class="record-value">${esc(r.reg_user || r.reg_creator || '—')}</span>
                </div>
                <div class="record-field">
                    <span class="record-label">Статус</span>
                    <span class="record-status ${statusClass}">${statusText}</span>
                </div>
            </div>
        </div>`;
}

function getStatusClass(status) {
    if (!status) return 'status-new';
    const s = String(status).toLowerCase();
    if (s.includes('отмен') || s.includes('cancel')) return 'status-cancelled';
    if (s.includes('не выполн') || s.includes('не обслуж') || s.includes('необслуж')) return 'status-waiting';
    if (s.includes('выполн') || s.includes('done') || s.includes('обслуж')) return 'status-done';
    if (s.includes('ожид') || s.includes('wait')) return 'status-waiting';
    if (s.includes('актив') || s.includes('active')) return 'status-active';
    return 'status-new';
}

function canComplete(r) {
    if (!state.user || state.user.level != 1) return false;
    var st = String(r.reg_status || '').toLowerCase();
    var isDone = !st.includes('не выполн') && !st.includes('не обслуж') && !st.includes('необслуж') && 
                 (st.includes('выполн') || st.includes('done') || st.includes('обслуж'));
    return !isDone;
}

function inlineComplete(btn) {
    state.completeTarget = {
        reg_id: btn.dataset.regId,
        fio: btn.dataset.fio,
        table: btn.dataset.table,
        tabName: state.currentTab,
        existingDiagnoz: btn.dataset.diagnoz || '',
    };
    openDiagnozModal();
}

// ==================== RECORD DETAIL MODAL ====================
function showRecordDetail(tabName, record) {
    const titleMap = {
        registrations: 'Вызов',
        active: 'Активный вызов',
        sisters: 'Журнал сестёр',
    };
    
    DOM.modalTitle.textContent = `${titleMap[tabName]} #${record.reg_id}`;
    
    let fields = [];
    
    if (tabName === 'registrations') {
        fields = [
            ['Дата и время', formatDateTime(record.reg_datetime)],
            ['ФИО пациента', record.reg_fio],
            ['Дата рождения', record.reg_dateofbirth],
            ['Телефон', record.reg_phone],
            ['Адрес', `${record.reg_address || ''} д.${record.reg_dom || ''} кв.${record.reg_kv || ''}`],
            ['Организация', record.reg_organization],
            ['Карта', record.reg_card],
            ['Первичный', record.reg_firsted],
            ['Участок', record.reg_areas],
            ['Поликлиника', record.reg_policlinic],
            ['Врач', record.reg_doctor],
            ['Телефон врача', record.reg_doctorphone],
            ['Жалобы', record.reg_complaints],
            ['Диагноз', record.reg_diagnoz],
            ['Статус', record.reg_status],
            ['Откуда', record.reg_from],
            ['SMS статус', record.reg_sms_status],
            ['Оператор', record.reg_user],
            ['Дата выполнения', record.reg_donedate],
        ];
    } else if (tabName === 'active') {
        fields = [
            ['Дата и время', formatDateTime(record.reg_datetime)],
            ['ФИО пациента', record.reg_fio],
            ['Дата рождения', record.reg_dateofbirth],
            ['Телефон', record.reg_phone],
            ['Адрес', `${record.reg_street || ''} д.${record.reg_dom || ''} кв.${record.reg_kv || ''}`],
            ['Организация', record.reg_organization],
            ['Карта', record.reg_card],
            ['Участок', record.reg_areas],
            ['Поликлиника', record.reg_policlinic],
            ['Врач', record.reg_doctor],
            ['Телефон врача', record.reg_doctorphone],
            ['Диагноз', record.reg_diagnoz],
            ['Статус', record.reg_status],
            ['Откуда', record.reg_from],
            ['Создатель', record.reg_creator],
            ['Оператор', record.reg_user],
            ['Дата выполнения', record.reg_donedate],
        ];
    } else if (tabName === 'sisters') {
        fields = [
            ['Дата и время', formatDateTime(record.reg_datetime)],
            ['ФИО пациента', record.reg_fio],
            ['Дата рождения', record.reg_dateofbirth],
            ['Адрес', `${record.reg_street || ''} д.${record.reg_dom || ''} кв.${record.reg_kv || ''}`],
            ['Телефон', record.reg_phone],
            ['Назначение', record.reg_naznach],
            ['Обследование', record.reg_obsledovanie],
            ['Рекомендации', record.reg_recomend],
            ['Сестра', record.reg_sister],
            ['Поликлиника', record.reg_policlinic],
            ['Участок', record.reg_area],
            ['Статус', record.reg_status == 0 ? 'Не выполнено' : 'Выполнено'],
            ['Создатель', record.reg_creator],
            ['Заметка', record.reg_note],
            ['Оператор', record.reg_user],
        ];
    }
    
    DOM.modalBody.innerHTML = fields
        .filter(([,v]) => v && String(v).trim() && v !== 'null')
        .map(([label, value]) => `
            <div class="modal-field">
                <span class="modal-field-label">${esc(label)}</span>
                <span class="modal-field-value">${esc(String(value))}</span>
            </div>`)
        .join('');
    
    // Show "Complete call" button for doctors on registrations/active tabs
    // Hide if already completed
    var showComplete = false;
    if (state.user && state.user.level == 1 && (tabName === 'registrations' || tabName === 'active')) {
        var st = String(record.reg_status || '').toLowerCase();
        var isDone = !st.includes('не выполн') && !st.includes('не обслуж') && !st.includes('необслуж') && 
                     (st.includes('выполн') || st.includes('done') || st.includes('обслуж'));
        if (!isDone) {
            showComplete = true;
        }
    }
    
    if (showComplete) {
        DOM.modalFooter.classList.remove('hidden');
        // Store current record info for complete action
        state.completeTarget = {
            reg_id: record.reg_id,
            fio: record.reg_fio,
            table: tabName === 'registrations' ? 'gdb_registrations' : 'gdb_active',
            tabName: tabName,
            existingDiagnoz: record.reg_diagnoz || '',
        };
    } else {
        DOM.modalFooter.classList.add('hidden');
        state.completeTarget = null;
    }
    
    DOM.modalOverlay.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    DOM.modalOverlay.classList.add('hidden');
    document.body.style.overflow = '';
    state.completeTarget = null;
}

// ==================== COMPLETE CALL (DIAGNOSIS) ====================
function openDiagnozModal() {
    if (!state.completeTarget) return;
    
    DOM.diagnozPatient.textContent = state.completeTarget.fio || '';
    DOM.diagnozInput.value = state.completeTarget.existingDiagnoz || '';
    DOM.diagnozError.classList.add('hidden');
    DOM.diagnozOverlay.classList.remove('hidden');
    
    setTimeout(function() { DOM.diagnozInput.focus(); }, 200);
}

function closeDiagnozModal() {
    DOM.diagnozOverlay.classList.add('hidden');
    DOM.diagnozInput.value = '';
    DOM.diagnozError.classList.add('hidden');
}

async function confirmCompleteCall() {
    var diagnoz = DOM.diagnozInput.value.trim();
    
    if (!diagnoz) {
        DOM.diagnozError.textContent = 'Заполните поле "Диагноз"';
        DOM.diagnozError.classList.remove('hidden');
        DOM.diagnozInput.focus();
        return;
    }
    
    if (!state.completeTarget) return;
    
    // Show loading
    DOM.btnConfirmComplete.disabled = true;
    DOM.btnConfirmComplete.querySelector('.btn-text').classList.add('hidden');
    DOM.btnConfirmComplete.querySelector('.btn-loader').classList.remove('hidden');
    
    var result = await apiCall('complete_call', {
        reg_id: state.completeTarget.reg_id,
        diagnoz: diagnoz,
        table: state.completeTarget.table,
    }, 'POST');
    
    // Reset loading
    DOM.btnConfirmComplete.disabled = false;
    DOM.btnConfirmComplete.querySelector('.btn-text').classList.remove('hidden');
    DOM.btnConfirmComplete.querySelector('.btn-loader').classList.add('hidden');
    
    if (result && result.success) {
        closeDiagnozModal();
        closeModal();
        showToast('✅ Вызов завершён', 'Диагноз: ' + diagnoz);
        // Refresh current tab
        loadTabData(state.currentTab);
    } else {
        DOM.diagnozError.textContent = (result && result.error) ? result.error : 'Ошибка сервера';
        DOM.diagnozError.classList.remove('hidden');
    }
}

// ==================== PAGINATION ====================
function renderPagination(tabName, currentPage, totalPages, totalRecords) {
    const pagEl = $(`#pagination-${tabName}`);
    if (!pagEl) return;
    
    if (totalPages <= 1) {
        pagEl.innerHTML = totalRecords > 0 
            ? `<span class="page-info">Всего: ${totalRecords}</span>` 
            : '';
        return;
    }
    
    let html = '';
    
    // Previous button
    html += `<button class="page-btn" ${currentPage <= 1 ? 'disabled' : ''} data-page="${currentPage - 1}">&laquo;</button>`;
    
    // Page numbers
    const maxVisible = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);
    if (endPage - startPage < maxVisible - 1) {
        startPage = Math.max(1, endPage - maxVisible + 1);
    }
    
    if (startPage > 1) {
        html += `<button class="page-btn" data-page="1">1</button>`;
        if (startPage > 2) html += `<span class="page-info">...</span>`;
    }
    
    for (let p = startPage; p <= endPage; p++) {
        html += `<button class="page-btn ${p === currentPage ? 'active' : ''}" data-page="${p}">${p}</button>`;
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) html += `<span class="page-info">...</span>`;
        html += `<button class="page-btn" data-page="${totalPages}">${totalPages}</button>`;
    }
    
    // Next button
    html += `<button class="page-btn" ${currentPage >= totalPages ? 'disabled' : ''} data-page="${currentPage + 1}">&raquo;</button>`;
    
    html += `<span class="page-info">${totalRecords} зап.</span>`;
    
    pagEl.innerHTML = html;
    
    // Event listeners
    pagEl.querySelectorAll('.page-btn:not([disabled])').forEach(btn => {
        btn.addEventListener('click', () => {
            const page = parseInt(btn.dataset.page);
            if (page && page !== currentPage) {
                loadTabData(tabName, page);
            }
        });
    });
}

// ==================== POLLING ====================
function startPolling() {
    state.lastCheck = new Date().toISOString().slice(0, 19).replace('T', ' ');
    
    if (state.pollingTimer) clearInterval(state.pollingTimer);
    
    state.pollingTimer = setInterval(pollForNew, state.pollingInterval);
    updatePollingStatus(navigator.onLine);
}

function stopPolling() {
    if (state.pollingTimer) {
        clearInterval(state.pollingTimer);
        state.pollingTimer = null;
    }
}

async function pollForNew() {
    if (!state.user || !navigator.onLine) return;
    
    try {
        const result = await apiCall('poll_new', { last_check: state.lastCheck }, 'GET');
        
        if (result && result.has_new) {
            // Update counts
            if (result.registrations > 0) {
                state.newCounts.registrations += result.registrations;
            }
            if (result.active > 0) {
                state.newCounts.active += result.active;
            }
            if (result.sisters > 0) {
                state.newCounts.sisters += result.sisters;
            }
            
            updateBadges();
            
            // Show toast, play sound, vibrate
            const totalNew = result.registrations + result.active + result.sisters;
            if (totalNew > 0) {
                showToast(
                    '🆕 Новые записи',
                    buildNewRecordMessage(result),
                    () => {
                        // Reload current tab
                        loadTabData(state.currentTab);
                    }
                );
                
                // Play notification sound
                playNotificationSound();
                
                // Vibrate phone
                vibratePhone();
                
                // Blink title if page is hidden
                if (document.hidden) {
                    startTitleBlink(totalNew);
                }
            }
            
            // Auto-reload current tab if on current day
            const today = new Date().toISOString().slice(0, 10);
            if (DOM.filterDateFrom.value === today && DOM.filterDateTo.value === today) {
                loadTabData(state.currentTab);
            }
        }
        
        if (result && result.server_time) {
            state.lastCheck = result.server_time;
        }
    } catch (err) {
        console.error('Polling error:', err);
    }
}

function buildNewRecordMessage(result) {
    const parts = [];
    if (result.registrations > 0) parts.push(`Вызовы: ${result.registrations}`);
    if (result.active > 0) parts.push(`Активные: ${result.active}`);
    if (result.sisters > 0) parts.push(`Журнал сестёр: ${result.sisters}`);
    return parts.join(' | ');
}

function updateBadges() {
    const { registrations, active, sisters } = state.newCounts;
    
    setBadge('badge-registrations', registrations);
    setBadge('badge-active', active);
    setBadge('badge-sisters', sisters);
    
    const total = registrations + active + sisters;
    setBadge('notification-badge', total);
    
    // Update app icon badge (PWA on Android)
    if ('setAppBadge' in navigator) {
        if (total > 0) {
            navigator.setAppBadge(total).catch(function() {});
        } else {
            navigator.clearAppBadge().catch(function() {});
        }
    }
}

function setBadge(elementId, count) {
    const el = $(`#${elementId}`);
    if (!el) return;
    
    if (count > 0) {
        el.textContent = count > 99 ? '99+' : count;
        el.classList.remove('hidden');
    } else {
        el.classList.add('hidden');
    }
}

// ==================== SOUND NOTIFICATIONS (HTTP-compatible) ====================

let audioContext = null;
let notificationBuffer = null;

/**
 * Инициализация звуковой системы через Web Audio API
 * Работает на HTTP — не требует HTTPS
 */
function initSoundSystem() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (AudioCtx) {
            audioContext = new AudioCtx();
        }
    } catch (e) {
        console.warn('Web Audio API not available:', e);
    }
}

/**
 * Разблокировка аудио на мобильных (требуется пользовательское действие)
 */
function unlockAudio() {
    if (audioContext && audioContext.state === 'suspended') {
        audioContext.resume();
    }
    // Создаём тихий звук для разблокировки
    if (audioContext) {
        const buffer = audioContext.createBuffer(1, 1, 22050);
        const source = audioContext.createBufferSource();
        source.buffer = buffer;
        source.connect(audioContext.destination);
        source.start(0);
    }
}

/**
 * Воспроизведение мелодии уведомления
 * Трёхтоновый медицинский сигнал
 */
function playNotificationSound() {
    if (!state.soundEnabled || !audioContext) return;
    
    try {
        // Resume context if suspended
        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }
        
        const now = audioContext.currentTime;
        
        // Мелодия: три восходящих тона (C5, E5, G5)
        const frequencies = [523.25, 659.25, 783.99];
        const duration = 0.18;
        const gap = 0.06;
        
        frequencies.forEach((freq, i) => {
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.type = 'sine';
            oscillator.frequency.value = freq;
            
            // Envelope: fade in/out for smooth sound
            const startTime = now + i * (duration + gap);
            gainNode.gain.setValueAtTime(0, startTime);
            gainNode.gain.linearRampToValueAtTime(0.3, startTime + 0.03);
            gainNode.gain.setValueAtTime(0.3, startTime + duration - 0.03);
            gainNode.gain.linearRampToValueAtTime(0, startTime + duration);
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.start(startTime);
            oscillator.stop(startTime + duration);
        });
        
        // Повторяем мелодию через 0.5с для внимания
        const repeatDelay = 0.8;
        frequencies.forEach((freq, i) => {
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.type = 'sine';
            oscillator.frequency.value = freq;
            
            const startTime = now + repeatDelay + i * (duration + gap);
            gainNode.gain.setValueAtTime(0, startTime);
            gainNode.gain.linearRampToValueAtTime(0.25, startTime + 0.03);
            gainNode.gain.setValueAtTime(0.25, startTime + duration - 0.03);
            gainNode.gain.linearRampToValueAtTime(0, startTime + duration);
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.start(startTime);
            oscillator.stop(startTime + duration);
        });
    } catch (e) {
        console.warn('Sound playback failed:', e);
    }
}

/**
 * Вибрация телефона (работает на HTTP)
 */
function vibratePhone() {
    if ('vibrate' in navigator) {
        navigator.vibrate([300, 100, 300, 100, 300]);
    }
}

/**
 * Мигание заголовка страницы при новых записях
 */
function startTitleBlink(count) {
    if (state.titleBlinking) return;
    state.titleBlinking = true;
    
    let visible = true;
    const blinkInterval = setInterval(() => {
        if (!state.titleBlinking) {
            clearInterval(blinkInterval);
            document.title = state.originalTitle;
            return;
        }
        document.title = visible 
            ? `🔴 (${count}) Новые записи!` 
            : state.originalTitle;
        visible = !visible;
    }, 1000);
}

function stopTitleBlink() {
    state.titleBlinking = false;
    document.title = state.originalTitle;
}

/**
 * Переключение звуковых уведомлений
 */
function toggleSoundNotifications() {
    state.soundEnabled = !state.soundEnabled;
    localStorage.setItem('medjournal_sound', state.soundEnabled ? 'on' : 'off');
    
    // Update button appearance
    updateSoundButtonState();
    
    if (state.soundEnabled) {
        showToast('🔔 Звук включён', 'Вы будете слышать сигнал при новых записях');
        // Play test sound
        playNotificationSound();
    } else {
        showToast('🔕 Звук выключен', 'Звуковые уведомления отключены');
    }
}

function updateSoundButtonState() {
    const btn = DOM.btnNotifications;
    if (!btn) return;
    
    if (state.soundEnabled) {
        btn.title = 'Звук вкл. (нажмите чтобы выключить)';
        btn.style.color = '';
    } else {
        btn.title = 'Звук выкл. (нажмите чтобы включить)';
        btn.style.color = 'var(--text-muted)';
    }
}

/**
 * Keep-alive: предотвращаем засыпание страницы на мобильных
 * Периодический невидимый fetch не даёт браузеру убить вкладку
 */
function startKeepAlive() {
    if (state.keepAliveTimer) clearInterval(state.keepAliveTimer);
    
    // Каждые 25 секунд — лёгкий ping для keep-alive
    state.keepAliveTimer = setInterval(() => {
        if (navigator.onLine && state.user) {
            // Minimal request to keep connection alive
            fetch('api.php?action=check_auth', { method: 'HEAD' }).catch(() => {});
        }
    }, 25000);
    
    // Wake Lock API (if available) — prevents screen from sleeping
    requestWakeLock();
}

async function requestWakeLock() {
    if ('wakeLock' in navigator) {
        try {
            const wakeLock = await navigator.wakeLock.request('screen');
            // Re-acquire on visibility change
            document.addEventListener('visibilitychange', async () => {
                if (!document.hidden && state.user) {
                    try { await navigator.wakeLock.request('screen'); } catch(e) {}
                }
            });
        } catch (e) {
            // Wake Lock not supported or denied — that's OK
        }
    }
}

// ==================== PWA INSTALL ====================
let deferredInstallPrompt = null;

function setupInstallPrompt() {
    // Check if already installed as standalone
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    
    if (isStandalone) return; // Already installed
    
    // Chrome/Edge: beforeinstallprompt
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredInstallPrompt = e;
        DOM.installPrompt.classList.remove('hidden');
    });
    
    if (DOM.btnInstall) {
        DOM.btnInstall.addEventListener('click', async function() {
            if (deferredInstallPrompt) {
                deferredInstallPrompt.prompt();
                var result = await deferredInstallPrompt.userChoice;
                if (result.outcome === 'accepted') {
                    showToast('Установка', 'Приложение установлено! ✓');
                }
                deferredInstallPrompt = null;
                DOM.installPrompt.classList.add('hidden');
            } else {
                // Manual instructions for browsers without beforeinstallprompt
                showManualInstallHint();
            }
        });
    }
    
    if (DOM.btnDismissInstall) {
        DOM.btnDismissInstall.addEventListener('click', function() {
            DOM.installPrompt.classList.add('hidden');
            try { localStorage.setItem('install_dismissed', '1'); } catch(e) {}
        });
    }
    
    window.addEventListener('appinstalled', function() {
        DOM.installPrompt.classList.add('hidden');
        deferredInstallPrompt = null;
    });
    
    // For iOS Safari and HTTP — show manual hint after 5 sec if not dismissed
    var dismissed = false;
    try { dismissed = localStorage.getItem('install_dismissed') === '1'; } catch(e) {}
    
    if (!dismissed) {
        var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
        var isHTTP = location.protocol === 'http:';
        
        if (isIOS || isHTTP) {
            setTimeout(function() {
                if (!deferredInstallPrompt && !isStandalone) {
                    DOM.installPrompt.classList.remove('hidden');
                    // Update button text for manual flow
                    if (DOM.btnInstall) {
                        DOM.btnInstall.textContent = 'Как установить?';
                    }
                }
            }, 5000);
        }
    }
}

function showManualInstallHint() {
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    var msg = '';
    if (isIOS) {
        msg = 'Нажмите кнопку «Поделиться» (□↑) внизу экрана Safari, затем «На экран Домой»';
    } else {
        msg = 'Откройте меню браузера (⋮), затем «Установить приложение» или «Добавить на главный экран»';
    }
    showToast('Установка', msg);
}

// ==================== SERVICE WORKER ====================
async function registerServiceWorker() {
    if ('serviceWorker' in navigator) {
        try {
            const reg = await navigator.serviceWorker.register('sw.js');
            console.log('Service Worker registered:', reg.scope);
        } catch (err) {
            console.warn('SW registration failed:', err);
        }
    }
}

// ==================== TOAST NOTIFICATIONS ====================
function showToast(title, message, onClick = null) {
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = `
        <div class="toast-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 01-3.46 0"/>
            </svg>
        </div>
        <div class="toast-body">
            <div class="toast-title">${esc(title)}</div>
            <div class="toast-message">${esc(message)}</div>
        </div>`;
    
    if (onClick) {
        toast.addEventListener('click', () => {
            onClick();
            removeToast(toast);
        });
    }
    
    DOM.toastContainer.appendChild(toast);
    
    // Auto-remove after 8 seconds (longer for readability)
    setTimeout(() => removeToast(toast), 8000);
}

function removeToast(toast) {
    toast.classList.add('toast-out');
    setTimeout(() => {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 300);
}

// ==================== POLLING STATUS ====================
function updatePollingStatus(online) {
    if (online) {
        DOM.pollingStatus.classList.remove('offline');
        DOM.pollingStatus.querySelector('span').textContent = 'Онлайн';
    } else {
        DOM.pollingStatus.classList.add('offline');
        DOM.pollingStatus.querySelector('span').textContent = 'Офлайн';
    }
}

// ==================== LOADING ====================
function showLoading(show) {
    DOM.loadingOverlay.classList.toggle('hidden', !show);
}

// ==================== API HELPERS ====================
async function apiCall(action, params = {}, method = 'GET') {
    try {
        let url, options;
        
        if (method === 'GET') {
            const qs = new URLSearchParams({ action, ...params }).toString();
            url = `api.php?${qs}`;
            options = { method: 'GET' };
        } else {
            url = 'api.php';
            const formData = new FormData();
            formData.append('action', action);
            for (const [k, v] of Object.entries(params)) {
                formData.append(k, v);
            }
            options = { method: 'POST', body: formData };
        }
        
        const response = await fetch(url, options);
        return await response.json();
    } catch (err) {
        console.error('API call error:', err);
        return null;
    }
}

async function apiCallRaw(action, data) {
    try {
        const response = await fetch(`api.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        return await response.json();
    } catch (err) {
        console.error('API raw call error:', err);
        return null;
    }
}

// ==================== UTILITIES ====================
function esc(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

function formatDateTime(dtStr) {
    if (!dtStr) return '—';
    try {
        const d = new Date(dtStr);
        if (isNaN(d.getTime())) return dtStr;
        const pad = n => String(n).padStart(2, '0');
        return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    } catch {
        return dtStr;
    }
}

function urlBase64ToUint8Array(base64String) {
    if (!base64String) return new Uint8Array();
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
}

// ==================== BOOT ====================
document.addEventListener('DOMContentLoaded', init);

})();

import './stimulus_bootstrap.js';
import 'datatables.net-dt/css/dataTables.dataTables.min.css';
import './styles/app.css';
import axios from 'axios';
import DataTable from 'datatables.net-dt';

const syncModalState = () => {
    const hasOpenModal = Boolean(document.querySelector('dialog.modal[open]'));

    document.body.classList.toggle('is-modal-open', hasOpenModal);
};

const focusModal = (modal) => {
    const focusTarget = modal.querySelector(
        '[data-modal-initial-focus], button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
    );

    focusTarget?.focus({ preventScroll: true });
};

const openModal = (modalId) => {
    const modal = document.getElementById(modalId);

    if (!(modal instanceof HTMLDialogElement)) {
        return;
    }

    if (modal.open && !modal.matches(':modal')) {
        modal.close();
    }

    if (!modal.open) {
        modal.showModal();
    }

    syncModalState();
    focusModal(modal);
};

const openInitialModal = () => {
    const holder = document.querySelector('[data-initial-modal]');
    const modalId = holder?.dataset.initialModal;

    if (!modalId) {
        return;
    }

    requestAnimationFrame(() => openModal(modalId));
};

const closeModal = (modal) => {
    if (!(modal instanceof HTMLDialogElement)) {
        return;
    }

    modal.close();
    syncModalState();
};

const getClosestElement = (target, selector) => {
    if (!(target instanceof Element)) {
        return null;
    }

    return target.closest(selector);
};

const SCROLL_INTENT_KEY = 'symclinic:scroll-intent';
const SCROLL_INTENT_LIFETIME = 15000;

const getSafeUrl = (value, fallback = window.location.href) => {
    try {
        return new URL(value || fallback, window.location.href);
    } catch (error) {
        return null;
    }
};

const readScrollIntent = () => {
    try {
        const payload = window.sessionStorage.getItem(SCROLL_INTENT_KEY);

        return payload ? JSON.parse(payload) : null;
    } catch (error) {
        return null;
    }
};

const clearScrollIntent = () => {
    try {
        window.sessionStorage.removeItem(SCROLL_INTENT_KEY);
    } catch (error) {
        // Storage can be disabled by the browser; in that case we simply skip restoration.
    }
};

const saveScrollIntent = (intent) => {
    try {
        window.sessionStorage.setItem(SCROLL_INTENT_KEY, JSON.stringify({
            anchorId: intent.anchorId ?? null,
            actionPath: intent.actionPath ?? null,
            sourcePath: window.location.pathname,
            x: window.scrollX,
            y: window.scrollY,
            expiresAt: Date.now() + SCROLL_INTENT_LIFETIME,
        }));
    } catch (error) {
        // Storage can be disabled by the browser; navigation should still continue normally.
    }
};

const getViewportAnchorElement = () => {
    const x = Math.max(1, Math.min(window.innerWidth - 1, Math.round(window.innerWidth / 2)));
    const y = Math.max(1, Math.min(window.innerHeight - 1, Math.round(window.innerHeight / 2)));

    return document.elementFromPoint(x, y);
};

const getLabelledAnchorId = (element) => {
    const labelled = element?.closest?.('[aria-labelledby]');
    const ids = labelled?.getAttribute('aria-labelledby')?.split(/\s+/) ?? [];

    return ids.find((id) => Boolean(id && document.getElementById(id))) ?? null;
};

const getScrollAnchorId = (element) => {
    if (!(element instanceof Element)) {
        return null;
    }

    const modal = element.closest('dialog.modal');
    const root = modal instanceof HTMLDialogElement
        ? getViewportAnchorElement()
        : element;

    if (!(root instanceof Element)) {
        return null;
    }

    const anchor = root.closest('[data-scroll-anchor], section[id], article[id], main[id], table[id], tbody[id], div[id]');

    if (anchor instanceof HTMLElement && anchor.id) {
        return anchor.id;
    }

    return getLabelledAnchorId(root);
};

const getScrollAnchorIdFromForm = (form, submitter = null) => {
    const explicitAnchor = form.dataset.scrollAnchor;

    if (explicitAnchor) {
        return explicitAnchor;
    }

    const payload = new FormData(form);
    const postedAnchor = payload.get('_scroll_anchor');

    if (typeof postedAnchor === 'string' && postedAnchor.trim() !== '') {
        return postedAnchor.trim().replace(/^#/, '');
    }

    const redirectDay = payload.get('_redirect_day');

    if (typeof redirectDay === 'string' && /^[1-7]$/.test(redirectDay)) {
        return `jour-${redirectDay}`;
    }

    return getScrollAnchorId(submitter) ?? getScrollAnchorId(form);
};

const scrollToAnchorOrPosition = (anchorId, x, y) => {
    const hashId = window.location.hash ? decodeURIComponent(window.location.hash.slice(1)) : '';
    const target = document.getElementById(hashId || anchorId || '');

    if (target instanceof HTMLElement && target.offsetParent !== null) {
        const top = target.getBoundingClientRect().top + window.scrollY - 18;

        window.scrollTo({
            left: 0,
            top: Math.max(0, top),
            behavior: 'auto',
        });
        return;
    }

    window.scrollTo(Number.isFinite(x) ? x : 0, Number.isFinite(y) ? y : 0);
};

const restoreScrollIntent = () => {
    const intent = readScrollIntent();

    if (!intent || typeof intent !== 'object') {
        return;
    }

    if (!intent.expiresAt || intent.expiresAt < Date.now()) {
        clearScrollIntent();
        return;
    }

    const currentPath = window.location.pathname;
    const matchesCurrentPage = [intent.sourcePath, intent.actionPath].includes(currentPath);

    if (!matchesCurrentPage) {
        clearScrollIntent();
        return;
    }

    clearScrollIntent();
    window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
            scrollToAnchorOrPosition(intent.anchorId, intent.x, intent.y);
        });
    });
};

const rememberScrollForFormSubmit = (form, submitter = null) => {
    if (!(form instanceof HTMLFormElement) || form.matches('[data-ajax-form]') || form.dataset.scrollRestore === 'off') {
        return;
    }

    if (form.target && form.target !== '_self') {
        return;
    }

    const method = (form.method || 'get').toUpperCase();

    if (!['GET', 'POST'].includes(method)) {
        return;
    }

    const actionUrl = getSafeUrl(form.getAttribute('action'));

    if (!actionUrl || actionUrl.origin !== window.location.origin) {
        return;
    }

    saveScrollIntent({
        actionPath: actionUrl.pathname,
        anchorId: getScrollAnchorIdFromForm(form, submitter),
    });
};

const rememberScrollForLink = (link) => {
    if (
        !(link instanceof HTMLAnchorElement)
        || link.dataset.scrollRestore === 'off'
        || link.dataset.modalOpen
        || link.target
        || link.download
    ) {
        return;
    }

    const targetUrl = getSafeUrl(link.href);

    if (!targetUrl || targetUrl.origin !== window.location.origin) {
        return;
    }

    const staysInContext = targetUrl.hash
        || (targetUrl.pathname === window.location.pathname && targetUrl.search !== window.location.search);

    if (!staysInContext) {
        return;
    }

    saveScrollIntent({
        actionPath: targetUrl.pathname,
        anchorId: targetUrl.hash ? decodeURIComponent(targetUrl.hash.slice(1)) : getScrollAnchorId(link),
    });
};

const initFlashMessages = (scope = document) => {
    scope.querySelectorAll('.flash-stack').forEach((stack) => {
        if (!(stack instanceof HTMLElement) || stack.dataset.flashReady === '1') {
            return;
        }

        stack.dataset.flashReady = '1';
        window.setTimeout(() => {
            stack.classList.add('is-hiding');
            window.setTimeout(() => {
                stack.hidden = true;
            }, 260);
        }, 5200);
    });
};

const copyMatchingFieldValue = (sourceForm, targetForm, fieldName) => {
    const source = sourceForm.querySelector(`[name="${fieldName}"]`);
    const target = targetForm.querySelector(`[name="${fieldName}"]`);

    if (
        (
            source instanceof HTMLInputElement
            || source instanceof HTMLSelectElement
            || source instanceof HTMLTextAreaElement
        )
        && (
            target instanceof HTMLInputElement
            || target instanceof HTMLSelectElement
            || target instanceof HTMLTextAreaElement
        )
    ) {
        target.value = source.value;
        target.dispatchEvent(new Event('change', { bubbles: true }));
    }
};

const applyAppointmentPrefill = (trigger) => {
    const sourceSelector = trigger.dataset.appointmentPrefillFrom;
    const modalId = trigger.dataset.modalOpen;

    if (!sourceSelector || !modalId) {
        return;
    }

    const sourceRoot = document.querySelector(sourceSelector);
    const sourceForm = sourceRoot?.matches('form')
        ? sourceRoot
        : sourceRoot?.querySelector('[data-appointment-prefill-source], form');
    const modal = document.getElementById(modalId);
    const targetForm = modal?.querySelector('[data-booking-wizard]');

    if (!(sourceForm instanceof HTMLFormElement) || !(targetForm instanceof HTMLFormElement)) {
        return;
    }

    ['device', 'problem', 'phone', 'email', 'customer_note'].forEach((fieldName) => {
        copyMatchingFieldValue(sourceForm, targetForm, fieldName);
    });

    setBookingStep(targetForm, 0);
};

const hideBookingPhoneAlert = (wizard) => {
    const alert = wizard.querySelector('[data-booking-phone-alert]');

    if (alert instanceof HTMLElement) {
        alert.hidden = true;
    }
};

const showBookingPhoneAlert = (wizard, message, loginUrl = null, shouldShowLogin = false) => {
    const alert = wizard.querySelector('[data-booking-phone-alert]');
    const messageTarget = wizard.querySelector('[data-booking-phone-message]');
    const loginLink = wizard.querySelector('[data-booking-phone-login]');

    if (messageTarget instanceof HTMLElement) {
        messageTarget.textContent = message;
    }

    if (loginLink instanceof HTMLAnchorElement) {
        if (loginUrl) {
            loginLink.href = loginUrl;
        }

        loginLink.hidden = !shouldShowLogin;
    }

    if (alert instanceof HTMLElement) {
        alert.hidden = false;
        alert.scrollIntoView({ block: 'nearest' });
    }
};

const checkBookingPhoneAvailability = async (wizard) => {
    const phoneInput = wizard.querySelector('[data-booking-phone]');
    const tokenInput = wizard.querySelector('input[name="_token"]');
    const checkUrl = wizard.dataset.phoneCheckUrl;

    if (!(phoneInput instanceof HTMLInputElement) || !(tokenInput instanceof HTMLInputElement) || !checkUrl) {
        return true;
    }

    hideBookingPhoneAlert(wizard);

    try {
        const response = await axios.post(
            checkUrl,
            {
                phone: phoneInput.value,
                _token: tokenInput.value,
            },
            {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        const payload = response.data;

        if (payload.available) {
            if (typeof payload.normalized_phone === 'string') {
                phoneInput.value = payload.normalized_phone;
            }

            return true;
        }

        showBookingPhoneAlert(
            wizard,
            payload.message ?? 'Connectez-vous pour continuer avec ce numéro, ou vérifiez les informations saisies.',
            payload.login_url ?? null,
            Boolean(payload.requires_login),
        );
        phoneInput.focus({ preventScroll: true });

        return false;
    } catch (error) {
        showBookingPhoneAlert(wizard, 'Impossible de vérifier ce numéro maintenant. Réessayez dans un instant.');
        phoneInput.focus({ preventScroll: true });

        return false;
    }
};

const showAjaxToast = (message, isSuccess = true) => {
    const toast = document.querySelector('[data-ajax-toast]');

    if (!(toast instanceof HTMLElement) || !message) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;
    toast.classList.toggle('ajax-toast--error', !isSuccess);
    toast.classList.toggle('ajax-toast--success', isSuccess);

    window.clearTimeout(showAjaxToast.timeoutId);
    showAjaxToast.timeoutId = window.setTimeout(() => {
        toast.hidden = true;
    }, 4200);
};

const updateAdminBadgeCounts = (count) => {
    const safeCount = Number.parseInt(count ?? '0', 10) || 0;

    document.querySelectorAll('[data-admin-appointment-badge-count], #admin-appointment-badge').forEach((badge) => {
        if (!(badge instanceof HTMLElement)) {
            return;
        }

        badge.textContent = String(safeCount);
        badge.hidden = safeCount <= 0;
    });
};

const parseJsonData = (value, fallback = []) => {
    if (!value) {
        return fallback;
    }

    try {
        const parsed = JSON.parse(value);

        return Array.isArray(parsed) ? parsed : fallback;
    } catch (error) {
        return fallback;
    }
};

const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

const dataTableInstances = new WeakMap();
const dataTableFilterStates = new WeakMap();
let dataTableFilterRegistered = false;

const normalizeDataTableText = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();

const getDataTableCellText = (row, label) => {
    if (!(row instanceof HTMLTableRowElement)) {
        return '';
    }

    const normalizedLabel = normalizeDataTableText(label);

    return Array.from(row.cells)
        .find((cell) => normalizeDataTableText(cell.dataset.label) === normalizedLabel)
        ?.textContent ?? '';
};

const cellEquals = (row, label, expected) => normalizeDataTableText(getDataTableCellText(row, label))
    === normalizeDataTableText(expected);

const parseDataTableMoney = (value) => {
    const normalized = String(value ?? '')
        .replace(/\s/g, '')
        .replace(',', '.')
        .replace(/[^\d.-]/g, '');

    const parsed = Number.parseFloat(normalized);

    return Number.isFinite(parsed) ? parsed : 0;
};

const getFormControlLabel = (control) => {
    if (!(control instanceof HTMLElement)) {
        return '';
    }

    if (control.id && window.CSS?.escape) {
        const label = document.querySelector(`label[for="${CSS.escape(control.id)}"]`);

        if (label instanceof HTMLLabelElement) {
            return label.textContent ?? '';
        }
    }

    return control.closest('label')?.textContent ?? '';
};

const rowMatchesDataTableFilter = (row, filter) => {
    if (!filter.value) {
        return true;
    }

    const name = normalizeDataTableText(filter.name);
    const label = normalizeDataTableText(filter.label);
    const optionText = normalizeDataTableText(filter.optionText);

    if (name === 'reservation_urgency') {
        const urgency = row.dataset.reservationUrgency ?? '';

        if (filter.value === 'soon') {
            return urgency === 'soon' || urgency === 'critical';
        }

        return urgency === filter.value;
    }

    if (name === 'status' || label.includes('statut')) {
        return normalizeDataTableText(getDataTableCellText(row, 'Statut')).includes(optionText);
    }

    if (name === 'filter' || label.includes('filtre')) {
        return matchDataTableBusinessFilter(row, filter.value);
    }

    if (label.includes('etat')) {
        return normalizeDataTableText(getDataTableCellText(row, 'État')).includes(optionText);
    }

    return normalizeDataTableText(row.textContent).includes(optionText);
};

const matchDataTableBusinessFilter = (row, value) => {
    switch (value) {
        case 'active':
            return cellEquals(row, 'Statut', 'Actif') || cellEquals(row, 'État', 'Visible');
        case 'inactive':
            return cellEquals(row, 'Statut', 'Désactivé') || cellEquals(row, 'État', 'Masqué');
        case 'expired':
            return cellEquals(row, 'État', 'Expirée');
        case 'sold':
            return cellEquals(row, 'État', 'Vendu');
        case 'with_balance':
            return parseDataTableMoney(getDataTableCellText(row, 'Disponible')) > 0;
        case 'with_pending':
            return parseDataTableMoney(getDataTableCellText(row, 'En attente')) > 0;
        default:
            return true;
    }
};

const registerDataTableFilter = () => {
    if (dataTableFilterRegistered) {
        return;
    }

    dataTableFilterRegistered = true;
    if (!Array.isArray(DataTable.ext?.search)) {
        return;
    }

    DataTable.ext.search.push((settings, _data, dataIndex) => {
        const table = settings.nTable;
        const filters = table instanceof HTMLTableElement ? dataTableFilterStates.get(table) : null;

        if (!filters || filters.length === 0) {
            return true;
        }

        const row = settings.aoData?.[dataIndex]?.nTr;

        if (!(row instanceof HTMLTableRowElement)) {
            return true;
        }

        return filters.every((filter) => rowMatchesDataTableFilter(row, filter));
    });
};

const normalizeEmptyDataTable = (table) => {
    const rows = Array.from(table.tBodies[0]?.rows ?? []);

    if (
        rows.length === 1
        && rows[0]?.cells.length === 1
        && rows[0]?.cells[0]?.hasAttribute('colspan')
    ) {
        table.tBodies[0].innerHTML = '';
    }
};

const buildDataTableFilterState = (shell) => Array.from(shell.querySelectorAll('.data-table-filter select'))
    .filter((select) => select instanceof HTMLSelectElement && select.value !== '')
    .map((select) => ({
        name: select.name,
        value: select.value,
        label: getFormControlLabel(select),
        optionText: select.selectedOptions[0]?.textContent ?? '',
    }));

const syncDataTableControls = (shell, table, dataTable) => {
    const searchInput = shell.querySelector('.data-table-filter input[type="search"]');

    if (searchInput instanceof HTMLInputElement) {
        dataTable.search(searchInput.value).draw();
    }

    dataTableFilterStates.set(table, buildDataTableFilterState(shell));
    dataTable.draw();
};

const initDataTableShellControls = (shell, table, dataTable) => {
    shell.querySelectorAll('.data-table-filter').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.datatableFilterReady === '1') {
            return;
        }

        form.dataset.datatableManaged = '1';
        form.dataset.datatableFilterReady = '1';

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            syncDataTableControls(shell, table, dataTable);
        });

        form.addEventListener('input', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.type === 'search') {
                window.clearTimeout(form.symclinicDataTableSearchTimeout);
                form.symclinicDataTableSearchTimeout = window.setTimeout(() => {
                    syncDataTableControls(shell, table, dataTable);
                }, 120);
            }
        });

        form.addEventListener('change', (event) => {
            if (event.target instanceof HTMLSelectElement) {
                syncDataTableControls(shell, table, dataTable);
            }
        });
    });

    syncDataTableControls(shell, table, dataTable);
};

const initDataTables = (scope = document) => {
    registerDataTableFilter();

    scope.querySelectorAll('table.data-table').forEach((table) => {
        if (!(table instanceof HTMLTableElement) || dataTableInstances.has(table)) {
            return;
        }

        normalizeEmptyDataTable(table);

        const dataTable = new DataTable(table, {
            autoWidth: false,
            order: [],
            pageLength: Number.parseInt(table.dataset.pageLength ?? '10', 10) || 10,
            lengthMenu: [
                [10, 25, 50, 100, -1],
                ['10', '25', '50', '100', 'Tout'],
            ],
            language: {
                decimal: ',',
                emptyTable: 'Aucune donnée disponible',
                info: '_START_ à _END_ sur _TOTAL_',
                infoEmpty: '0 résultat',
                infoFiltered: 'filtré sur _MAX_',
                lengthMenu: 'Afficher _MENU_',
                loadingRecords: 'Chargement...',
                paginate: {
                    first: 'Premier',
                    last: 'Dernier',
                    next: 'Suivant',
                    previous: 'Précédent',
                },
                search: 'Recherche',
                zeroRecords: 'Aucun résultat',
            },
            layout: {
                topStart: 'pageLength',
                topEnd: null,
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            columnDefs: [
                {
                    targets: -1,
                    orderable: false,
                    searchable: false,
                },
            ],
        });

        dataTableInstances.set(table, dataTable);
        table.dataset.datatableReady = '1';

        const shell = table.closest('.data-table-shell');

        if (shell instanceof HTMLElement) {
            initDataTableShellControls(shell, table, dataTable);
        }
    });
};

const destroyDataTable = (table) => {
    if (!(table instanceof HTMLTableElement)) {
        return;
    }

    const dataTable = dataTableInstances.get(table);

    if (!dataTable) {
        return;
    }

    dataTable.destroy();
    dataTableInstances.delete(table);
    dataTableFilterStates.delete(table);
    delete table.dataset.datatableReady;
};

const destroyDataTablesIn = (target) => {
    if (!(target instanceof HTMLElement)) {
        return;
    }

    if (target.matches('table.data-table')) {
        destroyDataTable(target);
    }

    target.querySelectorAll('table.data-table').forEach(destroyDataTable);
};

const clearAjaxErrors = (form) => {
    const alert = form.querySelector('[data-ajax-errors]');

    if (alert instanceof HTMLElement) {
        alert.hidden = true;
        alert.innerHTML = '';
    }
};

const showAjaxErrors = (form, errors) => {
    const alert = form.querySelector('[data-ajax-errors]');
    const safeErrors = Array.isArray(errors) && errors.length > 0
        ? errors
        : ['Une erreur est survenue. Vérifiez les informations puis réessayez.'];

    if (!(alert instanceof HTMLElement)) {
        showAjaxToast(safeErrors[0], false);
        return;
    }

    alert.innerHTML = `<ul>${safeErrors.map((error) => `<li>${escapeHtml(error)}</li>`).join('')}</ul>`;
    alert.hidden = false;
    alert.scrollIntoView({ block: 'nearest' });
};

const setAjaxFormLoading = (form, isLoading) => {
    form.dataset.ajaxBusy = isLoading ? '1' : '0';
    form.querySelectorAll('button, input[type="submit"]').forEach((button) => {
        if (
            button instanceof HTMLButtonElement
            || button instanceof HTMLInputElement
        ) {
            button.disabled = isLoading;
            button.classList.toggle('is-loading', isLoading);
        }
    });
};

const applyAjaxFragments = (fragments) => {
    if (!Array.isArray(fragments)) {
        return;
    }

    const hasOpenModal = Boolean(document.querySelector('dialog.modal[open]'));

    fragments.forEach((fragment) => {
        if (fragment.skip_when_modal_open && hasOpenModal) {
            return;
        }

        const target = document.querySelector(fragment.selector);

        if (!(target instanceof HTMLElement) || typeof fragment.html !== 'string') {
            return;
        }

        if (target instanceof HTMLTableSectionElement) {
            destroyDataTable(target.closest('table.data-table'));
            target.innerHTML = fragment.html;
            return;
        }

        destroyDataTablesIn(target);
        target.outerHTML = fragment.html;
    });

    initInteractiveComponents();
};

const closeAjaxFormModal = (form) => {
    const modal = form.closest('dialog.modal');

    if (modal instanceof HTMLDialogElement) {
        closeModal(modal);
    }
};

const applyLoyaltyBalance = (payload) => {
    const balance = payload?.loyalty_balance;
    const customerId = Number.parseInt(balance?.customer_id ?? '', 10);
    const label = typeof balance?.available_balance_label === 'string'
        ? balance.available_balance_label
        : '';

    if (!Number.isFinite(customerId) || customerId <= 0 || label === '') {
        return;
    }

    document.querySelectorAll(`[data-loyalty-customer-id="${customerId}"] [data-loyalty-available-balance]`)
        .forEach((target) => {
            target.textContent = label;
        });
};

const submitAjaxForm = async (form) => {
    if (form.dataset.ajaxBusy === '1') {
        return;
    }

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    clearAjaxErrors(form);
    setAjaxFormLoading(form, true);

    try {
        const response = await axios.post(form.action, new FormData(form), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload = response.data ?? {};

        applyAjaxFragments(payload.fragments);
        applyLoyaltyBalance(payload);
        if (Object.prototype.hasOwnProperty.call(payload, 'badge_count')) {
            updateAdminBadgeCounts(payload.badge_count);
        }
        closeAjaxFormModal(form);
        showAjaxToast(payload.message ?? 'Action enregistrée.');

        if (form.dataset.ajaxReset === '1') {
            form.reset();
        }
    } catch (error) {
        const payload = error?.response?.data ?? {};
        const errors = payload.errors ?? [payload.message ?? 'Une erreur serveur est survenue.'];

        showAjaxErrors(form, errors);
        showAjaxToast(payload.message ?? errors[0], false);
    } finally {
        setAjaxFormLoading(form, false);
    }
};

const setActiveSlotPickerDay = (picker, dayKey) => {
    if (!picker || !dayKey) {
        return;
    }

    picker.querySelectorAll('[data-slot-picker-day]').forEach((button) => {
        const isActive = button.dataset.slotPickerDay === dayKey;

        button.classList.toggle('is-active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });

    picker.querySelectorAll('[data-slot-picker-group]').forEach((group) => {
        group.hidden = group.dataset.slotPickerGroup !== dayKey;
    });

    const input = picker.querySelector('[data-slot-picker-input]');

    if (input instanceof HTMLInputElement && input.value && !input.value.startsWith(`${dayKey} `)) {
        input.value = '';

        picker.querySelectorAll('[data-slot-picker-slot]').forEach((button) => {
            button.classList.remove('is-selected');
            button.setAttribute('aria-pressed', 'false');
        });
    }

    picker.classList.remove('slot-picker--invalid');
    picker.querySelector('[data-slot-picker-error]')?.setAttribute('hidden', '');
};

const selectSlotPickerSlot = (picker, slotValue) => {
    if (!picker || !slotValue) {
        return;
    }

    const input = picker.querySelector('[data-slot-picker-input]');

    if (input instanceof HTMLInputElement) {
        input.value = slotValue;
    }

    picker.querySelectorAll('[data-slot-picker-slot]').forEach((button) => {
        const isSelected = button.dataset.slotPickerSlot === slotValue;

        button.classList.toggle('is-selected', isSelected);
        button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    });

    picker.classList.remove('slot-picker--invalid');
    picker.querySelector('[data-slot-picker-error]')?.setAttribute('hidden', '');
};

const initSlotPicker = (picker) => {
    const activeDay = picker.querySelector('[data-slot-picker-day].is-active')
        ?? picker.querySelector('[data-slot-picker-day]');
    const selectedSlot = picker.querySelector('[data-slot-picker-slot].is-selected');

    if (activeDay instanceof HTMLElement) {
        setActiveSlotPickerDay(picker, activeDay.dataset.slotPickerDay);
    }

    if (selectedSlot instanceof HTMLElement) {
        selectSlotPickerSlot(picker, selectedSlot.dataset.slotPickerSlot);
    }
};

const initSlotPickers = (scope = document) => {
    scope.querySelectorAll('[data-slot-picker]').forEach(initSlotPicker);
};

const validateSlotPickers = (scope) => {
    let isValid = true;
    let firstInvalidPicker = null;

    scope.querySelectorAll('[data-slot-picker-input][data-slot-picker-required="1"]').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        const picker = input.closest('[data-slot-picker]');
        const hasValue = input.value.trim() !== '';

        if (picker) {
            picker.classList.toggle('slot-picker--invalid', !hasValue);
            const error = picker.querySelector('[data-slot-picker-error]');

            if (error instanceof HTMLElement) {
                error.hidden = hasValue;
            }
        }

        if (!hasValue) {
            isValid = false;
            firstInvalidPicker ??= picker;
        }
    });

    if (firstInvalidPicker instanceof HTMLElement) {
        firstInvalidPicker.querySelector('[data-slot-picker-slot], [data-slot-picker-day]')?.focus({ preventScroll: true });
    }

    return isValid;
};

const getBookingSteps = (wizard) => Array.from(wizard.querySelectorAll('[data-booking-step]'));

const setBookingStep = (wizard, stepIndex, shouldFocus = false) => {
    const steps = getBookingSteps(wizard);
    const safeIndex = Math.min(Math.max(stepIndex, 0), Math.max(steps.length - 1, 0));

    steps.forEach((step, index) => {
        step.hidden = index !== safeIndex;
    });

    wizard.querySelectorAll('[data-booking-step-indicator]').forEach((indicator) => {
        const indicatorIndex = Number.parseInt(indicator.dataset.bookingStepIndicator ?? '0', 10);

        indicator.classList.toggle('is-active', indicatorIndex === safeIndex);
        indicator.classList.toggle('is-complete', indicatorIndex < safeIndex);
    });

    const previousButton = wizard.querySelector('[data-booking-prev]');
    const nextButton = wizard.querySelector('[data-booking-next]');
    const submitButton = wizard.querySelector('[data-booking-submit]');
    const isFirst = safeIndex === 0;
    const isLast = safeIndex === steps.length - 1;

    if (previousButton instanceof HTMLElement) {
        previousButton.hidden = isFirst;
    }

    if (nextButton instanceof HTMLElement) {
        nextButton.hidden = isLast;
    }

    if (submitButton instanceof HTMLElement) {
        submitButton.hidden = !isLast;
    }

    wizard.dataset.bookingCurrentStep = String(safeIndex);

    if (shouldFocus) {
        steps[safeIndex]?.querySelector('input, select, textarea, button')?.focus({ preventScroll: true });
    }
};

const validateBookingStep = async (wizard, stepIndex) => {
    const step = getBookingSteps(wizard)[stepIndex];

    if (!(step instanceof HTMLElement)) {
        return true;
    }

    const controls = step.querySelectorAll('input:not([type="hidden"]), select, textarea');

    for (const control of controls) {
        if (
            control instanceof HTMLInputElement
            || control instanceof HTMLSelectElement
            || control instanceof HTMLTextAreaElement
        ) {
            if (!control.checkValidity()) {
                control.reportValidity();

                return false;
            }
        }
    }

    if (step.querySelector('[data-booking-phone]') && !(await checkBookingPhoneAvailability(wizard))) {
        return false;
    }

    return validateSlotPickers(step);
};

const initBookingWizard = (wizard) => {
    if (wizard.dataset.bookingReady === '1') {
        return;
    }

    wizard.dataset.bookingReady = '1';
    wizard.classList.add('is-enhanced');

    const initialStep = Number.parseInt(wizard.dataset.bookingInitialStep ?? '0', 10);

    setBookingStep(wizard, Number.isNaN(initialStep) ? 0 : initialStep);
};

const initBookingWizards = (scope = document) => {
    scope.querySelectorAll('[data-booking-wizard]').forEach(initBookingWizard);
};

const setAuthPanel = (shell, panelName, shouldFocus = false) => {
    if (!(shell instanceof HTMLElement) || !panelName) {
        return;
    }

    shell.dataset.authActivePanel = panelName;

    shell.querySelectorAll('[data-auth-tab]').forEach((tab) => {
        const isActive = tab.dataset.authTab === panelName;

        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    shell.querySelectorAll('[data-auth-panel-content]').forEach((panel) => {
        const isActive = panel.dataset.authPanelContent === panelName;

        panel.hidden = !isActive;

        if (isActive && shouldFocus) {
            panel.querySelector('[data-modal-initial-focus], input, select, textarea, button')?.focus({ preventScroll: true });
        }
    });
};

const initAuthPanels = (scope = document) => {
    scope.querySelectorAll('[data-auth-shell]').forEach((shell) => {
        setAuthPanel(shell, shell.dataset.authActivePanel ?? 'login');
    });
};

const addAttributeRow = (list) => {
    if (!(list instanceof HTMLElement)) {
        return;
    }

    const template = list.querySelector('[data-attribute-template]');
    const rows = list.querySelector('[data-attribute-rows]');

    if (!(template instanceof HTMLTemplateElement) || !(rows instanceof HTMLElement)) {
        return;
    }

    const maxRows = Number.parseInt(list.dataset.attributeMaxRows ?? '0', 10);
    const currentRows = rows.querySelectorAll('[data-attribute-row], .attribute-row').length;

    if (maxRows > 0 && currentRows >= maxRows) {
        return;
    }

    const fragment = template.content.cloneNode(true);
    const row = fragment.querySelector('[data-attribute-row], .attribute-row');

    if (row instanceof HTMLElement) {
        row.dataset.attributeGenerated = '1';
    }

    rows.appendChild(fragment);

    if (row instanceof HTMLElement) {
        row.querySelector('input')?.focus({ preventScroll: true });
    }
};

const removeAttributeRow = (button) => {
    const list = button.closest('[data-attribute-list]');
    const row = button.closest('[data-attribute-row], .attribute-row');

    if (!(list instanceof HTMLElement) || !(row instanceof HTMLElement)) {
        return;
    }

    row.remove();

    const rows = list.querySelector('[data-attribute-rows]');

    if (rows instanceof HTMLElement && !rows.querySelector('[data-attribute-row], .attribute-row')) {
        addAttributeRow(list);
    }
};

const parseAvailabilityRanges = (form) => {
    try {
        const ranges = JSON.parse(form.dataset.availabilityRanges ?? '{}');

        return ranges && typeof ranges === 'object' && !Array.isArray(ranges) ? ranges : {};
    } catch (error) {
        return {};
    }
};

const timeToMinutes = (value) => {
    if (!/^\d{2}:\d{2}$/.test(value)) {
        return null;
    }

    const [hours, minutes] = value.split(':').map((part) => Number.parseInt(part, 10));

    if (
        Number.isNaN(hours)
        || Number.isNaN(minutes)
        || hours < 0
        || hours > 23
        || minutes < 0
        || minutes > 59
    ) {
        return null;
    }

    return hours * 60 + minutes;
};

const findAvailabilityConflict = (rangesByDay, dayOfWeek, startValue, endValue) => {
    const start = timeToMinutes(startValue);
    const end = timeToMinutes(endValue);

    if (start === null || end === null) {
        return null;
    }

    if (start >= end) {
        return {
            field: 'end',
            message: 'L’heure de fin doit être après l’heure de début.',
        };
    }

    const dayRanges = Array.isArray(rangesByDay[String(dayOfWeek)])
        ? rangesByDay[String(dayOfWeek)]
        : [];

    for (const range of dayRanges) {
        const rangeStart = timeToMinutes(range.start ?? '');
        const rangeEnd = timeToMinutes(range.end ?? '');
        const rangeLabel = range.label ?? `${range.start} - ${range.end}`;

        if (rangeStart === null || rangeEnd === null) {
            continue;
        }

        if (start === rangeStart && end === rangeEnd) {
            return {
                field: 'start',
                message: `Cette plage horaire existe déjà : ${rangeLabel}.`,
            };
        }

        if (start >= rangeStart && start < rangeEnd) {
            return {
                field: 'start',
                message: `L’heure de début est déjà couverte par la plage ${rangeLabel}.`,
            };
        }

        if (end > rangeStart && end <= rangeEnd) {
            return {
                field: 'end',
                message: `L’heure de fin est déjà couverte par la plage ${rangeLabel}.`,
            };
        }

        if (start <= rangeStart && end >= rangeEnd) {
            return {
                field: 'start',
                message: `Cette plage chevauche déjà la plage ${rangeLabel}.`,
            };
        }
    }

    return null;
};

const validateAvailabilityForm = (form, shouldFocus = false) => {
    const dayInput = form.querySelector('[name="day_of_week"]');
    const startInput = form.querySelector('[name="start_time"]');
    const endInput = form.querySelector('[name="end_time"]');
    const alert = form.querySelector('[data-availability-conflict-alert]');
    const submitButton = form.querySelector('[data-availability-submit]');

    if (
        !(
            dayInput instanceof HTMLInputElement
            || dayInput instanceof HTMLSelectElement
        )
        || !(startInput instanceof HTMLInputElement)
        || !(endInput instanceof HTMLInputElement)
    ) {
        return true;
    }

    startInput.setCustomValidity('');
    endInput.setCustomValidity('');
    startInput.classList.remove('is-conflicting');
    endInput.classList.remove('is-conflicting');

    const conflict = findAvailabilityConflict(
        parseAvailabilityRanges(form),
        dayInput.value,
        startInput.value,
        endInput.value,
    );

    form.classList.toggle('has-availability-conflict', Boolean(conflict));

    if (submitButton instanceof HTMLButtonElement || submitButton instanceof HTMLInputElement) {
        submitButton.disabled = Boolean(conflict);
    }

    if (alert instanceof HTMLElement) {
        alert.hidden = !conflict;
        alert.textContent = conflict?.message ?? '';
    }

    if (!conflict) {
        return true;
    }

    const invalidInput = conflict.field === 'end' ? endInput : startInput;

    invalidInput.setCustomValidity(conflict.message);
    invalidInput.classList.add('is-conflicting');

    if (shouldFocus) {
        invalidInput.reportValidity();
        invalidInput.focus({ preventScroll: true });
    }

    return false;
};

const initAvailabilityForms = (scope = document) => {
    scope.querySelectorAll('[data-availability-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.availabilityReady === '1') {
            return;
        }

        form.dataset.availabilityReady = '1';
        validateAvailabilityForm(form);

        form.addEventListener('input', () => validateAvailabilityForm(form));
        form.addEventListener('change', () => validateAvailabilityForm(form));
    });
};

const updateAdminStatusForm = (form) => {
    if (!(form instanceof HTMLElement)) {
        return;
    }

    const select = form.querySelector('[data-admin-status-select]');
    const consequence = form.querySelector('[data-admin-status-consequence]');
    const rescheduleFields = form.querySelector('[data-admin-reschedule-fields]');
    const rescheduleInput = form.querySelector('[name="scheduled_at"], [name="rescheduled_at"]');

    if (!(select instanceof HTMLSelectElement)) {
        return;
    }

    const selectedOption = select.selectedOptions[0];

    if (consequence instanceof HTMLElement) {
        consequence.textContent = selectedOption?.dataset.consequence
            || 'Sélectionnez un statut pour afficher la conséquence.';
    }

    const isReschedule = select.value === 'reschedule';

    if (rescheduleFields instanceof HTMLElement) {
        rescheduleFields.classList.toggle('is-required', isReschedule);
    }

    if (rescheduleInput instanceof HTMLInputElement) {
        rescheduleInput.required = isReschedule;
    }
};

const initAdminStatusForms = (scope = document) => {
    scope.querySelectorAll('[data-admin-status-form]').forEach(updateAdminStatusForm);
};

const syncAdminNotificationKeys = (root, toastKeys) => {
    root.dataset.notificationToastKeys = JSON.stringify(toastKeys);
    root.symclinicNotificationToastKeys = new Set(toastKeys);
};

const pollAdminNotifications = async (root, shouldToast = true) => {
    const url = root.dataset.notificationsUrl;

    if (!url || document.hidden || !root.isConnected || root.dataset.notificationsBusy === '1') {
        return;
    }

    root.dataset.notificationsBusy = '1';

    const knownKeys = root.symclinicNotificationToastKeys instanceof Set
        ? root.symclinicNotificationToastKeys
        : new Set(parseJsonData(root.dataset.notificationToastKeys));

    try {
        const notificationUrl = new URL(url, window.location.href);
        notificationUrl.searchParams.set('_live', String(Date.now()));

        const response = await axios.get(notificationUrl.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Cache-Control': 'no-cache',
            },
        });
        const payload = response.data ?? {};
        const toastKeys = Array.isArray(payload.toast_keys) ? payload.toast_keys : [];
        const newKeys = toastKeys.filter((key) => !knownKeys.has(key));
        const renderedKeys = new Set(Array.from(
            root.querySelectorAll('[data-notification-key]'),
            (item) => item.dataset.notificationKey,
        ).filter(Boolean));
        const notificationListChanged = toastKeys.length !== knownKeys.size
            || toastKeys.some((key) => !knownKeys.has(key))
            || toastKeys.length !== renderedKeys.size
            || toastKeys.some((key) => !renderedKeys.has(key));

        if (notificationListChanged) {
            applyAjaxFragments(payload.fragments);
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'badge_count')) {
            updateAdminBadgeCounts(payload.badge_count);
        }
        syncAdminNotificationKeys(root, toastKeys);

        if (shouldToast && newKeys.length > 0) {
            showAjaxToast(payload.message ?? 'Un rendez-vous passé est encore en attente de décision admin.');
        }
    } catch (error) {
        // Le polling reste silencieux : le prochain passage retentera proprement.
    } finally {
        root.dataset.notificationsBusy = '0';
    }
};

const initAdminNotificationPolling = (scope = document) => {
    scope.querySelectorAll('[data-admin-notifications]').forEach((root) => {
        if (!(root instanceof HTMLElement) || root.dataset.notificationsReady === '1') {
            return;
        }

        root.dataset.notificationsReady = '1';
        syncAdminNotificationKeys(root, parseJsonData(root.dataset.notificationToastKeys));
        const interval = Math.max(
            5000,
            Number.parseInt(root.dataset.notificationsInterval ?? '10000', 10) || 10000,
        );

        pollAdminNotifications(root, false);
        const intervalId = window.setInterval(() => {
            if (!root.isConnected) {
                window.clearInterval(intervalId);
                return;
            }

            pollAdminNotifications(root);
        }, interval);
        root.symclinicNotificationInterval = intervalId;

        const handleVisibilityChange = () => {
            if (!root.isConnected) {
                document.removeEventListener('visibilitychange', handleVisibilityChange);
                return;
            }

            if (!document.hidden) {
                pollAdminNotifications(root, false);
            }
        };
        document.addEventListener('visibilitychange', handleVisibilityChange);
    });
};

const submitInstantFilterForm = (form, delay = 0) => {
    window.clearTimeout(form.symclinicInstantFilterTimeout);
    form.symclinicInstantFilterTimeout = window.setTimeout(() => {
        if (form.requestSubmit) {
            form.requestSubmit();
            return;
        }

        form.submit();
    }, delay);
};

const initInstantFilterForms = (scope = document) => {
    scope.querySelectorAll('[data-instant-filter]').forEach((form) => {
        if (
            !(form instanceof HTMLFormElement)
            || form.dataset.instantFilterReady === '1'
            || form.dataset.datatableManaged === '1'
        ) {
            return;
        }

        form.dataset.instantFilterReady = '1';

        form.addEventListener('input', (event) => {
            const target = event.target;

            if (
                target instanceof HTMLInputElement
                && ['search', 'text', 'tel', 'email'].includes(target.type)
            ) {
                submitInstantFilterForm(form, 350);
            }
        });

        form.addEventListener('change', (event) => {
            if (
                event.target instanceof HTMLSelectElement
                || event.target instanceof HTMLInputElement
            ) {
                submitInstantFilterForm(form);
            }
        });
    });
};

const normalizeSearchValue = (value) => value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase('fr')
    .trim();

const initProductCatalogSearch = (scope = document) => {
    scope.querySelectorAll('[data-product-catalog]').forEach((catalog) => {
        if (!(catalog instanceof HTMLElement) || catalog.dataset.productSearchReady === '1') {
            return;
        }

        const form = catalog.querySelector('[data-product-search-form]');
        const input = catalog.querySelector('[data-product-search-input]');
        const cards = [...catalog.querySelectorAll('[data-product-card]')];
        const emptyState = catalog.querySelector('[data-product-search-empty]');
        const count = catalog.querySelector('[data-product-result-count]');
        const plural = catalog.querySelector('[data-product-result-plural]');

        if (!(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement)) {
            return;
        }

        catalog.dataset.productSearchReady = '1';
        form.addEventListener('submit', (event) => event.preventDefault());

        const filterProducts = () => {
            const query = normalizeSearchValue(input.value);
            let visibleCount = 0;

            cards.forEach((card) => {
                const searchableText = normalizeSearchValue(card.dataset.productSearchText ?? card.textContent ?? '');
                const isVisible = query === '' || searchableText.includes(query);

                card.hidden = !isVisible;
                visibleCount += isVisible ? 1 : 0;
            });

            if (count) {
                count.textContent = String(visibleCount);
            }

            if (plural) {
                plural.textContent = visibleCount > 1 ? 's' : '';
            }

            if (emptyState instanceof HTMLElement) {
                emptyState.hidden = visibleCount !== 0;
            }
        };

        input.addEventListener('input', filterProducts);
        filterProducts();
    });
};

const refreshUserLiveData = async (root) => {
    const url = root.dataset.userLiveRefreshUrl;

    if (!url || root.dataset.userLiveRefreshBusy === '1' || document.hidden) {
        return;
    }

    root.dataset.userLiveRefreshBusy = '1';

    try {
        const refreshUrl = new URL(url, window.location.href);
        refreshUrl.searchParams.set('_live', String(Date.now()));

        const response = await axios.get(refreshUrl.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Cache-Control': 'no-cache',
            },
        });
        const payload = response.data ?? {};

        applyAjaxFragments(payload.fragments);
    } catch (error) {
        // L'espace client retentera au prochain cycle sans bloquer la navigation.
    } finally {
        root.dataset.userLiveRefreshBusy = '0';
    }
};

const initUserLiveRefresh = (scope = document) => {
    scope.querySelectorAll('[data-user-live-refresh]').forEach((root) => {
        if (!(root instanceof HTMLElement) || root.dataset.userLiveRefreshReady === '1') {
            return;
        }

        root.dataset.userLiveRefreshReady = '1';
        const interval = Math.max(
            5000,
            Number.parseInt(root.dataset.userLiveRefreshInterval ?? '10000', 10) || 10000,
        );

        refreshUserLiveData(root);

        root.symclinicUserLiveRefreshInterval = window.setInterval(() => {
            refreshUserLiveData(root);
        }, interval);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                refreshUserLiveData(root);
            }
        });
    });
};

const refreshAdminStoreReservations = async (root) => {
    const url = root.dataset.adminStoreLiveRefreshUrl;

    if (!url || root.dataset.adminStoreLiveRefreshBusy === '1' || document.hidden) {
        return;
    }

    root.dataset.adminStoreLiveRefreshBusy = '1';

    try {
        const refreshUrl = new URL(url, window.location.href);
        refreshUrl.searchParams.set('_live', String(Date.now()));
        const response = await axios.get(refreshUrl.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Cache-Control': 'no-cache',
            },
        });
        const payload = response.data ?? {};

        if (Number.parseInt(payload.expired ?? '0', 10) > 0) {
            applyAjaxFragments(payload.fragments);
            showAjaxToast('Une réservation boutique vient d’expirer. Les articles ont été remis en disponibilité.', true);
        }
    } catch (error) {
        // Une nouvelle tentative sera faite au prochain cycle sans interrompre le travail admin.
    } finally {
        root.dataset.adminStoreLiveRefreshBusy = '0';
    }
};

const initAdminStoreLiveRefresh = (scope = document) => {
    scope.querySelectorAll('[data-admin-store-live-refresh]').forEach((root) => {
        if (!(root instanceof HTMLElement) || root.dataset.adminStoreLiveRefreshReady === '1') {
            return;
        }

        root.dataset.adminStoreLiveRefreshReady = '1';
        const interval = Math.max(
            5000,
            Number.parseInt(root.dataset.adminStoreLiveRefreshInterval ?? '10000', 10) || 10000,
        );

        refreshAdminStoreReservations(root);
        root.symclinicAdminStoreLiveRefreshInterval = window.setInterval(() => {
            refreshAdminStoreReservations(root);
        }, interval);
    });
};

const padCountdownPart = (value) => String(Math.max(0, value)).padStart(2, '0');

const formatReservationCountdown = (remainingSeconds) => {
    const days = Math.floor(remainingSeconds / 86400);
    const hours = Math.floor((remainingSeconds % 86400) / 3600);
    const minutes = Math.floor((remainingSeconds % 3600) / 60);
    const seconds = remainingSeconds % 60;

    if (days > 0) {
        return `${days} j ${padCountdownPart(hours)} h ${padCountdownPart(minutes)} min`;
    }

    return `${padCountdownPart(hours)}:${padCountdownPart(minutes)}:${padCountdownPart(seconds)}`;
};

const getReservationCountdownState = (remainingSeconds, alertSeconds) => {
    if (remainingSeconds <= 0) {
        return 'expired';
    }

    const criticalSeconds = Math.max(60, Math.floor(alertSeconds / 4));

    if (remainingSeconds <= criticalSeconds) {
        return 'critical';
    }

    if (remainingSeconds <= alertSeconds) {
        return 'soon';
    }

    return 'comfortable';
};

const countdownStateLabels = {
    comfortable: 'Temps restant',
    soon: 'Expiration proche',
    critical: 'Délai critique',
    expired: 'Délai expiré',
};

const compactCountdownStateLabels = {
    comfortable: 'Reste',
    soon: 'À appeler',
    critical: 'Urgent',
    expired: 'Expiré',
};

const updateReservationCountdowns = () => {
    const now = Date.now();

    document.querySelectorAll('[data-reservation-countdown]').forEach((countdown) => {
        if (!(countdown instanceof HTMLElement)) {
            return;
        }

        const expiresAt = Date.parse(countdown.dataset.expiresAt ?? '');
        const startsAt = Date.parse(countdown.dataset.startsAt ?? '');
        const alertSeconds = Math.max(60, Number.parseInt(countdown.dataset.alertSeconds ?? '7200', 10) || 7200);

        if (!Number.isFinite(expiresAt)) {
            return;
        }

        const remainingSeconds = Math.max(0, Math.ceil((expiresAt - now) / 1000));
        const state = getReservationCountdownState(remainingSeconds, alertSeconds);
        const previousState = countdown.dataset.countdownState ?? '';
        const value = countdown.querySelector('[data-countdown-value]');
        const stateLabel = countdown.querySelector('[data-countdown-state]');
        const progress = countdown.querySelector('[data-countdown-progress]');

        countdown.dataset.countdownState = state;
        countdown.classList.remove(
            'reservation-countdown--comfortable',
            'reservation-countdown--soon',
            'reservation-countdown--critical',
            'reservation-countdown--expired',
        );
        countdown.classList.add(`reservation-countdown--${state}`);
        countdown.setAttribute('aria-label', `${countdownStateLabels[state]} : ${formatReservationCountdown(remainingSeconds)}`);

        if (value instanceof HTMLElement) {
            value.textContent = formatReservationCountdown(remainingSeconds);
        }

        if (stateLabel instanceof HTMLElement) {
            stateLabel.textContent = countdown.classList.contains('reservation-countdown--compact')
                ? compactCountdownStateLabels[state]
                : countdownStateLabels[state];
        }

        if (progress instanceof HTMLElement) {
            const totalDuration = Number.isFinite(startsAt) ? Math.max(1, expiresAt - startsAt) : Math.max(1, alertSeconds * 1000);
            const progressPercent = Math.max(0, Math.min(100, ((expiresAt - now) / totalDuration) * 100));
            progress.style.width = `${progressPercent}%`;
        }

        const row = countdown.closest('tr');

        if (row instanceof HTMLTableRowElement) {
            row.dataset.reservationUrgency = state;
        }

        if (previousState !== '' && previousState !== state) {
            const table = countdown.closest('table.data-table');
            const dataTable = table instanceof HTMLTableElement ? dataTableInstances.get(table) : null;
            dataTable?.draw(false);
        }

        if (state === 'expired' && countdown.dataset.countdownRefreshTriggered !== '1') {
            countdown.dataset.countdownRefreshTriggered = '1';
            document.querySelectorAll('[data-user-live-refresh]').forEach((root) => {
                if (root instanceof HTMLElement) {
                    refreshUserLiveData(root);
                }
            });
            document.querySelectorAll('[data-admin-store-live-refresh]').forEach((root) => {
                if (root instanceof HTMLElement) {
                    refreshAdminStoreReservations(root);
                }
            });
        }
    });
};

let reservationCountdownIntervalId = null;

const initReservationCountdowns = () => {
    updateReservationCountdowns();

    if (reservationCountdownIntervalId === null) {
        reservationCountdownIntervalId = window.setInterval(updateReservationCountdowns, 1000);
    }
};

const initStoreReservationSettings = (scope = document) => {
    scope.querySelectorAll('[data-store-reservation-settings]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.storeReservationSettingsReady === '1') {
            return;
        }

        const holdInput = form.querySelector('input[name="product_reservation_hold_minutes"]');
        const alertInput = form.querySelector('input[name="product_reservation_alert_minutes"]');

        if (!(holdInput instanceof HTMLInputElement) || !(alertInput instanceof HTMLInputElement)) {
            return;
        }

        form.dataset.storeReservationSettingsReady = '1';

        const validateAlert = () => {
            const holdMinutes = Math.max(1, Number.parseInt(holdInput.value, 10) || 1);
            const alertMinutes = Math.max(0, Number.parseInt(alertInput.value, 10) || 0);
            alertInput.max = String(holdMinutes);
            alertInput.setCustomValidity(
                alertMinutes > holdMinutes
                    ? 'L’alerte doit être inférieure ou égale à la durée totale de réservation.'
                    : '',
            );
        };

        holdInput.addEventListener('input', validateAlert);
        alertInput.addEventListener('input', validateAlert);
        validateAlert();
    });
};

const initRecruitmentPositionLinks = (scope = document) => {
    const positionSelect = scope.querySelector('[data-recruitment-position-select]');

    if (!(positionSelect instanceof HTMLSelectElement)) {
        return;
    }

    scope.querySelectorAll('[data-recruitment-position]').forEach((link) => {
        if (!(link instanceof HTMLElement) || link.dataset.recruitmentPositionReady === '1') {
            return;
        }

        link.dataset.recruitmentPositionReady = '1';
        link.addEventListener('click', () => {
            const selectedPosition = link.dataset.recruitmentPosition ?? '';
            const optionExists = Array.from(positionSelect.options).some((option) => option.value === selectedPosition);

            if (optionExists) {
                positionSelect.value = selectedPosition;
                positionSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });
};

const initHomepagePhotoSettings = (scope = document) => {
    scope.querySelectorAll('[data-homepage-photo-settings]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.homepagePhotoSettingsReady === '1') {
            return;
        }

        const preview = form.querySelector('[data-homepage-photo-preview]');
        const previewImage = form.querySelector('[data-homepage-photo-preview-image]');
        const previewValue = form.querySelector('[data-homepage-photo-preview-value]');
        const opacityInput = form.querySelector('[data-homepage-photo-opacity]');
        const opacityValue = form.querySelector('[data-homepage-photo-opacity-value]');
        const fileInput = form.querySelector('input[name="home_hero_photo"]');

        if (!(preview instanceof HTMLElement) || !(opacityInput instanceof HTMLInputElement)) {
            return;
        }

        form.dataset.homepagePhotoSettingsReady = '1';

        const updateOpacity = () => {
            const opacity = Math.max(0, Math.min(100, Number.parseInt(opacityInput.value, 10) || 0));
            const label = `${opacity} %`;

            preview.style.setProperty('--homepage-photo-opacity', `${opacity}%`);
            opacityInput.setAttribute('aria-valuetext', label);

            if (previewValue instanceof HTMLElement) {
                previewValue.textContent = label;
            }

            if (opacityValue instanceof HTMLOutputElement) {
                opacityValue.value = label;
                opacityValue.textContent = label;
            }
        };

        opacityInput.addEventListener('input', updateOpacity);

        if (fileInput instanceof HTMLInputElement && previewImage instanceof HTMLImageElement) {
            fileInput.addEventListener('change', () => {
                const [file] = fileInput.files ?? [];

                if (file instanceof File && file.type.startsWith('image/')) {
                    previewImage.src = URL.createObjectURL(file);
                }
            });
        }

        updateOpacity();
    });
};

const initSiteLogoSettings = (scope = document) => {
    scope.querySelectorAll('[data-site-logo-settings]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.siteLogoSettingsReady === '1') {
            return;
        }

        const input = form.querySelector('[data-site-logo-input]');
        const previewImage = form.querySelector('[data-site-logo-preview-image]');
        const fallback = form.querySelector('[data-site-logo-preview-fallback]');

        if (!(input instanceof HTMLInputElement) || !(previewImage instanceof HTMLImageElement)) {
            return;
        }

        form.dataset.siteLogoSettingsReady = '1';
        let previewUrl = null;

        input.addEventListener('change', () => {
            const [file] = input.files ?? [];

            if (!(file instanceof File) || !file.type.startsWith('image/')) {
                return;
            }

            if (previewUrl !== null) {
                URL.revokeObjectURL(previewUrl);
            }

            previewUrl = URL.createObjectURL(file);
            previewImage.src = previewUrl;
            previewImage.hidden = false;

            if (fallback instanceof HTMLElement) {
                fallback.hidden = true;
            }
        });
    });
};

let promotionParallaxFrame = null;

const updatePromotionParallax = () => {
    promotionParallaxFrame = null;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const compactViewport = window.innerWidth <= 700;

    document.querySelectorAll('[data-promotion-parallax]').forEach((section) => {
        if (!(section instanceof HTMLElement)) {
            return;
        }

        if (reducedMotion || compactViewport) {
            section.style.setProperty('--promotion-parallax-y', '0px');
            return;
        }

        const bounds = section.getBoundingClientRect();

        if (bounds.bottom < -200 || bounds.top > window.innerHeight + 200) {
            return;
        }

        const sectionCenter = bounds.top + (bounds.height / 2);
        const viewportCenter = window.innerHeight / 2;
        const travel = Math.max(-1, Math.min(1, (viewportCenter - sectionCenter) / window.innerHeight));

        section.style.setProperty('--promotion-parallax-y', `${(travel * 42).toFixed(1)}px`);
    });
};

const requestPromotionParallaxUpdate = () => {
    if (promotionParallaxFrame !== null) {
        return;
    }

    promotionParallaxFrame = window.requestAnimationFrame(updatePromotionParallax);
};

const initPromotionParallax = () => {
    if (!window.symclinicPromotionParallaxReady) {
        window.symclinicPromotionParallaxReady = true;
        window.addEventListener('scroll', requestPromotionParallaxUpdate, { passive: true });
        window.addEventListener('resize', requestPromotionParallaxUpdate);
        window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', requestPromotionParallaxUpdate);
    }

    requestPromotionParallaxUpdate();
};

const initNewsMediaForms = (scope = document) => {
    scope.querySelectorAll('[data-news-media-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.newsMediaReady === '1') {
            return;
        }

        const imageFields = form.querySelector('[data-news-image-fields]');
        const videoFields = form.querySelector('[data-news-video-fields]');
        const imageInput = imageFields?.querySelector('input[type="file"]');
        const youtubeInput = videoFields?.querySelector('input[name="youtube_url"]');

        if (!(imageFields instanceof HTMLElement) || !(videoFields instanceof HTMLElement)) {
            return;
        }

        form.dataset.newsMediaReady = '1';

        const syncFields = () => {
            const selected = form.querySelector('input[name="media_type"]:checked')?.value ?? 'image';
            const usesVideo = selected === 'video';

            imageFields.hidden = usesVideo;
            videoFields.hidden = !usesVideo;

            if (imageInput instanceof HTMLInputElement) {
                imageInput.disabled = usesVideo;
                imageInput.required = !usesVideo && imageInput.dataset.newsImageRequired === '1';
            }

            if (youtubeInput instanceof HTMLInputElement) {
                youtubeInput.disabled = !usesVideo;
                youtubeInput.required = usesVideo;
            }
        };

        form.querySelectorAll('input[name="media_type"]').forEach((input) => {
            input.addEventListener('change', syncFields);
        });

        form.addEventListener('reset', () => window.setTimeout(syncFields));
        syncFields();
    });
};

const initInteractiveComponents = () => {
    initFlashMessages();
    initSlotPickers();
    initBookingWizards();
    initAuthPanels();
    initAvailabilityForms();
    initAdminStatusForms();
    initAdminNotificationPolling();
    initReservationCountdowns();
    initDataTables();
    initInstantFilterForms();
    initProductCatalogSearch();
    initUserLiveRefresh();
    initAdminStoreLiveRefresh();
    initStoreReservationSettings();
    initRecruitmentPositionLinks();
    initHomepagePhotoSettings();
    initSiteLogoSettings();
    initPromotionParallax();
    initNewsMediaForms();
};

if (!window.symclinicModalReady) {
    window.symclinicModalReady = true;

    document.addEventListener('click', async (event) => {
        const slotDayButton = getClosestElement(event.target, '[data-slot-picker-day]');

        if (slotDayButton instanceof HTMLElement) {
            event.preventDefault();
            setActiveSlotPickerDay(slotDayButton.closest('[data-slot-picker]'), slotDayButton.dataset.slotPickerDay);
            return;
        }

        const slotButton = getClosestElement(event.target, '[data-slot-picker-slot]');

        if (slotButton instanceof HTMLElement) {
            event.preventDefault();
            selectSlotPickerSlot(slotButton.closest('[data-slot-picker]'), slotButton.dataset.slotPickerSlot);
            return;
        }

        const nextButton = getClosestElement(event.target, '[data-booking-next]');

        if (nextButton) {
            event.preventDefault();
            const wizard = nextButton.closest('[data-booking-wizard]');
            const currentStep = Number.parseInt(wizard?.dataset.bookingCurrentStep ?? '0', 10);

            if (wizard && await validateBookingStep(wizard, currentStep)) {
                setBookingStep(wizard, currentStep + 1, true);
            }

            return;
        }

        const previousButton = getClosestElement(event.target, '[data-booking-prev]');

        if (previousButton) {
            event.preventDefault();
            const wizard = previousButton.closest('[data-booking-wizard]');
            const currentStep = Number.parseInt(wizard?.dataset.bookingCurrentStep ?? '0', 10);

            if (wizard) {
                setBookingStep(wizard, currentStep - 1, true);
            }

            return;
        }

        const openButton = getClosestElement(event.target, '[data-modal-open]');

        if (openButton) {
            event.preventDefault();
            const currentModal = openButton.closest('dialog.modal');
            const targetModalId = openButton.dataset.modalOpen;

            if (
                currentModal instanceof HTMLDialogElement
                && currentModal.id !== targetModalId
            ) {
                closeModal(currentModal);
            }

            applyAppointmentPrefill(openButton);
            openModal(targetModalId);
            return;
        }

        const addAttributeButton = getClosestElement(event.target, '[data-add-attribute-row]');

        if (addAttributeButton instanceof HTMLElement) {
            event.preventDefault();
            addAttributeRow(addAttributeButton.closest('[data-attribute-list]'));
            return;
        }

        const removeAttributeButton = getClosestElement(event.target, '[data-remove-attribute-row]');

        if (removeAttributeButton instanceof HTMLElement) {
            event.preventDefault();
            removeAttributeRow(removeAttributeButton);
            return;
        }

        const authTab = getClosestElement(event.target, '[data-auth-tab]');

        if (authTab instanceof HTMLElement) {
            event.preventDefault();
            setAuthPanel(authTab.closest('[data-auth-shell]'), authTab.dataset.authTab, true);
            return;
        }

        const closeButton = getClosestElement(event.target, '[data-modal-close]');

        if (closeButton) {
            event.preventDefault();
            closeModal(closeButton.closest('dialog.modal'));
            return;
        }

        if (event.target instanceof HTMLDialogElement && event.target.classList.contains('modal')) {
            closeModal(event.target);
            return;
        }

        const link = getClosestElement(event.target, 'a[href]');

        if (link instanceof HTMLAnchorElement) {
            rememberScrollForLink(link);
        }
    });

    document.addEventListener('change', (event) => {
        const statusSelect = getClosestElement(event.target, '[data-admin-status-select]');

        if (statusSelect instanceof HTMLSelectElement) {
            updateAdminStatusForm(statusSelect.closest('[data-admin-status-form]'));
        }
    });

    document.addEventListener('submit', async (event) => {
        if (!(event.target instanceof HTMLFormElement)) {
            return;
        }

        const wizard = event.target.matches('[data-booking-wizard]') ? event.target : null;

        if (wizard) {
            if (wizard.dataset.bookingSubmitReady === '1') {
                delete wizard.dataset.bookingSubmitReady;
                return;
            }

            event.preventDefault();

            const steps = getBookingSteps(wizard);
            const currentStep = Number.parseInt(wizard.dataset.bookingCurrentStep ?? '0', 10);

            if (currentStep < steps.length - 1) {
                if (await validateBookingStep(wizard, currentStep)) {
                    setBookingStep(wizard, currentStep + 1, true);
                }

                return;
            }

            if (await validateBookingStep(wizard, currentStep)) {
                wizard.dataset.bookingSubmitReady = '1';
                wizard.requestSubmit();
            }

            return;
        }

        if (event.target.querySelector('[data-slot-picker]') && !validateSlotPickers(event.target)) {
            event.preventDefault();
            return;
        }

        if (event.target.matches('[data-availability-form]') && !validateAvailabilityForm(event.target, true)) {
            event.preventDefault();
            return;
        }

        if (event.target.matches('[data-ajax-form]')) {
            event.preventDefault();
            await submitAjaxForm(event.target);
            return;
        }

        rememberScrollForFormSubmit(event.target, event.submitter);
    });

    document.addEventListener('close', syncModalState, true);
    document.addEventListener('cancel', () => requestAnimationFrame(syncModalState), true);
    document.addEventListener('DOMContentLoaded', () => {
        initInteractiveComponents();
        openInitialModal();
        restoreScrollIntent();
    });
    document.addEventListener('turbo:load', () => {
        initInteractiveComponents();
        syncModalState();
        openInitialModal();
        restoreScrollIntent();
    });

    initInteractiveComponents();
    restoreScrollIntent();
}

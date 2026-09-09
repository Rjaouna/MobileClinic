import './stimulus_bootstrap.js';
import './styles/app.css';

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
        const response = await fetch(checkUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                phone: phoneInput.value,
                _token: tokenInput.value,
            }),
        });
        const payload = await response.json();

        if (payload.available) {
            if (typeof payload.normalized_phone === 'string') {
                phoneInput.value = payload.normalized_phone;
            }

            return true;
        }

        showBookingPhoneAlert(
            wizard,
            payload.message ?? 'Ce numéro de téléphone ne peut pas être utilisé pour ce rendez-vous.',
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

const initInteractiveComponents = () => {
    initSlotPickers();
    initBookingWizards();
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
            applyAppointmentPrefill(openButton);
            openModal(openButton.dataset.modalOpen);
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
        }
    });

    document.addEventListener('close', syncModalState, true);
    document.addEventListener('cancel', () => requestAnimationFrame(syncModalState), true);
    document.addEventListener('DOMContentLoaded', () => {
        initInteractiveComponents();
        openInitialModal();
    });
    document.addEventListener('turbo:load', () => {
        initInteractiveComponents();
        syncModalState();
        openInitialModal();
    });

    initInteractiveComponents();
}

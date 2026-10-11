import 'flowbite';
import { initFlowbite } from 'flowbite';
import Datepicker from 'flowbite-datepicker/Datepicker';

window.initFlowbite = initFlowbite;

function componentForElement(el) {
    const root = el.closest('[wire\\:id],[wire-id]');

    if (!root || !window.Livewire) {
        return null;
    }

    const id = root.getAttribute('wire:id') || root.getAttribute('wire-id');

    if (!id) {
        return null;
    }

    try {
        return window.Livewire.find(id);
    } catch (e) {
        return null;
    }
}

function toYearMonth(value) {
    if (typeof value !== 'string') {
        return '';
    }

    const match = value.match(/^(\d{4})-(\d{2})/);

    return match ? `${match[1]}-${match[2]}` : '';
}

function initMonthPickers() {
    document.querySelectorAll('[data-month-picker]').forEach((el) => {
        if (!(el instanceof HTMLInputElement)) {
            return;
        }

        if (el._monthPicker) {
            const current = toYearMonth(el.value);

            if (current !== '') {
                try {
                    el._monthPicker.setDate(`${current}-01`, { format: 'yyyy-mm-dd' });
                } catch (e) {}
            }

            return;
        }

        const picker = new Datepicker(el, {
            format: 'yyyy-mm',
            pickLevel: 1,
            startView: 1,
            autohide: true,
            todayBtn: false,
            clearBtn: true,
        });

        el._monthPicker = picker;

        if (toYearMonth(el.value) !== '') {
            try {
                picker.setDate(`${toYearMonth(el.value)}-01`, { format: 'yyyy-mm-dd' });
            } catch (e) {}
        }

        el.addEventListener('changeDate', () => {
            const yearMonth = toYearMonth(el.value);
            const field = el.getAttribute('data-month-picker');

            if (yearMonth !== '') {
                el.value = yearMonth;
            }

            if (!field) {
                return;
            }

            const component = componentForElement(el);

            if (component) {
                component.set(field, yearMonth);
            } else {
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        el.addEventListener('input', () => {
            const field = el.getAttribute('data-month-picker');
            const yearMonth = toYearMonth(el.value);

            if (!field || yearMonth === '') {
                return;
            }

            const component = componentForElement(el);

            if (component) {
                component.set(field, yearMonth);
            }
        });
    });
}

window.initMonthPickers = initMonthPickers;

function applyTheme() {
    const stored = localStorage.getItem('color-theme');

    if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

window.applyTheme = applyTheme;

document.addEventListener('DOMContentLoaded', () => {
    applyTheme();
    initFlowbite();
    initMonthPickers();
});

// Livewire SPA navigation (wire:navigate) swaps page content without a full
// reload, so re-apply the stored theme — otherwise the <html> class can fall
// back to light after navigating between pages.
document.addEventListener('livewire:navigated', () => {
    applyTheme();
    initFlowbite();
    initMonthPickers();
});

// The academic-year form is inserted by a Livewire roundtrip (showForm
// toggle), so re-scan after every morph. `morphed` is a Livewire JS hook,
// not a DOM event — `document.addEventListener('livewire:morphed')` never
// fires, which is why the calendar previously never opened.
function registerMonthPickerMorphHook() {
    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        window.Livewire.hook('morphed', () => {
            initMonthPickers();
        });

        return true;
    }

    return false;
}

if (!registerMonthPickerMorphHook()) {
    document.addEventListener('livewire:init', () => {
        registerMonthPickerMorphHook();
    });
}

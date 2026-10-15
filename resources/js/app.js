import 'flowbite';
import { initFlowbite } from 'flowbite';
import Datepicker from 'flowbite-datepicker/Datepicker';
import ApexCharts from 'apexcharts';

window.ApexCharts = ApexCharts;
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

/**
 * Money inputs (data-money): display thousands grouping for readability
 * while the Livewire model keeps the raw ungrouped string. Group on
 * blur, strip back to raw on focus. Invalid text is left untouched so
 * server validation still reports it. The backend (Money::fromDecimal)
 * already strips commas, so grouped values parse and store cleanly.
 */
function groupMoneyValue(value) {
    const raw = value.replace(/[,٬\s]/gu, '');

    if (!/^\d+(\.\d{1,2})?$/.test(raw)) {
        return null;
    }

    const [intPart, decPart] = raw.split('.');
    const grouped = Number(intPart).toLocaleString('en-US');

    return decPart !== undefined ? `${grouped}.${decPart}` : grouped;
}

function initMoneyInputs() {
    document.querySelectorAll('[data-money]').forEach((el) => {
        if (!(el instanceof HTMLInputElement)) {
            return;
        }

        if (el._moneyBound) {
            return;
        }
        el._moneyBound = true;

        el.addEventListener('focus', () => {
            el.value = el.value.replace(/[,٬\s]/gu, '');
        });

        el.addEventListener('blur', () => {
            const grouped = groupMoneyValue(el.value);

            if (grouped !== null) {
                el.value = grouped;
            }
        });

        // Never reformat the field the user is actively typing in;
        // morph echoes would otherwise move the caret mid-typing.
        if (document.activeElement === el) {
            return;
        }

        const grouped = groupMoneyValue(el.value);

        if (grouped !== null && grouped !== el.value) {
            el.value = grouped;
        }
    });
}

window.initMoneyInputs = initMoneyInputs;

/**
 * Dashboard charts (ApexCharts, bundled offline). Each [data-chart]
 * element carries its labels/series as JSON data attributes rendered
 * by Blade, so charts survive Livewire morphs: re-render after every
 * navigation and every morph.
 */
function renderDashboardCharts() {
    if (!window.ApexCharts) {
        return;
    }

    const isDark = document.documentElement.classList.contains('dark');
    const mode = isDark ? 'dark' : 'light';

    document.querySelectorAll('[data-chart]').forEach((el) => {
        if (!(el instanceof HTMLElement)) {
            return;
        }

        let config = null;
        const kind = el.getAttribute('data-chart');
        const labels = JSON.parse(el.getAttribute('data-labels') || '[]');

        if (kind === 'grade-stacked') {
            const collected = JSON.parse(el.getAttribute('data-collected') || '[]');
            const remaining = JSON.parse(el.getAttribute('data-remaining') || '[]');
            const cashLabel = el.getAttribute('data-cash-label') || '';
            const restLabel = el.getAttribute('data-rest-label') || '';
            config = {
                series: [
                    { name: cashLabel, data: collected },
                    { name: restLabel, data: remaining },
                ],
                chart: { type: 'bar', height: 280, stacked: true, background: 'transparent', toolbar: { show: false } },
                colors: ['#10b981', '#f59e0b'],
                xaxis: { categories: labels },
                legend: { position: 'bottom' },
                theme: { mode },
            };
        } else if (kind === 'status-donut' || kind === 'fee-donut') {
            const values = JSON.parse(el.getAttribute('data-values') || '[]');
            config = {
                series: values,
                labels,
                chart: { type: 'donut', height: 280, background: 'transparent' },
                legend: { position: 'bottom' },
                theme: { mode },
            };
        } else if (kind === 'monthly-line') {
            const values = JSON.parse(el.getAttribute('data-values') || '[]');
            const netLabel = el.getAttribute('data-net-label') || '';
            config = {
                series: [{ name: netLabel, data: values }],
                chart: { type: 'line', height: 280, background: 'transparent', toolbar: { show: false } },
                xaxis: { categories: labels },
                stroke: { curve: 'smooth', width: 3 },
                colors: ['#6366f1'],
                theme: { mode },
            };
        }

        if (!config) {
            return;
        }

        el.innerHTML = '';
        new ApexCharts(el, config).render();
    });
}

window.renderDashboardCharts = renderDashboardCharts;

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
    initMoneyInputs();
    renderDashboardCharts();
});

// Livewire SPA navigation (wire:navigate) swaps page content without a full
// reload, so re-apply the stored theme — otherwise the <html> class can fall
// back to light after navigating between pages.
document.addEventListener('livewire:navigated', () => {
    applyTheme();
    initFlowbite();
    initMonthPickers();
    initMoneyInputs();
    renderDashboardCharts();
});

// The academic-year form is inserted by a Livewire roundtrip (showForm
// toggle), so re-scan after every morph. `morphed` is a Livewire JS hook,
// not a DOM event — `document.addEventListener('livewire:morphed')` never
// fires, which is why the calendar previously never opened.
function registerMonthPickerMorphHook() {
    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        window.Livewire.hook('morphed', () => {
            initMonthPickers();
            initMoneyInputs();
            renderDashboardCharts();
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

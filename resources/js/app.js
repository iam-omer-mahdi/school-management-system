import 'flowbite';
import { initFlowbite } from 'flowbite';

window.initFlowbite = initFlowbite;

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
});

// Livewire SPA navigation (wire:navigate) swaps page content without a full
// reload, so re-apply the stored theme — otherwise the <html> class can fall
// back to light after navigating between pages.
document.addEventListener('livewire:navigated', () => {
    applyTheme();
    initFlowbite();
});

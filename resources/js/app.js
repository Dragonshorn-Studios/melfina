// Import the knowledge graph modules
// D3 version (if installed): import './knowledge-graph-interactive.js';
// Lightweight version (no dependencies):
import './knowledge-graph.js';
import './knowledge-graph-interactive-simple.js';

// Styled confirmation for destructive console forms: any <form> with a
// data-confirm="…" attribute is intercepted and gated behind the shared
// <dialog id="melfina-confirm"> rendered by the console layout. The flag
// below lets the form's inline onsubmit skip its native confirm() fallback
// once this bundle has loaded — without it the gate is a plain confirm().
window.__melfinaConfirm = true;

document.addEventListener('change', (event) => {
    const radio = event.target;

    if (! (radio instanceof HTMLInputElement) || ! radio.hasAttribute('data-described-select')) {
        return;
    }

    const root = radio.closest('[data-described-select-root]');
    const label = root?.querySelector('[data-described-select-label]');

    if (label instanceof HTMLElement) {
        label.textContent = radio.dataset.label ?? radio.value;
    }

    root?.querySelector('details')?.removeAttribute('open');
});

document.addEventListener('click', (event) => {
    if (! (event.target instanceof Element)) {
        return;
    }

    document.querySelectorAll('[data-described-select-root] details[open]').forEach((details) => {
        if (! details.parentElement?.contains(event.target)) {
            details.removeAttribute('open');
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    document.querySelectorAll('[data-described-select-root] details[open]').forEach((details) => {
        details.removeAttribute('open');
    });
});

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
        return;
    }

    const dialog = document.getElementById('melfina-confirm');

    if (!(dialog instanceof HTMLDialogElement)) {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }

        return;
    }

    event.preventDefault();

    dialog.querySelector('#melfina-confirm-message').textContent = form.dataset.confirm;
    dialog.showModal();

    dialog.querySelector('#melfina-confirm-yes').onclick = () => {
        dialog.close();
        form.submit();
    };
}, true);

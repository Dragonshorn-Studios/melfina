import './knowledge-graph.js';

// Styled confirmation for destructive console forms: any <form> with a
// data-confirm="…" attribute is intercepted and gated behind the shared
// <dialog id="melfina-confirm"> rendered by the console layout. The flag
// below lets the form's inline onsubmit skip its native confirm() fallback
// once this bundle has loaded — without it the gate is a plain confirm().
window.__melfinaConfirm = true;

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

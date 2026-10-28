export default function registerSalesAgendaPaper() {
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-paper-submit]');
        const item = form?.closest('[data-agenda-paper-item]');

        if (!form || !item || form.dataset.submitting === 'true') {
            return;
        }

        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        event.preventDefault();
        form.dataset.submitting = 'true';
        item.classList.add('is-tearing');

        let submitted = false;
        const submit = () => {
            if (submitted) {
                return;
            }

            submitted = true;
            HTMLFormElement.prototype.submit.call(form);
        };
        item.addEventListener('animationend', submit, { once: true });
        window.setTimeout(submit, 850);
    });
}

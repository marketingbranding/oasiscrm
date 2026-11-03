export function syncAgendaCompletionState(form) {
    const result = form.querySelector('[data-agenda-result]');
    const submit = form.querySelector('[data-complete-agenda]');

    if (!result || !submit) {
        return;
    }

    const hasResult = result.value.trim().length > 0;
    submit.disabled = !hasResult;
    submit.setAttribute('aria-disabled', String(!hasResult));
}

export default function registerSalesAgendaDetail() {
    document.querySelectorAll('[data-completion-form]').forEach((form) => {
        const result = form.querySelector('[data-agenda-result]');

        if (!result) {
            return;
        }

        const sync = () => syncAgendaCompletionState(form);
        result.addEventListener('input', sync);
        result.addEventListener('change', sync);
        sync();
    });

    document.querySelectorAll('[data-agenda-photo-slot]').forEach((form) => {
        const input = form.querySelector('[data-photo-input]');

        if (!input) {
            return;
        }

        input.addEventListener('change', () => {
            if (input.files?.length) {
                form.requestSubmit();
            }
        });
    });
}

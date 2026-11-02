export default function registerSalesAgendaDetail() {
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

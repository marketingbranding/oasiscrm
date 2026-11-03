export default function registerSalesAgendaPaper() {
    const soundKey = 'oasis.salesAgenda.tearSound';

    document.querySelectorAll('[data-sales-tear-sound]').forEach((toggle) => {
        const enabled = window.localStorage?.getItem(soundKey) !== 'false';
        toggle.setAttribute('aria-pressed', String(enabled));
        toggle.textContent = `Suara sobek: ${enabled ? 'aktif' : 'nonaktif'}`;
        toggle.addEventListener('click', () => {
            const next = toggle.getAttribute('aria-pressed') !== 'true';
            window.localStorage?.setItem(soundKey, String(next));
            toggle.setAttribute('aria-pressed', String(next));
            toggle.textContent = `Suara sobek: ${next ? 'aktif' : 'nonaktif'}`;
        });
    });

    document.addEventListener('submit', (event) => {
        const receiptForm = event.target.closest('[data-receipt-submit]');
        if (receiptForm && receiptForm.dataset.submitting !== 'true') {
            event.preventDefault();
            receiptForm.dataset.submitting = 'true';
            receiptForm.closest('.sales-receipt-canvas')?.classList.add('is-tearing');
            playTearSound();
            const submit = () => HTMLFormElement.prototype.submit.call(receiptForm);
            if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
                submit();
            } else {
                window.setTimeout(submit, 1500);
            }

            return;
        }

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

    function playTearSound() {
        if (window.localStorage?.getItem(soundKey) === 'false' || !window.AudioContext) {
            return;
        }

        const context = new window.AudioContext();
        const buffer = context.createBuffer(1, context.sampleRate * 0.16, context.sampleRate);
        const data = buffer.getChannelData(0);
        for (let index = 0; index < data.length; index += 1) {
            data[index] = (Math.random() * 2 - 1) * (1 - index / data.length);
        }
        const source = context.createBufferSource();
        const gain = context.createGain();
        source.buffer = buffer;
        gain.gain.value = 0.18;
        source.connect(gain).connect(context.destination);
        source.start();
        source.addEventListener('ended', () => context.close(), { once: true });
    }
}

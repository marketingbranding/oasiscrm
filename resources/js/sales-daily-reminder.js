export default function registerSalesDailyReminder(Alpine) {
    Alpine.data('salesDailyReminder', config => ({
        open: Boolean(config.shouldShow),
        hideToday: false,
        dismissed: false,
        dismissPromise: null,
        actionPending: false,
        trigger: null,

        init() {
            if (!this.open) return;
            this.trigger = document.activeElement === document.body
                ? document.querySelector('a[href*="/buku-saku-sales"]')
                : document.activeElement;
            this.$nextTick(() => this.$refs.dialog?.querySelector('a, button, input')?.focus({ preventScroll: true }));
        },

        async persistDismissal() {
            if (!this.hideToday || this.dismissed) return;
            if (this.dismissPromise) return this.dismissPromise;

            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 3000);
            this.dismissPromise = fetch(config.dismissUrl, {
                method: 'POST',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ reminder_key: config.reminderKey }),
            }).then(async response => {
                if (!response.ok) throw new Error();
                this.dismissed = true;
                return true;
            }).catch(() => {
                window.oasisToast('Pengingat belum dapat disembunyikan untuk hari ini. Pengingat mungkin muncul kembali.', 'warning');
                return false;
            }).finally(() => {
                window.clearTimeout(timeout);
                this.dismissPromise = null;
            });

            return this.dismissPromise;
        },

        async close() {
            if (this.actionPending) return;
            this.actionPending = true;
            this.open = false;
            this.$nextTick(() => this.trigger?.focus?.({ preventScroll: true }));
            await this.persistDismissal();
            this.actionPending = false;
        },

        async navigate(url) {
            if (this.actionPending) return;
            this.actionPending = true;
            this.open = false;
            const persisted = await this.persistDismissal();
            const destination = new URL(url, window.location.origin);
            if (this.hideToday && persisted === false) destination.searchParams.set('reminder_dismiss_failed', '1');
            window.location.assign(destination.toString());
        },

        conflictOpen() { return document.documentElement.dataset.oasisConflictOpen === '1'; },

        trapFocus(event) {
            const focusable = [...this.$refs.dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                .filter(element => element.offsetParent !== null);
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        },
    }));
}

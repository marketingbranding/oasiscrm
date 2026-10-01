import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';

export default function registerConsumerWorkspace(Alpine) {
    Alpine.data('consumerWorkspace', (config) => ({
        open: false,
        loading: false,
        error: '',
        detail: null,
        recordId: null,
        activeTab: 'overview',
        trigger: null,
        controller: null,
        lockOwner: 'consumer-workspace-drawer',

        async openDetail(id, trigger = null) {
            this.trigger = trigger || document.activeElement;
            this.recordId = id;
            this.open = true;
            this.loading = true;
            this.error = '';
            this.detail = null;
            this.activeTab = 'overview';
            this.controller?.abort();
            this.controller = new AbortController();
            lockBodyScroll(this.lockOwner);
            this.$nextTick(() => this.$refs.drawerClose?.focus());

            try {
                const response = await fetch(config.detailUrl.replace('__ID__', encodeURIComponent(id)), {
                    headers: { Accept: 'application/json' },
                    signal: this.controller.signal,
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(response.status === 403 ? 'Kamu tidak memiliki akses ke data ini.' : 'Detail konsumen belum dapat dimuat.');
                }
                this.detail = payload.data;
            } catch (exception) {
                if (exception.name !== 'AbortError') {
                    this.error = exception.message === 'Kamu tidak memiliki akses ke data ini.'
                        ? exception.message
                        : 'Detail konsumen belum dapat dimuat. Silakan coba lagi.';
                }
            } finally {
                this.loading = false;
            }
        },

        closeDetail() {
            this.controller?.abort();
            this.open = false;
            this.loading = false;
            this.error = '';
            this.detail = null;
            this.recordId = null;
            unlockBodyScroll(this.lockOwner);
            this.$nextTick(() => this.trigger?.focus());
        },

        retry() {
            const id = this.recordId;
            if (id) {
                this.openDetail(id, this.trigger);
            }
        },

        handleKeydown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeDetail();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }

            const focusable = Array.from(this.$refs.drawer.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )).filter((element) => element.offsetParent !== null);
            if (focusable.length === 0) {
                event.preventDefault();
                this.$refs.drawer.focus();
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        formatDate(value) {
            if (!value) {
                return '—';
            }

            return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(value));
        },

        formatDateTime(value) {
            if (!value) {
                return '—';
            }

            return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
        },

        formatValue(value) {
            return value === null || value === undefined || value === '' ? '—' : value;
        },
    }));
}

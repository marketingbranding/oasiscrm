import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';

export default function registerConsumerWorkspace(Alpine) {
    Alpine.data('consumerWorkspace', (config) => ({
        open: false,
        loading: false,
        error: '',
        detail: null,
        recordId: null,
        activeTab: 'overview',
        leadLink: { status: 'linked', sales_lead_id: '', reason: '', legacy_anchor: '' },
        leadLinkSaving: false,
        leadLinkError: '',
        leadLinkSuccess: '',
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
                this.syncLeadLinkForm();
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

        syncLeadLinkForm() {
            const current = this.detail?.lead_link?.current;
            this.leadLink = {
                status: current?.status || 'linked',
                sales_lead_id: current?.sales_lead_id ? String(current.sales_lead_id) : '',
                reason: current?.reason || '',
                legacy_anchor: current?.legacy_anchor || '',
            };
            this.leadLinkError = '';
            this.leadLinkSuccess = '';
        },

        leadLinkStatusLabel(status) {
            return { linked: 'Lead', without_lead: 'Tanpa Lead', legacy: 'Data Lama' }[status] || status || '—';
        },

        async submitLeadLinkDecision() {
            if (this.leadLinkSaving) return;

            this.leadLinkSaving = true;
            this.leadLinkError = '';
            this.leadLinkSuccess = '';
            const body = { ...this.leadLink, suggestions: this.detail?.lead_link?.suggestions || [] };
            if (body.sales_lead_id) body.sales_lead_id = Number(body.sales_lead_id);
            if (body.status !== 'linked') delete body.sales_lead_id;
            if (body.status !== 'legacy') delete body.legacy_anchor;

            try {
                const response = await fetch(config.leadLinkDecisionUrl.replace('__ID__', encodeURIComponent(this.recordId)), {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify(body),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const messages = Object.values(payload.errors || {}).flat();
                    throw new Error(messages[0] || payload.message || 'Keputusan belum dapat disimpan.');
                }
                this.leadLinkSuccess = payload.message || 'Keputusan penautan Lead berhasil disimpan.';
                await this.openDetail(this.recordId, this.trigger);
                this.activeTab = 'lead_link';
            } catch (exception) {
                this.leadLinkError = exception.message;
            } finally {
                this.leadLinkSaving = false;
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

         processUrl(process) {
             return (config.processUrls?.[process] || '').replace('__ID__', encodeURIComponent(this.recordId));
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

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
        bulkLegacyResult: null,
        bulkLegacyBranchId: '',
        bulkLegacyProjectId: '',
        bulkLegacyBefore: '',
        bulkLegacyReason: '',
        bulkLegacyError: '',
        bulkLegacySummary: null,
        subjectPanelOpen: false,
        subjectLoading: false,
        subjectError: '',
        subject: null,
        subjectDecision: { status: 'without_lead', reason: '', sales_lead_id: '' },
        subjectDecisionSaving: false,
        leadLinkError: '',
        leadLinkSuccess: '',
        trigger: null,
        controller: null,
        lockOwner: 'consumer-workspace-drawer',

        init() {
            this.bulkLegacyBranchId = config.bulkLegacyBranchId || '';
            this.bulkLegacyProjectId = config.bulkLegacyProjectId || '';
            this.bulkLegacyBefore = config.bulkLegacyBefore || '';
        },

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

        async previewBulkLegacy() {
            this.bulkLegacyError = '';
            this.bulkLegacySummary = null;
            try {
                const response = await fetch(`${config.bulkLegacyPreviewUrl}?branch_id=${this.bulkLegacyBranchId}&project_id=${this.bulkLegacyProjectId}&before=${this.bulkLegacyBefore}`,  { headers: { Accept: 'application/json' } });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Pratinjau gagal.');
                this.bulkLegacyResult = payload.data;
            } catch (exception) {
                this.bulkLegacyError = exception.message;
            }
        },

        async submitBulkLegacy(reason) {
            this.bulkLegacyError = '';
            try {
                const response = await fetch(config.bulkLegacyStoreUrl, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ reason, token: this.bulkLegacyResult?.token, branch_id: Number(this.bulkLegacyBranchId), project_id: this.bulkLegacyProjectId ? Number(this.bulkLegacyProjectId) : null, before: this.bulkLegacyBefore }),
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'Data Lama belum dapat disimpan.');
                this.bulkLegacySummary = payload.data;
                this.bulkLegacyResult = null;
            } catch (exception) {
                this.bulkLegacyError = exception.message || 'Data Lama belum dapat disimpan.';
            }
        },

        async openSubject(subjectType, subjectId, trigger = null) {
            this.subjectPanelOpen = true;
            this.subjectLoading = true;
            this.subjectError = '';
            this.subject = null;
            this.subjectDecision = { status: 'without_lead', reason: '', sales_lead_id: '' };
            this.$nextTick(() => this.$refs.subjectClose?.focus());
            try {
                const url = config.subjectUrl.replace('__TYPE__', encodeURIComponent(subjectType)).replace('__ID__', encodeURIComponent(subjectId));
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Subjek belum dapat dimuat.');
                this.subject = payload.data.subject;
            } catch (exception) {
                this.subjectError = exception.message || 'Subjek belum dapat dimuat.';
            } finally {
                this.subjectLoading = false;
            }
        },

        async submitSubjectDecision() {
            this.subjectDecisionSaving = true;
            this.subjectError = '';
            try {
                const url = config.subjectDecisionUrl.replace('__TYPE__', encodeURIComponent(this.subject.subject_type)).replace('__ID__', encodeURIComponent(this.subject.subject_id));
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify(this.subjectDecision),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'Keputusan belum dapat disimpan.');
                await this.openSubject(this.subject.subject_type, this.subject.subject_id);
            } catch (exception) {
                this.subjectError = exception.message || 'Keputusan belum dapat disimpan.';
            } finally {
                this.subjectDecisionSaving = false;
            }
        },

        closeSubject() {
            this.subjectPanelOpen = false;
            this.subject = null;
            this.subjectError = '';
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
            const suggestions = (this.detail?.lead_link?.suggestions || []).map((suggestion) => ({
                lead_id: suggestion.lead_id,
                score: suggestion.score,
                rationale: suggestion.rationale,
            }));
            const body = { ...this.leadLink, suggestions };
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
                const successMessage = payload.message || 'Keputusan penautan Lead berhasil disimpan.';
                await this.openDetail(this.recordId, this.trigger);
                this.activeTab = 'lead_link';
                this.leadLinkSuccess = successMessage;
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

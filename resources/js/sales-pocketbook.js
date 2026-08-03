import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';

export default function registerSalesPocketbook(Alpine) {
    if (typeof window.salesPocketbook === 'function') {
        Alpine.data('salesPocketbook', window.salesPocketbook);
    }

    Alpine.data('salesCascade', (projects, salesUsers, initial = {}) => ({
        projects,
        salesUsers,
        submitting: false,
        branch: String(initial.branch || ''),
        project: String(initial.project || ''),
        sales: String(initial.sales || ''),

        projectVisible(id) {
            return !this.branch || this.projects.find((item) => item.id === String(id))?.branch_id === this.branch;
        },

        salesVisible(id) {
            const sales = this.salesUsers.find((item) => item.id === String(id));
            if (!sales) return false;
            if (this.project) return sales.project_ids.includes(this.project);
            if (this.branch) {
                return sales.project_ids.some((projectId) => (
                    this.projects.find((item) => item.id === projectId)?.branch_id === this.branch
                ));
            }
            return true;
        },

        branchChanged() {
            if (this.project && !this.projectVisible(this.project)) this.project = '';
            if (this.sales && !this.salesVisible(this.sales)) this.sales = '';
        },

        projectChanged() {
            const selected = this.projects.find((item) => item.id === this.project);
            if (selected) this.branch = selected.branch_id;
            if (this.sales && !this.salesVisible(this.sales)) this.sales = '';
        },

        setSubmitting() {
            window.setTimeout(() => { this.submitting = true; }, 0);
        },
    }));

    Alpine.data('salesDuplicatePhone', (endpoint, exceptId = null) => ({
        duplicates: [],
        duplicatePending: false,
        phoneController: null,
        phoneRequestId: 0,

        async checkPhone(phone) {
            this.phoneController?.abort();
            const requestId = ++this.phoneRequestId;
            if (!phone) {
                this.duplicates = [];
                this.duplicatePending = false;
                return;
            }

            const controller = new AbortController();
            this.phoneController = controller;
            this.duplicatePending = true;
            try {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('phone', phone);
                if (exceptId) url.searchParams.set('except_id', exceptId);
                const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                const matches = (await response.json()).matches;
                if (requestId === this.phoneRequestId) this.duplicates = matches;
            } catch (error) {
                if (error.name === 'AbortError' || requestId !== this.phoneRequestId) return;
                this.duplicates = [];
                window.oasisToast('Pemeriksaan nomor duplikat belum tersedia. Data tetap dapat disimpan.', 'warning');
            } finally {
                if (requestId === this.phoneRequestId) {
                    this.duplicatePending = false;
                    this.phoneController = null;
                }
            }
        },
    }));

    Alpine.magic('salesBodyScroll', () => ({
        lock: lockBodyScroll,
        unlock: unlockBodyScroll,
    }));
}

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
        optionsEndpoint: initial.optionsEndpoint || '',
        promoOptionsEndpoint: initial.promoOptionsEndpoint || '',
        leadDate: String(initial.leadDate || ''),
        sheetOptions: { promo: initial.promos || [], source: [], channel: [], activity: [], project: [], sales: [], status: [] },
        promoOptions: initial.promos || [],
        optionsLoading: false,
        optionsError: '',
        source: String(initial.source || ''),
        historicalSource: '',
        platform: String(initial.platform || ''),
        campaignName: String(initial.campaignName || ''),
        promo: String(initial.promo || ''),

        init() {
            if (this.optionsEndpoint && this.branch) this.loadSheetOptions();
        },

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
            if (this.optionsEndpoint) this.loadSheetOptions();
        },

        async loadSheetOptions() {
            if (!this.branch) return;
            this.optionsLoading = true;
            this.optionsError = '';
            try {
                const response = await fetch(this.optionsEndpoint.replace('BRANCH_ID', this.branch), { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                this.sheetOptions = (await response.json()).options;
                if (this.source && !this.sheetOptions.source.includes(this.source)) {
                    this.historicalSource = this.source;
                    this.source = '';
                } else {
                    this.historicalSource = '';
                }
                if (this.platform && !this.sheetOptions.channel.includes(this.platform)) this.platform = '';
                if (this.campaignName && !this.sheetOptions.activity.includes(this.campaignName)) this.campaignName = '';
                if (this.promo && !this.sheetOptions.promo.includes(this.promo)) this.promo = '';
            } catch (_) {
                this.optionsError = 'Pilihan spreadsheet cabang belum dapat dimuat.';
                this.sheetOptions = { promo: [], source: [], channel: [], activity: [], project: [], sales: [], status: [] };
            } finally {
                this.optionsLoading = false;
            }
        },

        projectChanged() {
            const previousBranch = this.branch;
            const selected = this.projects.find((item) => item.id === this.project);
            if (selected) this.branch = selected.branch_id;
            if (this.sales && !this.salesVisible(this.sales)) this.sales = '';
            if (this.optionsEndpoint && this.branch && this.branch !== previousBranch) this.loadSheetOptions();
            if (this.promoOptionsEndpoint) this.loadPromoOptions();
        },

        async loadPromoOptions() {
            if (!this.project || !this.leadDate) return;
            const url = new URL(this.promoOptionsEndpoint.replace('PROJECT_ID', this.project), window.location.origin);
            url.searchParams.set('date', this.leadDate);
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            this.promoOptions = (await response.json()).options;
            if (!this.promoOptions.includes(this.promo)) this.promo = 'No Promo';
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

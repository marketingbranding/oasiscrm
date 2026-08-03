export default function registerDanaTalangan(Alpine) {
    Alpine.data('danaTalanganPage', detailModal => ({
        ...detailModal,
        adding: false,
        editingRecord: null,
        filterMode: 'date',
        filterOpen: false,
        tableFrozen: true,
        updateBaseUrl: '',
        kavlingOptionsUrl: '',
        projectCatalog: [],
        addForm: {
            branch_id: '',
            project_name: '',
            kav: '',
        },
        addKavlings: [],
        editKavlings: [],
        addKavLoading: false,
        editKavLoading: false,
        modalTriggers: {},
        modalScrollOwner: 'dana-talangan-modal',
        modalFocusSelector: '',

        init() {
            const config = this.$root.querySelector('[data-dana-talangan-config]');
            Object.assign(this, JSON.parse(config?.textContent || '{}'));
            this.initModalState();

            if (this.addForm.project_name) {
                this.loadAddKavlings(true);
            }
        },

        projectsFor(branchId, current = '') {
            const projects = this.projectCatalog.filter(project => String(project.branch_id) === String(branchId || ''));
            if (current && !projects.some(project => project.name === current)) {
                projects.push({ id: null, name: current, branch_id: branchId });
            }

            return projects;
        },

        normalizeKav(value) {
            return String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        },

        async fetchKavlings(branchId, projectName) {
            if (!branchId || !projectName) return [];

            const params = new URLSearchParams({ branch_id: branchId, project_name: projectName });
            const response = await fetch(`${this.kavlingOptionsUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) return [];

            const data = await response.json();

            return data.options || [];
        },

        async loadAddKavlings(preserve = false) {
            const projectName = this.addForm.project_name;
            const current = preserve ? this.addForm.kav : '';
            this.addKavLoading = true;
            this.addKavlings = await this.fetchKavlings(this.addForm.branch_id, projectName);

            if (projectName !== this.addForm.project_name) {
                this.addKavLoading = false;

                return;
            }

            if (current && !this.addKavlings.some(code => this.normalizeKav(code) === this.normalizeKav(current))) {
                this.addKavlings.push(current);
            }
            if (!preserve) this.addForm.kav = '';
            this.addKavLoading = false;
        },

        changeAddBranch() {
            this.addForm.project_name = '';
            this.addForm.kav = '';
            this.addKavlings = [];
        },

        changeAddProject() {
            this.addForm.kav = '';
            this.loadAddKavlings();
        },

        firstFocusable(panel) {
            if (!panel) return null;

            return Array.from(panel.querySelectorAll(this.modalFocusSelector))
                .find(element => element.offsetParent !== null) || panel;
        },

        trapModalFocus(event, key) {
            const panel = this.$refs[key];
            if (!panel) return;

            const focusable = Array.from(panel.querySelectorAll(this.modalFocusSelector))
                .filter(element => element.offsetParent !== null);
            if (focusable.length === 0) {
                event.preventDefault();
                panel.focus();

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

        lockModalScroll() {
            window.oasisBodyScroll?.lock(this.modalScrollOwner);
        },

        unlockModalScroll() {
            window.oasisBodyScroll?.unlock(this.modalScrollOwner);
        },

        openModal(key, trigger, focusTarget = null) {
            this.modalTriggers[key] = trigger;
            this.lockModalScroll();
            this.$nextTick(() => {
                const panel = this.$refs[key];
                const target = focusTarget || this.firstFocusable(panel);
                target?.focus();
            });
        },

        closeModal(key) {
            const trigger = this.modalTriggers[key];
            this.modalTriggers[key] = null;
            this.unlockModalScroll();
            this.$nextTick(() => trigger?.focus());
        },

        initModalState() {
            if (this.adding) {
                this.lockModalScroll();
                this.$nextTick(() => this.firstFocusable(this.$refs.addModalPanel)?.focus());
            }
        },

        openEdit(record, trigger) {
            this.editingRecord = record;
            this.editKavlings = [];
            this.modalTriggers.editModalPanel = trigger;
            this.lockModalScroll();
            this.$nextTick(() => {
                this.loadEditKavlings(true);
                this.firstFocusable(this.$refs.editModalPanel)?.focus();
            });
        },

        async loadEditKavlings(preserve = false) {
            if (!this.editingRecord) return;

            const projectName = this.editingRecord.project_name;
            const current = preserve ? this.editingRecord.kav : '';
            this.editKavLoading = true;
            this.editKavlings = await this.fetchKavlings(this.editingRecord.branch_id, projectName);

            if (!this.editingRecord || projectName !== this.editingRecord.project_name) {
                this.editKavLoading = false;

                return;
            }

            if (current && !this.editKavlings.some(code => this.normalizeKav(code) === this.normalizeKav(current))) {
                this.editKavlings.push(current);
            }
            if (!preserve) this.editingRecord.kav = '';
            this.editKavLoading = false;
        },

        changeEditBranch() {
            this.editingRecord.project_name = '';
            this.editingRecord.kav = '';
            this.editKavlings = [];
        },

        changeEditProject() {
            this.editingRecord.kav = '';
            this.loadEditKavlings();
        },

        closeFilterModal() {
            if (!this.filterOpen) return;
            this.filterOpen = false;
            this.closeModal('filterModalPanel');
        },

        closeAddModal() {
            if (!this.adding) return;
            this.adding = false;
            this.closeModal('addModalPanel');
        },

        closeEditModal() {
            if (!this.editingRecord) return;
            this.editingRecord = null;
            this.closeModal('editModalPanel');
        },
    }));
}

const roleDescriptions = {
    sales: 'Memiliki akses ke data miliknya sendiri dan ditempatkan dalam Team di bawah Sales Coordinator.',
    sales_coordinator: 'Mengelola Sales yang secara aktif dipetakan ke Team-nya.',
    supervisor: 'Memantau operasional dalam scope cabang.',
    branch_manager: 'Memantau scope cabang.',
    sas: 'Administrasi dan monitoring Sales pada scope cabang.',
    sam: 'Administrasi dan monitoring Marketing pada scope cabang.',
    superadmin: 'Akses luas melalui permission yang terdaftar.',
};

export default function registerAdminUserCreation(Alpine) {
    Alpine.data('adminUserCreation', (config = {}) => ({
        step: config.initialStep ?? 1,
        roles: config.roles ?? [],
        branches: config.branches ?? [],
        projects: config.projects ?? [],
        supervisors: config.supervisors ?? [],
        coordinators: config.coordinators ?? [],
        name: config.name ?? '',
        email: config.email ?? '',
        phone: config.phone ?? '',
        roleId: String(config.roleId ?? ''),
        primaryBranchId: String(config.primaryBranchId ?? ''),
        additionalBranchIds: (config.additionalBranchIds ?? []).map(String),
        primaryProjectId: String(config.primaryProjectId ?? ''),
        additionalProjectIds: (config.additionalProjectIds ?? []).map(String),
        supervisorId: String(config.supervisorId ?? ''),
        coordinatorId: String(config.coordinatorId ?? ''),
        provisioningMode: config.provisioningMode ?? 'invitation',
        additionalAccessOpen: false,
        branchSearch: '',
        projectSearch: '',
        errorSummary: '',

        init() {
            this.additionalBranchIds = this.additionalBranchIds.filter((id) => id !== this.primaryBranchId);
            this.pruneSelections();
        },

        get role() {
            return this.roles.find((role) => String(role.id) === String(this.roleId)) ?? null;
        },

        get roleSlug() {
            return this.role?.slug ?? '';
        },

        get roleDescription() {
            return roleDescriptions[this.roleSlug] ?? this.role?.description ?? 'Pilih peran untuk melihat penempatan yang sesuai.';
        },

        get requiresBranch() {
            return this.roleSlug !== '' && this.roleSlug !== 'superadmin';
        },

        get requiresProjects() {
            return this.roleSlug === 'sales';
        },

        get showReporting() {
            return ['supervisor', 'sales_coordinator', 'sas', 'sam'].includes(this.roleSlug);
        },

        get selectedBranch() {
            return this.branches.find((branch) => String(branch.id) === String(this.primaryBranchId)) ?? null;
        },

        get selectedCoordinator() {
            return this.coordinators.find((coordinator) => String(coordinator.id) === String(this.coordinatorId)) ?? null;
        },

        get filteredBranches() {
            const search = this.branchSearch.trim().toLowerCase();

            return this.branches.filter((branch) => String(branch.id) !== String(this.primaryBranchId)
                && (!search || `${branch.name} ${branch.code}`.toLowerCase().includes(search)));
        },

        get primaryBranchProjects() {
            return this.projects.filter((project) => !this.primaryBranchId || String(project.branch_id) === String(this.primaryBranchId));
        },

        get additionalAccessProjects() {
            const branchIds = [this.primaryBranchId, ...this.additionalBranchIds].filter(Boolean).map(String);

            return this.projects.filter((project) => branchIds.includes(String(project.branch_id)));
        },

        get filteredPrimaryProjects() {
            const search = this.projectSearch.trim().toLowerCase();

            return this.primaryBranchProjects.filter((project) => !search || `${project.project_name} ${project.branch?.name ?? ''}`.toLowerCase().includes(search));
        },

        get filteredAdditionalProjects() {
            const search = this.projectSearch.trim().toLowerCase();

            return this.additionalAccessProjects.filter((project) => !search || `${project.project_name} ${project.branch?.name ?? ''}`.toLowerCase().includes(search));
        },

        get visibleCoordinators() {
            return this.coordinators.filter((coordinator) => !this.primaryBranchId || String(coordinator.branch_id) === String(this.primaryBranchId));
        },

        get visibleSupervisors() {
            const allowedSlugs = ['branch_manager', 'supervisor', 'manager', 'pusat', 'superadmin'];

            return this.supervisors.filter((supervisor) => {
                const branchMatch = !this.primaryBranchId || String(supervisor.branch_id) === String(this.primaryBranchId);

                return branchMatch && allowedSlugs.includes(supervisor.role?.slug);
            });
        },

        get selectedAdditionalBranches() {
            return this.additionalBranchIds.map((id) => this.branches.find((branch) => String(branch.id) === String(id))).filter(Boolean);
        },

        get selectedAdditionalProjects() {
            return this.additionalProjectIds.map((id) => this.projects.find((project) => String(project.id) === String(id))).filter(Boolean);
        },

        get teamSales() {
            return this.selectedCoordinator?.current_coordinator_sales ?? [];
        },

        get visibleTeamSales() {
            return this.teamSales.slice(0, 6);
        },

        get remainingTeamSales() {
            return Math.max(0, this.teamSales.length - this.visibleTeamSales.length);
        },

        get reviewScope() {
            if (this.roleSlug === 'sales') {
                return 'Own';
            }

            if (['sas', 'sam', 'supervisor', 'branch_manager'].includes(this.roleSlug)) {
                return 'Branch';
            }

            return this.roleSlug === 'superadmin' ? 'All registered permissions' : 'Sesuai permission terdaftar';
        },

        setRole(value) {
            this.roleId = String(value ?? '');
            if (this.roleSlug !== 'sales') {
                this.coordinatorId = '';
            }
            if (!this.requiresProjects) {
                this.primaryProjectId = '';
                this.additionalProjectIds = [];
            }
            if (!this.showReporting || this.roleSlug === 'sales') {
                this.supervisorId = '';
            }
            if (!this.requiresBranch) {
                this.primaryBranchId = '';
                this.additionalBranchIds = [];
                this.primaryProjectId = '';
                this.additionalProjectIds = [];
                this.supervisorId = '';
            }
            this.pruneSelections();
        },

        setPrimaryBranch(value) {
            this.primaryBranchId = String(value ?? '');
            this.pruneSelections();
        },

        toggleAdditionalBranch(id) {
            const value = String(id);
            this.additionalBranchIds = this.additionalBranchIds.includes(value)
                ? this.additionalBranchIds.filter((branchId) => branchId !== value)
                : [...this.additionalBranchIds, value];
        },

        removeAdditionalBranch(id) {
            this.additionalBranchIds = this.additionalBranchIds.filter((branchId) => branchId !== String(id));
        },

        toggleAdditionalProject(id) {
            const value = String(id);
            this.additionalProjectIds = this.additionalProjectIds.includes(value)
                ? this.additionalProjectIds.filter((projectId) => projectId !== value)
                : [...this.additionalProjectIds, value];
        },

        removeAdditionalProject(id) {
            this.additionalProjectIds = this.additionalProjectIds.filter((projectId) => projectId !== String(id));
        },

        isAdditionalBranchSelected(id) {
            return this.additionalBranchIds.includes(String(id));
        },

        isAdditionalProjectSelected(id) {
            return this.additionalProjectIds.includes(String(id));
        },

        pruneSelections() {
            this.additionalBranchIds = this.additionalBranchIds.filter((id) => String(id) !== String(this.primaryBranchId));
            if (this.primaryProjectId && !this.primaryBranchProjects.some((project) => String(project.id) === String(this.primaryProjectId))) {
                this.primaryProjectId = '';
            }
            this.additionalProjectIds = this.additionalProjectIds.filter((id) => this.additionalAccessProjects.some((project) => String(project.id) === String(id)));
            if (this.coordinatorId && !this.visibleCoordinators.some((coordinator) => String(coordinator.id) === String(this.coordinatorId))) {
                this.coordinatorId = '';
            }
            if (this.supervisorId && !this.visibleSupervisors.some((supervisor) => String(supervisor.id) === String(this.supervisorId))) {
                this.supervisorId = '';
            }
        },

        validateStep(step) {
            this.errorSummary = '';

            if (step === 1) {
                if (!this.name.trim()) {
                    this.errorSummary = 'Nama belum diisi.';
                    return false;
                }
                if (!this.email.trim()) {
                    this.errorSummary = 'Email belum diisi.';
                    return false;
                }
                if (!this.roleId) {
                    this.errorSummary = 'Pilih peran sebelum melanjutkan.';
                    return false;
                }
            }

            if (step === 2) {
                if (this.requiresBranch && !this.primaryBranchId) {
                    this.errorSummary = 'Pilih cabang utama untuk penempatan organisasi.';
                    return false;
                }
                if (this.requiresProjects && this.additionalProjectIds.length === 0 && !this.primaryProjectId) {
                    this.errorSummary = 'Sales harus memiliki minimal satu proyek aktif.';
                    return false;
                }
                if (this.roleSlug === 'sales' && !this.coordinatorId) {
                    this.errorSummary = 'Pilih Koordinator Tim untuk user Sales.';
                    return false;
                }
            }

            return true;
        },

        goToStep(step) {
            if (step > this.step && !this.validateStep(this.step)) {
                return;
            }

            this.step = Math.max(1, Math.min(3, step));
        },

        next() {
            if (this.validateStep(this.step)) {
                this.step = Math.min(3, this.step + 1);
            }
        },

        back() {
            this.errorSummary = '';
            this.step = Math.max(1, this.step - 1);
        },
    }));
}

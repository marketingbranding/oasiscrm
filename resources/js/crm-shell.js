const COLLAPSED_KEY = 'oasis.sidebar.collapsed';
const GROUPS_KEY = 'oasis.sidebar.groups';

function storedGroups() {
    try {
        return JSON.parse(localStorage.getItem(GROUPS_KEY) || '{}');
    } catch {
        return {};
    }
}

export default function registerCrmShell(Alpine) {
    Alpine.data('crmShell', (config = {}) => {
        const activeGroups = Array.isArray(config.activeGroups) ? config.activeGroups : [];
        const expandedGroups = storedGroups();

        activeGroups.forEach((key) => {
            expandedGroups[key] = true;
        });

        return {
            sidebarOpen: false,
            sidebarCollapsed: localStorage.getItem(COLLAPSED_KEY) === 'true',
            expandedGroups,

            init() {
                this.syncSidebarClass();
            },

            toggleDesktopSidebar() {
                this.sidebarCollapsed = !this.sidebarCollapsed;
                localStorage.setItem(COLLAPSED_KEY, String(this.sidebarCollapsed));
                this.syncSidebarClass();
            },

            toggleGroup(key) {
                if (this.sidebarCollapsed) {
                    this.sidebarCollapsed = false;
                    localStorage.setItem(COLLAPSED_KEY, 'false');
                    this.syncSidebarClass();
                }

                this.expandedGroups[key] = !this.expandedGroups[key];
                localStorage.setItem(GROUPS_KEY, JSON.stringify(this.expandedGroups));
            },

            isGroupOpen(key) {
                return this.expandedGroups[key] === true;
            },

            syncSidebarClass() {
                document.documentElement.classList.toggle('oasis-sidebar-collapsed', this.sidebarCollapsed);
            },
        };
    });
}

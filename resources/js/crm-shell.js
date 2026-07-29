import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';

const COLLAPSED_KEY = 'oasis.sidebar.collapsed';
const GROUPS_KEY = 'oasis.sidebar.groups';

function readStorage(key, fallback = null) {
    try {
        return localStorage.getItem(key) ?? fallback;
    } catch {
        return fallback;
    }
}

function writeStorage(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Shell preferences remain optional when browser storage is unavailable.
    }
}

function storedGroups() {
    try {
        return JSON.parse(readStorage(GROUPS_KEY, '{}'));
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
            sidebarCollapsed: readStorage(COLLAPSED_KEY) === 'true',
            expandedGroups,
            mobileViewport: window.matchMedia('(max-width: 767px)').matches,
            navigationTrigger: null,
            bodyLockOwner: 'crm-shell-mobile-navigation',
            mediaQuery: null,
            mediaListener: null,

            init() {
                this.syncSidebarClass();
                this.mediaQuery = window.matchMedia('(max-width: 767px)');
                this.mediaListener = (event) => {
                    this.mobileViewport = event.matches;
                    if (!event.matches && this.sidebarOpen) {
                        this.closeMobileNavigation(false);
                        this.$nextTick(() => this.$refs.desktopSidebarToggle?.focus());
                    }
                };
                this.mediaQuery.addEventListener('change', this.mediaListener);
            },

            destroy() {
                unlockBodyScroll(this.bodyLockOwner);
                this.mediaQuery?.removeEventListener('change', this.mediaListener);
            },

            openMobileNavigation() {
                this.navigationTrigger = document.activeElement;
                this.sidebarOpen = true;
                lockBodyScroll(this.bodyLockOwner);
                this.$nextTick(() => this.$refs.drawerClose?.focus());
            },

            closeMobileNavigation(restoreFocus = true) {
                if (!this.sidebarOpen) {
                    return;
                }

                this.sidebarOpen = false;
                unlockBodyScroll(this.bodyLockOwner);

                if (restoreFocus) {
                    this.$nextTick(() => this.navigationTrigger?.focus());
                }
            },

            handleDrawerKeydown(event) {
                if (!this.mobileViewport || !this.sidebarOpen) {
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    this.closeMobileNavigation();
                    return;
                }

                if (event.key !== 'Tab') {
                    return;
                }

                const focusable = Array.from(this.$refs.drawer.querySelectorAll(
                    'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
                )).filter((element) => element.offsetParent !== null);

                if (focusable.length === 0) {
                    event.preventDefault();
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

            toggleDesktopSidebar() {
                this.sidebarCollapsed = !this.sidebarCollapsed;
                writeStorage(COLLAPSED_KEY, String(this.sidebarCollapsed));
                this.syncSidebarClass();
            },

            toggleGroup(key) {
                if (!this.mobileViewport && this.sidebarCollapsed) {
                    this.sidebarCollapsed = false;
                    writeStorage(COLLAPSED_KEY, 'false');
                    this.syncSidebarClass();
                    this.expandedGroups[key] = true;
                    writeStorage(GROUPS_KEY, JSON.stringify(this.expandedGroups));
                    return;
                }

                this.expandedGroups[key] = !this.expandedGroups[key];
                writeStorage(GROUPS_KEY, JSON.stringify(this.expandedGroups));
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

export default function registerPresence(Alpine) {
    Alpine.data('crmPresence', (config) => ({
        enabled: Boolean(config.enabled), heartbeatUrl: config.heartbeatUrl, indexUrl: config.indexUrl, destroyUrl: config.destroyUrl,
        intervalMs: Math.max(20, Number(config.heartbeatSeconds || 25)) * 1000,
        pageKey: config.pageKey, branchId: config.branchId || null, recordType: config.recordType || null,
        recordId: config.recordId || null, mode: config.mode || 'viewing', baseMode: config.mode || 'viewing', presences: [], timer: null,
        sessionKey: null, csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
        visibilityHandler: null, unloadHandler: null, savedHandler: null, hiddenTimer: null,

        init() {
            if (!this.enabled) return;
            this.sessionKey = sessionStorage.getItem('oasis_presence_session') || this.makeSessionKey();
            sessionStorage.setItem('oasis_presence_session', this.sessionKey);
            this.attachSessionToForm();
            this.visibilityHandler = () => this.handleVisibility();
            this.unloadHandler = () => this.cleanup(true);
            this.savedHandler = () => { if (this.baseMode === 'editing') { this.cleanup(false); this.stopPolling(); } };
            document.addEventListener('visibilitychange', this.visibilityHandler);
            window.addEventListener('pagehide', this.unloadHandler);
            window.addEventListener('oasis-presence-saved', this.savedHandler);
            this.startPolling();
        },
        destroy() {
            this.stopPolling(); this.clearHiddenTimer(); this.cleanup(true);
            if (this.visibilityHandler) document.removeEventListener('visibilitychange', this.visibilityHandler);
            if (this.unloadHandler) window.removeEventListener('pagehide', this.unloadHandler);
            if (this.savedHandler) window.removeEventListener('oasis-presence-saved', this.savedHandler);
        },
        makeSessionKey() {
            if (window.crypto?.randomUUID) return window.crypto.randomUUID().replaceAll('-', '');
            return `${Date.now()}_${Math.random().toString(36).slice(2)}`;
        },
        attachSessionToForm() {
            if (this.baseMode !== 'editing') return;
            const form = this.$root.closest('form') || document.querySelector('[data-conflict-form]');
            if (!form) return;
            let input = form.querySelector('input[name="presence_session_key"]');
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'presence_session_key'; form.appendChild(input); }
            input.value = this.sessionKey;
        },
        payload(includeSession = true) {
            return {
                page_key: this.pageKey, branch_id: this.branchId || null, record_type: this.recordType || null,
                record_id: this.recordId || null, mode: this.mode,
                ...(includeSession ? { session_key: this.sessionKey } : {}),
            };
        },
        startPolling() {
            this.stopPolling();
            if (!this.enabled || document.hidden) return;
            this.pulse();
            this.timer = window.setInterval(() => this.pulse(), this.intervalMs);
        },
        stopPolling() { if (this.timer) window.clearInterval(this.timer); this.timer = null; },
        clearHiddenTimer() { if (this.hiddenTimer) window.clearTimeout(this.hiddenTimer); this.hiddenTimer = null; },
        handleVisibility() {
            this.clearHiddenTimer();
            if (document.hidden) {
                this.stopPolling();
                if (this.baseMode === 'editing') {
                    this.hiddenTimer = window.setTimeout(() => { this.mode = 'idle'; this.heartbeat(); }, 60000);
                }
                return;
            }
            this.mode = this.baseMode;
            this.startPolling();
        },
        async pulse() { await this.heartbeat(); await this.refresh(); },
        async heartbeat() {
            try {
                await fetch(this.heartbeatUrl, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify(this.payload(true)),
                });
            } catch (_) { /* Presence is advisory. */ }
        },
        async refresh() {
            try {
                const query = new URLSearchParams(Object.entries(this.payload(false)).filter(([, value]) => value !== null && value !== ''));
                const response = await fetch(`${this.indexUrl}?${query}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const data = await response.json();
                this.presences = data.presences || [];
            } catch (_) { /* Keep the previous advisory list. */ }
        },
        cleanup(keepalive = false) {
            if (!this.enabled || !this.sessionKey) return;
            try {
                fetch(this.destroyUrl, {
                    method: 'DELETE', keepalive,
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify(this.payload(true)),
                });
            } catch (_) {}
        },
        async updateContext(context) {
            this.cleanup(false);
            this.branchId = context.branchId || null; this.recordType = context.recordType || null;
            this.recordId = context.recordId || null; this.baseMode = context.mode || 'viewing'; this.mode = this.baseMode; this.presences = [];
            this.attachSessionToForm();
            this.startPolling();
        },
        get others() {
            const seen = new Set();
            return this.presences.filter((presence) => {
                if (presence.is_current_user || (this.baseMode === 'editing' && presence.mode !== 'editing') || seen.has(presence.user_id)) return false;
                seen.add(presence.user_id); return true;
            });
        },
        get fullNames() { return this.others.map((presence) => presence.display_name).join(', '); },
        get summary() {
            const names = this.others.map((presence) => presence.display_name);
            if (!names.length) return '';
            const shown = names.slice(0, 3);
            const subject = shown.length === 1 ? shown[0]
                : shown.length === 2 ? `${shown[0]} dan ${shown[1]}`
                : `${shown.slice(0, -1).join(', ')}, dan ${shown.at(-1)}`;
            const remainder = names.length - shown.length;
            const people = remainder > 0 ? `${shown.join(', ')}, dan ${remainder} pengguna lain` : subject;
            const action = this.recordType ? (this.baseMode === 'editing' ? 'sedang mengedit data ini' : 'sedang melihat data ini') : 'sedang membuka halaman ini';
            return `${people} ${action}.`;
        },
    }));
}

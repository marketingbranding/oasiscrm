export default function registerPresence(Alpine) {
    Alpine.data('crmPresence', (config) => ({
        enabled: Boolean(config.enabled), heartbeatUrl: config.heartbeatUrl, indexUrl: config.indexUrl, destroyUrl: config.destroyUrl,
        intervalMs: Math.max(20, Number(config.heartbeatSeconds || 25)) * 1000,
        pageKey: config.pageKey, branchId: config.branchId || null, recordType: config.recordType || null,
        recordId: config.recordId || null, mode: config.mode || 'viewing', presences: [], timer: null,
        sessionKey: null, csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
        visibilityHandler: null, unloadHandler: null,

        init() {
            if (!this.enabled) return;
            this.sessionKey = sessionStorage.getItem('oasis_presence_session') || this.makeSessionKey();
            sessionStorage.setItem('oasis_presence_session', this.sessionKey);
            this.visibilityHandler = () => document.hidden ? this.stopPolling() : this.startPolling();
            this.unloadHandler = () => this.cleanup(true);
            document.addEventListener('visibilitychange', this.visibilityHandler);
            window.addEventListener('pagehide', this.unloadHandler);
            this.startPolling();
        },
        destroy() {
            this.stopPolling(); this.cleanup(true);
            if (this.visibilityHandler) document.removeEventListener('visibilitychange', this.visibilityHandler);
            if (this.unloadHandler) window.removeEventListener('pagehide', this.unloadHandler);
        },
        makeSessionKey() {
            if (window.crypto?.randomUUID) return window.crypto.randomUUID().replaceAll('-', '');
            return `${Date.now()}_${Math.random().toString(36).slice(2)}`;
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
            this.recordId = context.recordId || null; this.mode = context.mode || 'viewing'; this.presences = [];
            this.startPolling();
        },
        get others() { return this.presences.filter((presence) => !presence.is_current_user && (this.mode !== 'editing' || presence.mode === 'editing')); },
        get fullNames() { return this.others.map((presence) => presence.display_name).join(', '); },
        get summary() {
            const names = this.others.map((presence) => presence.display_name);
            if (!names.length) return '';
            return `${names.slice(0, 5).join(', ')}${names.length > 5 ? `, dan ${names.length - 5} lainnya` : ''}`;
        },
    }));
}

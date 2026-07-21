export default function registerNotifications(Alpine) {
    Alpine.data('crmNotifications', (config) => ({
        open: false,
        notifications: [],
        unreadCount: 0,
        loading: false,
        timer: null,
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

        init() {
            this.refresh();
            document.addEventListener('visibilitychange', () => document.hidden ? this.stop() : this.start());
            this.start();
        },

        start() {
            this.stop();
            if (!document.hidden) this.timer = window.setInterval(() => this.refresh(), 60000);
        },

        stop() {
            if (this.timer) window.clearInterval(this.timer);
            this.timer = null;
        },

        async refresh() {
            this.loading = true;
            try {
                const response = await fetch(config.indexUrl, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const data = await response.json();
                this.notifications = data.notifications || [];
                this.unreadCount = Number(data.unread_count || 0);
            } catch (_) { /* Notifications must not interrupt CRM use. */ }
            finally { this.loading = false; }
        },

        async markRead(notification, follow = false) {
            try {
                if (!notification.read_at) {
                    const response = await fetch(config.readUrl.replace('__ID__', notification.id), {
                        method: 'PATCH',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    });
                    if (!response.ok) throw new Error('Notification read failed');
                    notification.read_at = new Date().toISOString();
                    this.unreadCount = Math.max(0, this.unreadCount - 1);
                }
            } catch (_) { /* The authorized destination still rechecks access. */ }
            if (follow && notification.action_url) window.location.href = notification.action_url;
        },

        async markAllRead() {
            try {
                const response = await fetch(config.readAllUrl, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                });
                if (!response.ok) return;
                this.notifications.forEach((notification) => { notification.read_at ||= new Date().toISOString(); });
                this.unreadCount = 0;
            } catch (_) {}
        },
    }));
}

export default function registerToasts(Alpine) {
    Alpine.data('crmToasts', (initial = []) => ({
        toasts: [],
        nextId: 0,

        init() {
            initial.forEach(toast => this.push(toast));
        },

        push(detail = {}) {
            const message = String(detail.message || '').trim();
            if (!message) return;

            const type = ['success', 'error', 'warning'].includes(detail.type) ? detail.type : 'success';
            if (this.toasts.some(toast => toast.show && toast.type === type && toast.message === message)) return;
            const id = ++this.nextId;
            this.toasts.push({ id, type, message, show: true });
            window.setTimeout(() => this.dismiss(id), Number(detail.duration) || 4000);
        },

        dismiss(id) {
            const toast = this.toasts.find(item => item.id === id);
            if (!toast || !toast.show) return;
            toast.show = false;
            window.setTimeout(() => {
                this.toasts = this.toasts.filter(item => item.id !== id);
            }, 500);
        },
    }));

    window.oasisToast = (message, type = 'success', duration = 4000) => {
        window.dispatchEvent(new CustomEvent('oasis:toast', { detail: { message, type, duration } }));
    };
}

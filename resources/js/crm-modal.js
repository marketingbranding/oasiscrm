import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';

export default function registerCrmModal(Alpine) {
    Alpine.data('crmModal', (name, initiallyOpen = false) => ({
        name,
        open: false,
        trigger: null,
        lockOwner: `crm-modal:${name}`,

        init() {
            if (initiallyOpen) {
                this.show();
            }
        },

        destroy() {
            if (this.open) {
                unlockBodyScroll(this.lockOwner);
            }
        },

        show(event = null) {
            if (event?.detail?.name && event.detail.name !== this.name) {
                return;
            }

            if (this.open) {
                return;
            }

            this.trigger = event?.detail?.trigger || document.activeElement;
            this.open = true;
            lockBodyScroll(this.lockOwner);
            this.$nextTick(() => {
                const initial = this.$refs.panel.querySelector('[data-autofocus]') || this.$refs.close || this.$refs.panel;
                initial.focus();
                window.dispatchEvent(new CustomEvent('oasis:modal-opened', { detail: { name: this.name } }));
            });
        },

        hide(restoreFocus = true, reason = 'programmatic') {
            if (!this.open) {
                return;
            }

            this.open = false;
            unlockBodyScroll(this.lockOwner);
            if (restoreFocus) {
                this.$nextTick(() => this.trigger?.focus());
            }
            window.dispatchEvent(new CustomEvent('oasis:modal-closed', { detail: { name: this.name, reason } }));
        },

        closeFromEvent(event) {
            if (!event.detail?.name || event.detail.name === this.name) {
                this.hide(true, event.detail?.reason || 'event');
            }
        },

        hasOpenPicker() {
            return Array.from(this.$refs.panel.querySelectorAll('.date-calendar, .month-picker-popup, .time-picker-popup'))
                .some((picker) => picker.style.display !== 'none');
        },

        handleKeydown(event) {
            if (event.key === 'Escape') {
                if (this.hasOpenPicker()) {
                    return;
                }
                event.preventDefault();
                this.hide(true, 'escape');
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusable = Array.from(this.$refs.panel.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )).filter((element) => element.offsetParent !== null);

            if (focusable.length === 0) {
                event.preventDefault();
                this.$refs.panel.focus();
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
    }));
}

export default function registerCrmModal(Alpine) {
    Alpine.data('crmModal', (name, initiallyOpen = false) => ({
        name,
        open: false,
        trigger: null,
        previousBodyOverflow: '',

        init() {
            if (initiallyOpen) {
                this.show();
            }
        },

        destroy() {
            if (this.open) {
                this.unlockBody();
            }
        },

        show(event = null) {
            if (event?.detail?.name && event.detail.name !== this.name) {
                return;
            }

            this.trigger = event?.detail?.trigger || document.activeElement;
            this.open = true;
            this.previousBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            this.$nextTick(() => {
                const initial = this.$refs.panel.querySelector('[data-autofocus]') || this.$refs.close || this.$refs.panel;
                initial.focus();
            });
        },

        hide(restoreFocus = true) {
            if (!this.open) {
                return;
            }

            this.open = false;
            this.unlockBody();
            if (restoreFocus) {
                this.$nextTick(() => this.trigger?.focus());
            }
        },

        closeFromEvent(event) {
            if (!event.detail?.name || event.detail.name === this.name) {
                this.hide();
            }
        },

        handleKeydown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.hide();
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

        unlockBody() {
            document.body.style.overflow = this.previousBodyOverflow;
        },
    }));
}

import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';

export default function registerSalesPocketbookOnboarding(Alpine) {
    Alpine.data('salesPocketbookTour', (steps, initiallyOpen = false) => ({
        steps,
        step: 1,
        open: initiallyOpen,
        targetFound: false,
        previousFocus: null,
        bodyLockOwner: 'sales-pocketbook-tour',

        init() {
            if (this.open) {
                this.start();
            }
        },

        destroy() {
            this.close();
        },

        get current() {
            return this.steps[this.step - 1] || this.steps[0] || {};
        },

        start() {
            this.previousFocus = document.activeElement;
            lockBodyScroll(this.bodyLockOwner);
            this.$nextTick(() => this.focusCard());
            this.highlightTarget();
        },

        close() {
            document.querySelectorAll('[data-tour-active]').forEach((element) => element.removeAttribute('data-tour-active'));
            unlockBodyScroll(this.bodyLockOwner);
            this.previousFocus?.focus?.({ preventScroll: true });
        },

        next() {
            if (this.step >= this.steps.length) {
                return;
            }

            this.step += 1;
            this.$nextTick(() => {
                this.highlightTarget();
                this.focusCard();
            });
        },

        previous() {
            if (this.step <= 1) {
                return;
            }

            this.step -= 1;
            this.$nextTick(() => {
                this.highlightTarget();
                this.focusCard();
            });
        },

        skip() {
            this.open = false;
            this.$refs.skipForm?.submit();
        },

        complete() {
            this.open = false;
            this.$refs.completeForm?.submit();
        },

        highlightTarget() {
            document.querySelectorAll('[data-tour-active]').forEach((element) => element.removeAttribute('data-tour-active'));
            const target = this.current.target ? document.querySelector(this.current.target) : null;
            this.targetFound = Boolean(target);

            if (!target) {
                return;
            }

            target.setAttribute('data-tour-active', 'true');
            target.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center', inline: 'nearest' });
        },

        focusCard() {
            this.$refs.card?.querySelector('button')?.focus({ preventScroll: true });
        },

        trapFocus(event) {
            const focusable = [...this.$refs.card.querySelectorAll('button:not([disabled])')];
            if (focusable.length === 0) {
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

        handleKeydown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.skip();
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                this.next();
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault();
                this.previous();
            }
        },
    }));
}

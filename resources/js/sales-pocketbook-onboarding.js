import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';

export default function registerSalesPocketbookOnboarding(Alpine) {
    Alpine.data('salesPocketbookOnboarding', totalSteps => ({
        step: 1,
        totalSteps,
        bodyLockOwner: 'sales-pocketbook-onboarding',

        init() {
            lockBodyScroll(this.bodyLockOwner);
            this.$nextTick(() => this.focusStep());
        },

        destroy() {
            unlockBodyScroll(this.bodyLockOwner);
        },

        next() {
            if (this.step < this.totalSteps) {
                this.step += 1;
                this.$nextTick(() => this.focusStep());
            }
        },

        previous() {
            if (this.step > 1) {
                this.step -= 1;
                this.$nextTick(() => this.focusStep());
            }
        },

        focusStep() {
            const activeStep = this.$refs.step?.querySelector(`[data-onboarding-step="${this.step}"]`);

            activeStep?.querySelector('h1, button, a')?.focus({ preventScroll: true });
        },

        handleKeydown(event) {
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                this.next();
            }
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                this.previous();
            }
        },
    }));
}

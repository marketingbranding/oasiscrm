import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';

export default function registerSalesPocketbookTour(Alpine) {
    Alpine.data('salesPocketbookTour', (totalSteps) => ({
        step: 1,
        totalSteps,
        previousFocus: null,
        lockOwner: 'sales-pocketbook-tour',

        start() {
            this.previousFocus = document.activeElement;
            lockBodyScroll(this.lockOwner);
            this.$nextTick(() => this.position());
            window.addEventListener('resize', this.position);
            window.addEventListener('scroll', this.position, true);
        },

        destroy() {
            window.removeEventListener('resize', this.position);
            window.removeEventListener('scroll', this.position, true);
            unlockBodyScroll(this.lockOwner);
            this.previousFocus?.focus?.({ preventScroll: true });
        },

        next() {
            if (this.step < this.totalSteps) {
                this.step += 1;
                this.$nextTick(() => this.position());
            }
        },

        handleKeydown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.$el.querySelector('.sales-tour-skip')?.click();
            }
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                this.next();
            }
        },

        position() {
            const activeStep = this.$el.querySelector(`[data-tour-step="${this.step}"]`);
            const targetName = [null, null, 'work-summary', 'add-plan-action', 'agenda-row', 'lead-tab'][this.step];
            const target = targetName ? document.querySelector(`[data-tour-target="${targetName}"]`) : null;
            const spotlight = this.$el.querySelector('[data-tour-spotlight]');
            const card = this.$el.querySelector('[data-tour-card]');

            if (!activeStep || !spotlight || !card) {
                return;
            }

            if (!target) {
                spotlight.hidden = true;
                card.classList.add('is-centered');
                card.style.removeProperty('top');
                card.style.removeProperty('left');
                return;
            }

            spotlight.hidden = false;
            card.classList.remove('is-centered');
            const rect = target.getBoundingClientRect();
            spotlight.style.top = `${Math.max(8, rect.top - 4)}px`;
            spotlight.style.left = `${Math.max(8, rect.left - 4)}px`;
            spotlight.style.width = `${rect.width + 8}px`;
            spotlight.style.height = `${rect.height + 8}px`;
            const cardHeight = card.offsetHeight || 190;
            const top = rect.top > cardHeight + 24 ? rect.top - cardHeight - 16 : rect.bottom + 16;
            card.style.top = `${Math.min(Math.max(12, top), window.innerHeight - cardHeight - 12)}px`;
            card.style.left = `${Math.min(Math.max(12, rect.left), window.innerWidth - Math.min(card.offsetWidth || 350, window.innerWidth - 24) - 12)}px`;
        },
    }));
}

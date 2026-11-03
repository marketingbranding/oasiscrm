import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';

export default function registerSalesAgendaWorkspace(Alpine) {
    Alpine.data('salesAgendaWorkspace', () => ({
        agendaOpen: false,
        trigger: null,
        lockOwner: 'sales-agenda-create-sheet',

        openAgendaSheet(event = null) {
            this.trigger = event?.currentTarget || document.activeElement;
            this.agendaOpen = true;
            lockBodyScroll(this.lockOwner);
            this.$nextTick(() => {
                this.$refs.agendaPanel?.querySelector('[data-autofocus]')?.focus();
                this.$refs.sheetClose?.focus();
            });
        },

        closeSheet(restoreFocus = true) {
            if (!this.agendaOpen) {
                return;
            }

            this.agendaOpen = false;
            unlockBodyScroll(this.lockOwner);
            if (restoreFocus) {
                this.$nextTick(() => this.trigger?.focus());
            }
        },

        handleSheetKeydown(event) {
            if (!this.agendaOpen) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeSheet();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }

            const focusable = [...this.$refs.agendaPanel.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )].filter(element => element.offsetParent !== null);
            if (!focusable.length) {
                event.preventDefault();
                this.$refs.agendaPanel.focus();
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

        syncDuration(duration) {
            const start = this.$refs.agendaPanel?.querySelector('[name="start_time"]')?.value;
            const endInput = this.$refs.agendaPanel?.querySelector('[name="end_time"]');
            const preview = this.$refs.agendaPanel?.querySelector('[data-end-preview]');
            if (!start || !endInput || !duration) {
                if (preview) preview.textContent = 'Waktu selesai akan dihitung dari jam mulai.';
                return;
            }

            const [hours, minutes] = start.split(':').map(Number);
            const end = hours * 60 + minutes + Number(duration);
            if (end >= 24 * 60) {
                if (preview) preview.textContent = 'Durasi harus selesai pada hari yang sama.';
                return;
            }

            const endValue = `${String(Math.floor(end / 60)).padStart(2, '0')}:${String(end % 60).padStart(2, '0')}`;
            endInput.value = endValue;
            endInput.dispatchEvent(new Event('input', { bubbles: true }));
            endInput.dispatchEvent(new Event('change', { bubbles: true }));
            if (preview) preview.textContent = `Selesai sekitar ${endValue}`;
        },
    }));
}

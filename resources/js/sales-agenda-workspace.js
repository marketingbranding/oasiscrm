import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock.js';
import { calculateAgendaSlot, resolveAgendaDuration } from './sales-agenda-slot.js';

export default function registerSalesAgendaWorkspace(Alpine) {
    Alpine.data('salesAgendaWorkspace', () => ({
        agendaOpen: false,
        receiptOpen: false,
        receiptTrigger: null,
        agendaTrigger: null,
        editingAgenda: null,
        deleteAgendaUrl: '',
        deleteAgendaToken: '',
        deleteAgendaTitle: '',
        deletePending: false,
        receiptLockOwner: 'sales-agenda-receipt-preview',
        agendaLockOwner: 'sales-agenda-create-sheet',

        init() {
            this.handleChipSelection = (event) => {
                const category = event.target.closest('[data-category-option]');
                const duration = event.target.closest('[data-duration-option]');
                const panel = this.$refs.agendaPanel;

                if (!panel || (!category && !duration)) {
                    return;
                }

                if (category) {
                    const select = panel.querySelector('[name="sales_activity_category"]');
                    if (select) {
                        select.value = category.dataset.categoryOption;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }

                 if (duration) {
                     const select = panel.querySelector('[name="duration_minutes"]');
                        const customInput = panel.querySelector('[name="custom_duration_minutes"]');
                        const durationValue = duration.dataset.durationOption;
                     if (select) {
                        if (durationValue === 'custom') {
                            select.value = '';
                            customInput?.focus();
                        } else {
                            if (customInput) customInput.value = '';
                            select.value = durationValue;
                        }
                         select.dispatchEvent(new Event('change', { bubbles: true }));
                     }
                }

                this.syncChipState();
            };
            this.$el.addEventListener('click', this.handleChipSelection);

            if (this.$el.dataset.resumeReceipt === 'true') {
                this.$nextTick(() => this.openAgendaPreview(null, true));
            }
        },

        destroy() {
            this.$el.removeEventListener('click', this.handleChipSelection);
            unlockBodyScroll(this.receiptLockOwner);
            unlockBodyScroll(this.agendaLockOwner);
        },

        openAgendaSheet(event = null, editingAgenda = null) {
            this.agendaTrigger = event?.currentTarget || document.activeElement;
            this.editingAgenda = editingAgenda;
            const resumeReceiptAfterSave = !editingAgenda && Boolean(event?.currentTarget?.closest?.('[data-receipt-preview-add]'));
            this.agendaOpen = true;
            lockBodyScroll(this.agendaLockOwner);
            this.$nextTick(() => {
                if (this.editingAgenda) {
                    this.populateAgendaForm();
                } else {
                    this.resetAgendaForm();
                }
                const receiptContext = this.$refs.agendaPanel?.querySelector('[data-receipt-context]');
                if (receiptContext) {
                    receiptContext.value = resumeReceiptAfterSave ? '1' : '0';
                }
                this.$refs.sheetClose?.focus();
                this.initializeSlot();
                this.syncChipState();
            });
        },

        handleAgendaFormKeydown(event) {
            if (event.key !== 'Enter' || event.isComposing || event.target?.tagName === 'TEXTAREA') {
                return;
            }

            const form = event.target?.form;
            if (!form || event.target.matches('button, select, textarea, [type="submit"]')) {
                return;
            }

            const focusable = [...form.querySelectorAll(
                'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])',
            )].filter((element) => element.offsetParent !== null && element.type !== 'submit');
            const currentIndex = focusable.indexOf(event.target);
            const next = focusable[currentIndex + 1];
            if (!next) {
                return;
            }

            event.preventDefault();
            next.focus();
        },

        openAgendaEdit(event) {
            const button = event.currentTarget;

            this.openAgendaSheet(event, {
                id: button.dataset.agendaId,
                updateUrl: button.dataset.agendaUpdateUrl,
                token: button.dataset.agendaToken,
                title: button.dataset.agendaTitle || '',
                category: button.dataset.agendaCategory || '',
                start: button.dataset.agendaStart || '',
                end: button.dataset.agendaEnd || '',
                location: button.dataset.agendaLocation || '',
            });
        },

        requestAgendaDelete(event) {
            const button = event.currentTarget;

            this.deleteAgendaUrl = button.dataset.agendaDeleteUrl || '';
            this.deleteAgendaToken = button.dataset.agendaToken || '';
            this.deleteAgendaTitle = button.dataset.agendaTitle || 'agenda ini';
            this.deletePending = false;
            this.$dispatch('oasis:modal-open', {
                name: 'sales-agenda-delete-confirm',
                trigger: button,
            });
        },

        openAgendaPreview(event = null, forcePreview = false) {
            if (!forcePreview && !window.matchMedia('(max-width: 767px)').matches) {
                this.openAgendaSheet(event);
                return;
            }

            this.receiptTrigger = event?.currentTarget || document.activeElement;
            this.receiptOpen = true;
            lockBodyScroll(this.receiptLockOwner);
            this.$nextTick(() => this.$refs.receiptClose?.focus());
        },

        closeReceipt(restoreFocus = true) {
            if (!this.receiptOpen) {
                return;
            }

            this.receiptOpen = false;
            unlockBodyScroll(this.receiptLockOwner);
            if (restoreFocus) {
                this.$nextTick(() => this.receiptTrigger?.focus());
            }
        },

        closeSheet(restoreFocus = true) {
            if (!this.agendaOpen) {
                return;
            }

            this.agendaOpen = false;
            unlockBodyScroll(this.agendaLockOwner);
            this.resetAgendaForm();
            this.editingAgenda = null;
            if (restoreFocus) {
                this.$nextTick(() => this.agendaTrigger?.focus());
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

        handleReceiptKeydown(event) {
            if (!this.receiptOpen) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeReceipt();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }

            const focusable = [...this.$refs.receiptPanel?.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ) || []].filter((element) => element.offsetParent !== null);
            if (!focusable.length) {
                event.preventDefault();
                this.$refs.receiptClose?.focus();
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
            this.syncSlot(duration);
        },

        initializeSlot() {
            const panel = this.$refs.agendaPanel;
            const duration = panel?.querySelector('[name="duration_minutes"]');
            if (!panel || !duration) {
                return;
            }

            if (!duration.value) {
                duration.value = '30';
            }

            const start = panel.querySelector('[name="start_time"]');
            if (!start?.value && !this.isWeekend(panel)) {
                this.syncSlot();
            } else {
                this.syncChipState();
            }
        },

        syncCustomDuration() {
            const panel = this.$refs.agendaPanel;
            const customInput = panel?.querySelector('[name="custom_duration_minutes"]');
            const duration = panel?.querySelector('[name="duration_minutes"]');
            if (!customInput || !duration) {
                return;
            }

            this.syncSlot(customInput.value);
        },

        syncSlot(durationOverride = null) {
            const panel = this.$refs.agendaPanel;
            const form = panel?.querySelector('[data-agenda-slot-form]') || panel?.querySelector('form');
            const startInput = panel?.querySelector('[name="start_time"]');
            const endInput = panel?.querySelector('[name="end_time"]');
            const durationInput = panel?.querySelector('[name="duration_minutes"]');
            const customInput = panel?.querySelector('[name="custom_duration_minutes"]');
            const preview = panel?.querySelector('[data-end-preview]');
            const error = panel?.querySelector('[data-slot-error]');
            if (!form || !startInput || !endInput || !durationInput) {
                return;
            }

            const durationValue = resolveAgendaDuration(
                durationInput.value,
                durationOverride !== null ? durationOverride : customInput?.value,
            );

            const result = calculateAgendaSlot({
                date: form.dataset.agendaDate,
                agendas: this.parseAgendas(form.dataset.agendas, this.editingAgenda?.id),
                requestedStart: startInput.value || null,
                durationMinutes: durationValue,
                allowOutsideSchedule: form.dataset.agendaSubmitted === 'true',
            });

            if (!result.ok) {
                if (error) error.textContent = result.message;
                if (preview) preview.textContent = result.message;
                endInput.value = '';
                this.syncChipState();
                return result;
            }

            startInput.value = result.startTime;
            endInput.value = result.endTime;
            if (error) error.textContent = '';
            if (preview) {
                preview.textContent = result.remainingMinutes === null
                    ? `Selesai ${result.endTime}`
                    : `Selesai ${result.endTime} · sisa ${result.remainingMinutes} menit`;
            }
            this.syncChipState();

            return result;
        },

        parseAgendas(value, excludedId = null) {
            try {
                const agendas = JSON.parse(value || '[]');

                return excludedId === null
                    ? agendas
                    : agendas.filter((agenda) => String(agenda.id) !== String(excludedId));
            } catch (_) {
                return [];
            }
        },

        populateAgendaForm() {
            const panel = this.$refs.agendaPanel;
            const form = panel?.querySelector('[data-agenda-slot-form]');
            const agenda = this.editingAgenda;
            if (!form || !agenda) {
                return;
            }

            form.action = agenda.updateUrl;
            form.querySelector('[data-agenda-method]').value = 'PATCH';
            form.querySelector('[data-agenda-expected-updated-at]').value = agenda.token;
            form.querySelector('[name="sales_activity_category"]').value = agenda.category;
            form.querySelector('[name="title"]').value = agenda.title;
            form.querySelector('[name="start_time"]').value = agenda.start;
            form.querySelector('[name="end_time"]').value = agenda.end;
            form.querySelector('[name="location"]').value = agenda.location;
            form.querySelector('[name="activity_result"]').value = '';

            const durationInput = form.querySelector('[name="duration_minutes"]');
            const customInput = form.querySelector('[name="custom_duration_minutes"]');
            const startMinutes = this.timeToMinutes(agenda.start);
            const endMinutes = this.timeToMinutes(agenda.end);
            const duration = startMinutes !== null && endMinutes !== null ? endMinutes - startMinutes : 0;
            const preset = ['30', '60', '90', '120'].includes(String(duration)) ? String(duration) : '';
            durationInput.value = preset;
            customInput.value = preset ? '' : String(duration || '');
            this.$refs.agendaFormTitle.textContent = 'Edit agenda';
            this.$refs.agendaFormSubmit.textContent = 'Simpan perubahan';
        },

        resetAgendaForm() {
            const form = this.$refs.agendaPanel?.querySelector('[data-agenda-slot-form]');
            if (!form) {
                return;
            }

            form.reset();
            form.action = form.dataset.createAction;
            form.querySelector('[data-agenda-method]').value = '';
            form.querySelector('[data-agenda-expected-updated-at]').value = '';
            const receiptContext = form.querySelector('[data-receipt-context]');
            if (receiptContext) receiptContext.value = '0';
             this.$refs.agendaFormTitle.textContent = 'Tambah Rencana';
             this.$refs.agendaFormSubmit.textContent = form.dataset.createLabel || 'Tambahkan Rencana';
        },

        timeToMinutes(value) {
            if (!value || !/^\d{2}:\d{2}$/.test(String(value).slice(0, 5))) {
                return null;
            }

            const [hours, minutes] = String(value).slice(0, 5).split(':').map(Number);

            return (hours * 60) + minutes;
        },

        isWeekend(panel) {
            const form = panel?.querySelector('[data-agenda-slot-form]') || panel?.querySelector('form');
            if (!form) {
                return false;
            }

            const date = new Date(`${form.dataset.agendaDate}T12:00:00`);
            return date.getDay() === 0 || date.getDay() === 6;
        },

        syncChipState() {
            const panel = this.$refs.agendaPanel;
            if (!panel) {
                return;
            }

            const category = panel.querySelector('[name="sales_activity_category"]')?.value;
            const presetDuration = panel.querySelector('[name="duration_minutes"]')?.value;
            const customDuration = panel.querySelector('[name="custom_duration_minutes"]')?.value;
            const duration = resolveAgendaDuration(presetDuration, customDuration);
            const isPreset = ['30', '60', '90', '120'].includes(duration);
            panel.querySelectorAll('[data-category-option]').forEach((button) => button.classList.toggle('is-active', button.dataset.categoryOption === category));
            panel.querySelectorAll('[data-duration-option]').forEach((button) => button.classList.toggle('is-active', button.dataset.durationOption === duration || (button.dataset.durationOption === 'custom' && !isPreset && Boolean(duration))));
        },
    }));
}

export default function registerSalesDailyPlan(Alpine) {
    Alpine.data('salesDailyPlan', (initialActivities = []) => ({
        activities: initialActivities.length ? initialActivities : [],
        draft: { sales_activity_category: '', activity: '', location: '', detail: '', duration_minutes: '' },
        modalError: '',
        submitting: false,

        init() {
            this.$watch('draft.duration_minutes', () => {
                this.modalError = '';
            });
        },

        total() {
            return this.activities.reduce((total, activity) => total + (Number(activity.duration_minutes) || 0), 0);
        },

        add() {
            this.activities.push({ sales_activity_category: '', activity: '', location: '', detail: '', duration_minutes: '' });
        },

        remove(index) {
            this.activities.splice(index, 1);
        },

        move(index, direction) {
            const target = index + direction;
            if (target < 0 || target >= this.activities.length) return;
            [this.activities[index], this.activities[target]] = [this.activities[target], this.activities[index]];
        },

        schedule(index, list = null) {
            const source = list || this.activities;
            let cursor = 540;
            const scheduled = source.slice(0, index + 1).map((activity) => {
                if (cursor >= 720 && cursor < 780) cursor = 780;
                const start = cursor;
                let remaining = Number(activity.duration_minutes) || 0;
                while (remaining > 0) {
                    if (cursor >= 1020) {
                        break;
                    }
                    if (cursor >= 720 && cursor < 780) cursor = 780;
                    const blockEnd = cursor < 720 ? 720 : 1020;
                    const consumed = Math.min(remaining, blockEnd - cursor);
                    cursor += consumed;
                    remaining -= consumed;
                }

                return { start: this.format(start), end: this.format(cursor) };
            });

            return scheduled[scheduled.length - 1] || { start: '09:00', end: '09:00' };
        },

        format(minutes) {
            return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
        },

        openAddModal() {
            this.draft = { sales_activity_category: '', activity: '', location: '', detail: '', duration_minutes: '' };
            this.modalError = '';
            window.dispatchEvent(new CustomEvent('oasis:modal-open', { detail: { name: 'tambah-agenda' } }));
        },

        closeAddModal() {
            window.dispatchEvent(new CustomEvent('oasis:modal-close', { detail: { name: 'tambah-agenda' } }));
        },

        submitPlan(event) {
            if (this.submitting) return;

            const form = event.currentTarget;
            const submit = () => HTMLFormElement.prototype.submit.call(form);
            const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

            this.submitting = true;
            if (reducedMotion) {
                submit();
                return;
            }

            const paper = form.closest('.sales-agenda-paper');
            if (!paper) {
                submit();
                return;
            }

            paper.classList.add('is-tearing');
            let finished = false;
            const finish = () => {
                if (finished) return;
                finished = true;
                submit();
            };

            paper.addEventListener('animationend', (animationEvent) => {
                if (animationEvent.animationName === 'sales-paper-tear') finish();
            }, { once: true });
            window.setTimeout(finish, 760);
        },

        draftSchedule() {
            const duration = Number(this.draft.duration_minutes) || 0;
            if (!duration) return { start: '09:00', end: '09:00' };

            return this.schedule(this.activities.length, [...this.activities, { duration_minutes: duration }]);
        },

        addFromModal() {
            const duration = Number(this.draft.duration_minutes) || 0;
            const category = (this.draft.sales_activity_category || '').trim();
            const activity = (this.draft.activity || '').trim();
            if (!category) {
                this.modalError = 'Kategori aktivitas wajib dipilih.';
                return;
            }
            if (!activity) {
                this.modalError = 'Judul agenda wajib diisi.';
                return;
            }
            if (duration < 1 || duration > 420) {
                this.modalError = 'Durasi harus antara 1 sampai 420 menit.';
                return;
            }
            if (this.total() + duration > 420) {
                this.modalError = 'Total durasi tidak boleh melebihi 420 menit.';
                return;
            }

            this.activities.push({
                sales_activity_category: category,
                activity,
                location: (this.draft.location || '').trim(),
                detail: (this.draft.detail || '').trim(),
                duration_minutes: duration,
            });
            this.closeAddModal();
        },
    }));
}

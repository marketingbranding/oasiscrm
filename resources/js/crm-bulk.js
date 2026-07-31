window.CrmBulk = {
    selected: new Set(),
    pendingConfirm: null,
    confirmModalName: 'bulk-confirm',

    init() {
        document.getElementById('select-all')?.addEventListener('change', (e) => {
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = e.target.checked;
                e.target.checked ? this.selected.add(cb.value) : this.selected.delete(cb.value);
            });
            this.toggleBar();
        });
        document.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.addEventListener('change', (e) => {
                e.target.checked ? this.selected.add(e.target.value) : this.selected.delete(e.target.value);
                this.toggleBar();
            });
        });
    },

    toggleBar() {
        const bar = document.getElementById('bulk-bar');
        if (!bar) return;
        const count = this.selected.size;
        document.getElementById('bulk-count').textContent = count;
        bar.style.display = count > 0 ? 'block' : 'none';
    },

    destroy(route) {
        const count = this.selected.size;
        if (!count) return;
        this.openConfirm({
            message: 'Hapus ' + count + ' data terpilih? Data akan dihapus dari daftar lokal dan Google Sheets.',
            okLabel: 'Hapus ' + count,
            run: () => {
                document.getElementById('bulk-ids').value = Array.from(this.selected).join(',');
                document.getElementById('bulk-form').submit();
            },
        });
    },

    updateStatus(route, accentColor) {
        const count = this.selected.size;
        if (!count) return;
        const status = document.getElementById('bulk-new-status').value;
        this.openConfirm({
            message: 'Ubah status ' + count + ' data terpilih menjadi "' + status + '"?',
            okLabel: 'Ubah Status',
            run: () => {
                document.getElementById('bulk-update-ids').value = Array.from(this.selected).join(',');
                document.getElementById('bulk-update-status').value = status;
                document.getElementById('bulk-update-form').submit();
            },
        });
    },

    openConfirm({ message, okLabel, run }) {
        const messageEl = document.getElementById('bulk-confirm-message');
        const okEl = document.getElementById('bulk-confirm-ok');
        if (messageEl) messageEl.textContent = message;
        if (okEl) okEl.textContent = okLabel;
        this.pendingConfirm = run;
        window.dispatchEvent(new CustomEvent('oasis:modal-open', {
            detail: { name: this.confirmModalName, trigger: document.activeElement },
        }));
    },

    cancelConfirm() {
        this.pendingConfirm = null;
        window.dispatchEvent(new CustomEvent('oasis:modal-close', {
            detail: { name: this.confirmModalName, reason: 'cancel' },
        }));
    },

    confirmPending() {
        const run = this.pendingConfirm;
        if (!run) return;
        this.pendingConfirm = null;
        run();
    },
};

document.addEventListener('DOMContentLoaded', () => window.CrmBulk.init());

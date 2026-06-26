window.CrmBulk = {
    selected: new Set(),

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
        if (!confirm('Hapus ' + count + ' data terpilih?')) return;
        document.getElementById('bulk-ids').value = Array.from(this.selected).join(',');
        document.getElementById('bulk-form').submit();
    },

    updateStatus(route, accentColor) {
        const count = this.selected.size;
        if (!count) return;
        const status = document.getElementById('bulk-new-status').value;
        if (!confirm('Ubah status ' + count + ' data terpilih menjadi ' + status + '?')) return;
        document.getElementById('bulk-update-ids').value = Array.from(this.selected).join(',');
        document.getElementById('bulk-update-status').value = status;
        document.getElementById('bulk-update-form').submit();
    }
};

document.addEventListener('DOMContentLoaded', () => window.CrmBulk.init());

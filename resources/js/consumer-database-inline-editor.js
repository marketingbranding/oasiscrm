export default function registerConsumerDatabaseInlineEditor(Alpine) {
    Alpine.data('consumerDatabaseCellEditor', config => ({
        editing: false,
        saving: false,
        error: '',
        value: config.value,
        display: config.display,
        token: config.token,
        start() { this.error = ''; this.editing = true; this.$nextTick(() => this.$refs.input?.focus()); },
        cancel() { this.value = config.value; this.error = ''; this.editing = false; },
        async save() {
            if (this.saving) return;
            this.saving = true;
            this.error = '';
            try {
                const response = await fetch(config.url, {
                    method: 'PATCH',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ column: config.column, value: this.value, expected_updated_at: this.token }),
                });
                const data = await response.json().catch(() => ({}));
                if (response.status === 409) {
                    window.dispatchEvent(new CustomEvent('oasis-conflict', { detail: { response: data, context: { reload: () => window.location.reload() } } }));
                    this.error = data.message || 'Data telah berubah.';
                    return;
                }
                if ([422, 403].includes(response.status) || !response.ok) {
                    this.error = data.message || Object.values(data.errors || {})[0]?.[0] || 'Data belum dapat disimpan.';
                    if (response.status === 403) window.oasisToast?.(this.error, 'error');
                    return;
                }
                this.value = data.value;
                config.value = data.value;
                this.display = data.display;
                config.display = data.display;
                this.token = data.write_token;
                config.token = data.write_token;
                this.editing = false;
                window.oasisToast?.('Data konsumen berhasil diperbarui.', 'success');
            } catch (_) {
                this.error = 'Koneksi bermasalah. Perubahan belum disimpan.';
            } finally {
                this.saving = false;
            }
        },
        keydown(event) {
            if (event.key === 'Escape') { event.preventDefault(); this.cancel(); }
            if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') { event.preventDefault(); this.save(); }
        },
    }));
}

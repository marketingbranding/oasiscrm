const excludedField = (name, type) => !name
    || type === 'password'
    || ['_token', '_method', 'expected_updated_at', 'expected_sync_id'].includes(name)
    || /password|token|secret/i.test(name);

export default function registerConflict(Alpine) {
    Alpine.data('crmConflict', (config = {}) => ({
        open: Boolean(config.initial?.code),
        conflict: config.initial || null,
        copied: false,
        copyError: '',
        validationMessage: '',
        savedValues: null,
        trigger: null,
        conflictContext: {},
        storagePrefix: 'oasis_conflict_unsaved',

        init() {
            if (this.open) {
                document.documentElement.dataset.oasisConflictOpen = '1';
                queueMicrotask(() => {
                    this.preserveUnsavedValues(document.querySelector('[data-conflict-form]'));
                    this.focusDialog();
                });
            }
        },

        async handleConflict(response, context = {}) {
            const data = response instanceof Response ? await response.json() : response;
            this.showConflict(data?.message, data);
            this.conflictContext = context;
            this.preserveUnsavedValues(context.form || document.querySelector('[data-conflict-form]'), context.originalValues);
        },

        showConflict(message, metadata = {}) {
            this.trigger = document.activeElement;
            this.conflict = { ...metadata, message: message || 'Data ini telah diperbarui oleh pengguna lain.' };
            this.validationMessage = '';
            this.copied = false;
            this.open = true;
            document.documentElement.dataset.oasisConflictOpen = '1';
            this.focusDialog();
        },

        focusDialog() {
            this.$nextTick(() => this.$refs.dialog?.querySelector('button')?.focus({ preventScroll: true }));
        },

        closeConflict() {
            this.open = false;
            window.setTimeout(() => { delete document.documentElement.dataset.oasisConflictOpen; }, 0);
            this.$nextTick(() => this.trigger?.focus({ preventScroll: true }));
        },

        trapFocus(event) {
            const focusable = [...this.$refs.dialog.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                .filter(element => element.offsetParent !== null);
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        },

        reloadConflictRecord() {
            const warning = 'Muat ulang data akan mengganti nilai pada form saat ini. Pastikan Anda sudah menyalin perubahan yang masih diperlukan.';
            if (!window.confirm(warning)) return;
            this.clearSavedValues();
            if (typeof this.conflictContext.reload === 'function') {
                this.closeConflict();
                this.conflictContext.reload();
                return;
            }
            window.location.href = this.conflict?.reload_url || window.location.href;
        },

        async copyUnsavedValues() {
            const values = this.savedValues || this.readSavedValues();
            if (!values || !Object.keys(values).length) return;
            const text = Object.entries(values).map(([field, value]) => `${field}: ${Array.isArray(value) ? value.join(', ') : value}`).join('\n');
            this.copyError = '';
            let copied = false;
            try {
                await navigator.clipboard.writeText(text);
                copied = true;
            } catch (_) {
                try {
                    const textarea = document.createElement('textarea');
                    textarea.value = text; document.body.appendChild(textarea); textarea.select();
                    copied = document.execCommand('copy'); textarea.remove();
                } catch (_) { copied = false; }
            }
            this.copied = copied;
            if (!copied) this.copyError = 'Browser tidak mengizinkan penyalinan otomatis. Pilih dan salin nilai form secara manual sebelum memuat ulang.';
        },

        preserveUnsavedValues(form, originalValues = null) {
            if (!form) return;
            const values = {};
            for (const element of form.elements) {
                if (excludedField(element.name, element.type) || element.disabled || element.dataset.formula === 'true') continue;
                if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) continue;
                const value = element.value;
                if (originalValues && String(originalValues[element.name] ?? '') === String(value ?? '')) continue;
                if (Object.hasOwn(values, element.name)) values[element.name] = [].concat(values[element.name], value);
                else values[element.name] = value;
            }
            this.savedValues = values;
            try { sessionStorage.setItem(this.storageKey(), JSON.stringify({ savedAt: Date.now(), values })); } catch (_) {}
        },

        readSavedValues() {
            try {
                const saved = JSON.parse(sessionStorage.getItem(this.storageKey()) || 'null');
                if (!saved || Date.now() - saved.savedAt > 30 * 60 * 1000) { this.clearSavedValues(); return null; }
                return saved.values;
            } catch (_) { return null; }
        },

        clearSavedValues() {
            this.savedValues = null;
            try { sessionStorage.removeItem(this.storageKey()); } catch (_) {}
        },

        storageKey() {
            return `${this.storagePrefix}:${this.conflict?.record_type || 'form'}:${this.conflict?.record_id || 'current'}`;
        },

        discardSavedValues() {
            this.clearSavedValues();
            this.closeConflict();
        },

        async submitForm(form, context = {}) {
            this.validationMessage = '';
            try {
                const response = await fetch(form.action, {
                    method: form.method || 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: new FormData(form),
                });
                if (response.status === 409) {
                    await this.handleConflict(response, { ...context, form });
                    return;
                }
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    this.validationMessage = data.message || Object.values(data.errors || {})[0]?.[0] || 'Data belum dapat disimpan. Periksa kembali isian form.';
                    if (data.updated_at) form.querySelector('[name="expected_updated_at"]')?.setAttribute('value', data.updated_at);
                    return;
                }
                this.clearSavedValues();
                window.dispatchEvent(new CustomEvent('oasis-presence-saved'));
                window.location.href = data.reload_url || context.successUrl || window.location.href;
            } catch (_) {
                this.validationMessage = 'Koneksi bermasalah. Form tetap terbuka dan perubahan Anda belum dihapus.';
            }
        },
    }));
}

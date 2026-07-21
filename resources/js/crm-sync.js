export default function registerSync(Alpine) {
    Alpine.data('crmSync', (config) => ({
        state: config.initial?.status || 'idle', result: config.initial || {}, open: false, active: false,
        elapsed: 0, startedAt: null, timer: null, controller: null, timeoutMs: Number(config.timeoutMs || 45000),
        storageKey: `oasis_sync_${config.key}`,

        init() {
            if (this.state === 'syncing') { this.open = true; this.startedAt = Date.parse(this.result.started_at) || Date.now(); this.startTimer(); this.pollStatus(); }
            window.addEventListener('storage', (event) => { if (event.key === this.storageKey && event.newValue) this.state = 'syncing'; });
        },
        async submit(form) {
            if (this.active || this.browserLocked()) { this.showAlreadyRunning(); return; }
            this.active = true; this.open = true; this.state = 'syncing'; this.result = {};
            this.startedAt = Date.now(); this.elapsed = 0; this.lockBrowser(); this.startTimer();
            this.$nextTick(() => this.$refs.dialog?.focus());
            this.controller = new AbortController();
            const timeout = window.setTimeout(() => this.controller.abort(), this.timeoutMs);
            try {
                const response = await fetch(form.action, {
                    method: 'POST', signal: this.controller.signal,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: new FormData(form),
                });
                const data = await response.json().catch(() => ({}));
                if (response.status === 409) { this.result = data; this.state = 'syncing'; this.pollStatus(); return; }
                this.applyResult(data, response.ok);
            } catch (error) {
                if (error.name === 'AbortError') {
                    this.state = 'timed_out';
                    this.result = { message: 'Proses memerlukan waktu lebih lama dari biasanya.', retryable: false };
                } else {
                    this.state = 'failed';
                    this.result = { message: 'Koneksi terputus sebelum status sinkronisasi diterima.', retryable: true, local_data_changed: false };
                    this.unlockBrowser();
                }
            } finally {
                window.clearTimeout(timeout); this.active = false;
                if (!['syncing', 'timed_out'].includes(this.state)) this.stopTimer();
            }
        },
        applyResult(data, httpOk = true) {
            this.result = data || {};
            this.state = data.status || (httpOk ? 'success' : 'failed');
            if (this.terminal) { this.stopTimer(); this.unlockBrowser(); }
        },
        async pollStatus() {
            try {
                const response = await fetch(config.statusUrl, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const data = await response.json(); this.applyResult(data, true);
                if (!this.terminal) window.setTimeout(() => this.pollStatus(), 3000);
            } catch (_) { /* Status tetap tidak diketahui; pengguna dapat memeriksa lagi. */ }
        },
        checkStatus() { this.open = true; this.pollStatus(); },
        retry(form) { if (this.result.retryable && !this.active) this.submit(form); },
        waitLonger() { this.state = 'syncing'; this.startTimer(); this.pollStatus(); },
        startTimer() { this.stopTimer(); this.timer = window.setInterval(() => { this.elapsed = Math.floor((Date.now() - this.startedAt) / 1000); }, 1000); },
        stopTimer() { if (this.timer) window.clearInterval(this.timer); this.timer = null; },
        lockBrowser() { try { localStorage.setItem(this.storageKey, String(Date.now() + 15 * 60 * 1000)); } catch (_) {} },
        unlockBrowser() { try { localStorage.removeItem(this.storageKey); } catch (_) {} },
        browserLocked() { try { return Number(localStorage.getItem(this.storageKey) || 0) > Date.now(); } catch (_) { return false; } },
        showAlreadyRunning() { this.open = true; this.state = 'syncing'; this.result = { message: 'Sinkronisasi untuk scope ini sedang berjalan.' }; this.startedAt ||= Date.now(); this.startTimer(); this.pollStatus(); },
        formatElapsed(seconds) { const minutes = Math.floor(seconds / 60); return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`; },
        formatTime(value) { if (!value) return '-'; return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)); },
        get terminal() { return ['success', 'partial_success', 'failed'].includes(this.state); },
        get stateLabel() { return { idle: 'Siap', loading: 'Memuat data', syncing: 'Sedang Sinkronisasi', success: 'Berhasil', partial_success: 'Selesai dengan Catatan', failed: 'Gagal', timed_out: 'Memerlukan Waktu Lebih Lama', empty: 'Belum Ada Data' }[this.state] || this.state; },
        get summaryEntries() { return Object.entries(this.result.summary || {}).filter(([, value]) => Number.isFinite(Number(value))); },
    }));
}

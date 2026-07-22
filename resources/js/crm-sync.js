export default function registerSync(Alpine) {
    Alpine.data('crmSync', (config) => {
        let pointerX = 0;
        let pointerY = 0;
        let cardLeft = 12;
        let cardTop = 12;
        let activationAt = 0;
        let activationMode = 'keyboard';
        let pointerFrame = null;
        let pointerHandler = null;
        let storageHandler = null;
        let pollTimer = null;
        let dismissTimer = null;

        return {
            state: config.initial?.status || 'idle',
            result: config.initial || {},
            open: false,
            active: false,
            detailsOpen: false,
            completedAt: null,
            elapsed: 0,
            startedAt: null,
            timer: null,
            controller: null,
            timeoutMs: Number(config.timeoutMs || 45000),
            storageKey: `oasis_sync_${config.key}`,

            init() {
                storageHandler = (event) => {
                    if (event.key !== this.storageKey || !event.newValue) return;
                    activationMode = window.matchMedia('(pointer: fine)').matches ? 'pointer' : 'touch';
                    this.showRunningCard();
                    this.pollStatus();
                };
                window.addEventListener('storage', storageHandler);

                if (this.state === 'syncing') {
                    activationMode = window.matchMedia('(pointer: fine)').matches ? 'pointer' : 'touch';
                    this.startedAt = Date.parse(this.result.started_at) || Date.now();
                    this.showRunningCard();
                    this.pollStatus();
                }
            },

            destroy() {
                this.stopCursorTracking();
                this.stopTimer();
                this.clearPoll();
                this.clearDismiss();
                if (storageHandler) window.removeEventListener('storage', storageHandler);
                this.controller?.abort();
            },

            captureActivation(event) {
                activationAt = Date.now();
                activationMode = event.pointerType === 'touch' ? 'touch' : 'pointer';
                pointerX = event.clientX;
                pointerY = event.clientY;
            },

            prepareActivation(form) {
                if (Date.now() - activationAt <= 1000) return;
                activationMode = 'keyboard';
                const rect = (this.$refs.button || form).getBoundingClientRect();
                pointerX = rect.right;
                pointerY = rect.top + rect.height / 2;
            },

            async submit(form) {
                this.prepareActivation(form);
                if (this.active || this.browserLocked()) {
                    this.showAlreadyRunning();
                    return;
                }

                this.active = true;
                this.result = {};
                this.startedAt = Date.now();
                this.elapsed = 0;
                this.lockBrowser();
                this.showRunningCard();
                this.controller = new AbortController();
                const timeout = window.setTimeout(() => this.controller.abort(), this.timeoutMs);

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        signal: this.controller.signal,
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                        body: new FormData(form),
                    });
                    const data = await response.json().catch(() => ({}));
                    if (response.status === 409) {
                        this.result = data;
                        this.state = 'syncing';
                        this.pollStatus();
                        return;
                    }
                    this.applyResult(data, response.ok);
                } catch (error) {
                    if (error.name === 'AbortError') {
                        this.applyResult({ status: 'timed_out', message: 'Proses lebih lama dari biasanya.', retryable: false }, false);
                    } else {
                        this.applyResult({ status: 'failed', message: 'Koneksi terputus sebelum status sinkronisasi diterima.', retryable: true, local_data_changed: false }, false);
                    }
                } finally {
                    window.clearTimeout(timeout);
                    this.active = false;
                }
            },

            showRunningCard() {
                this.clearDismiss();
                this.detailsOpen = false;
                this.open = true;
                this.state = 'syncing';
                this.startedAt ||= Date.now();
                this.startTimer();
                this.$nextTick(() => {
                    this.placeInitialCard();
                    if (activationMode === 'pointer') this.startCursorTracking();
                });
            },

            applyResult(data, httpOk = true) {
                this.result = data || {};
                this.state = data.status || (httpOk ? 'success' : 'failed');

                if (this.state === 'syncing') {
                    this.showRunningCard();
                    return;
                }

                this.stopCursorTracking();
                this.stopTimer();
                this.clearPoll();
                if (this.state !== 'timed_out') this.unlockBrowser();
                this.$nextTick(() => this.freezeCardInViewport());

                if (this.state === 'success') {
                    this.completedAt = this.result.finished_at || new Date().toISOString();
                    this.scheduleDismiss(2500);
                    window.dispatchEvent(new CustomEvent('crm-sync-completed', { detail: { key: config.key, result: this.result } }));
                } else if (this.state === 'partial_success') {
                    this.scheduleDismiss(5000);
                }
            },

            async pollStatus() {
                this.clearPoll();
                try {
                    const response = await fetch(config.statusUrl, { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    const data = await response.json();
                    this.applyResult(data, true);
                } catch (_) {
                    // Status remains unknown; manual status checking stays available.
                } finally {
                    if (this.state === 'syncing' && !pollTimer) pollTimer = window.setTimeout(() => this.pollStatus(), 3000);
                }
            },

            checkStatus() {
                this.open = true;
                this.pollStatus();
            },

            retry(form) {
                if (this.result.retryable && !this.active) this.submit(form);
            },

            waitLonger() {
                activationMode = window.matchMedia('(pointer: fine)').matches ? 'pointer' : 'keyboard';
                this.showRunningCard();
                this.pollStatus();
            },

            showDetails() {
                this.detailsOpen = true;
                this.clearDismiss();
                this.$nextTick(() => this.freezeCardInViewport());
            },

            dismiss() {
                this.open = false;
                this.stopCursorTracking();
                this.clearDismiss();
            },

            startCursorTracking() {
                if (pointerHandler || !this.open || this.state !== 'syncing') return;
                pointerHandler = (event) => {
                    if (event.pointerType === 'touch') return;
                    pointerX = event.clientX;
                    pointerY = event.clientY;
                    if (pointerFrame !== null) return;
                    pointerFrame = window.requestAnimationFrame(() => {
                        pointerFrame = null;
                        this.positionNearPointer();
                    });
                };
                window.addEventListener('pointermove', pointerHandler, { passive: true });
            },

            stopCursorTracking() {
                if (pointerHandler) window.removeEventListener('pointermove', pointerHandler);
                pointerHandler = null;
                if (pointerFrame !== null) window.cancelAnimationFrame(pointerFrame);
                pointerFrame = null;
            },

            placeInitialCard() {
                if (activationMode === 'touch') {
                    this.placeTouchCard();
                } else if (activationMode === 'keyboard' || pointerX === 0 && pointerY === 0) {
                    this.placeKeyboardCard();
                } else {
                    this.positionNearPointer();
                }
            },

            positionNearPointer() {
                const card = this.$refs.card;
                if (!card) return;
                const width = card.offsetWidth || 240;
                const height = card.offsetHeight || 100;
                const margin = 8;
                let left = pointerX + 16;
                let top = pointerY - height - 20;

                if (left + width > window.innerWidth - margin) left = pointerX - width - 16;
                if (top < margin) top = pointerY + 20;

                this.setCardPosition(
                    Math.min(Math.max(margin, left), Math.max(margin, window.innerWidth - width - margin)),
                    Math.min(Math.max(margin, top), Math.max(margin, window.innerHeight - height - margin)),
                );
            },

            placeKeyboardCard() {
                const card = this.$refs.card;
                if (!card) return;
                const width = card.offsetWidth || 240;
                const height = card.offsetHeight || 100;
                const button = this.$refs.button?.getBoundingClientRect();
                if (!button) {
                    this.setCardPosition(window.innerWidth - width - 12, 12);
                    return;
                }
                pointerX = button.right;
                pointerY = button.top + button.height / 2;
                this.positionNearPointer();
            },

            placeTouchCard() {
                const card = this.$refs.card;
                if (!card) return;
                const width = card.offsetWidth || 240;
                const height = card.offsetHeight || 100;
                this.setCardPosition((window.innerWidth - width) / 2, window.innerHeight - height - 16);
            },

            freezeCardInViewport() {
                const card = this.$refs.card;
                if (!card) return;
                const width = card.offsetWidth || 240;
                const height = card.offsetHeight || 100;
                this.setCardPosition(
                    Math.min(Math.max(8, cardLeft), Math.max(8, window.innerWidth - width - 8)),
                    Math.min(Math.max(8, cardTop), Math.max(8, window.innerHeight - height - 8)),
                );
            },

            setCardPosition(left, top) {
                cardLeft = Math.round(left);
                cardTop = Math.round(top);
                if (this.$refs.card) this.$refs.card.style.transform = `translate3d(${cardLeft}px, ${cardTop}px, 0)`;
            },

            scheduleDismiss(milliseconds) {
                this.clearDismiss();
                dismissTimer = window.setTimeout(() => this.dismiss(), milliseconds);
            },

            clearDismiss() {
                if (dismissTimer) window.clearTimeout(dismissTimer);
                dismissTimer = null;
            },

            clearPoll() {
                if (pollTimer) window.clearTimeout(pollTimer);
                pollTimer = null;
            },

            startTimer() {
                this.stopTimer();
                this.timer = window.setInterval(() => {
                    this.elapsed = Math.floor((Date.now() - this.startedAt) / 1000);
                }, 1000);
            },

            stopTimer() {
                if (this.timer) window.clearInterval(this.timer);
                this.timer = null;
            },

            lockBrowser() {
                try { localStorage.setItem(this.storageKey, String(Date.now() + 15 * 60 * 1000)); } catch (_) {}
            },

            unlockBrowser() {
                try { localStorage.removeItem(this.storageKey); } catch (_) {}
            },

            browserLocked() {
                try { return Number(localStorage.getItem(this.storageKey) || 0) > Date.now(); } catch (_) { return false; }
            },

            showAlreadyRunning() {
                this.result = { message: 'Sinkronisasi untuk scope ini sedang berjalan.' };
                this.showRunningCard();
                this.pollStatus();
            },

            formatElapsed(seconds) {
                const minutes = Math.floor(seconds / 60);
                return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
            },

            formatTime(value) {
                if (!value) return '-';
                return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
            },

            metricLabel(key) {
                return { checked: 'data diperiksa', created: 'dibuat', updated: 'diperbarui', unchanged: 'tidak berubah', deleted: 'dihapus', failed: 'gagal' }[key] || key.replaceAll('_', ' ');
            },

            get terminal() {
                return ['success', 'partial_success', 'failed'].includes(this.state);
            },

            get interactive() {
                return ['partial_success', 'failed', 'timed_out'].includes(this.state);
            },

            get stateLabel() {
                return { idle: 'Siap', loading: 'Memuat data', syncing: 'Sinkronisasi berjalan', success: 'Sinkronisasi selesai', partial_success: 'Selesai dengan catatan', failed: 'Sinkronisasi gagal', timed_out: 'Status belum diketahui', empty: 'Belum ada data' }[this.state] || this.state;
            },

            get summaryEntries() {
                return Object.entries(this.result.summary || {}).filter(([, value]) => Number.isFinite(Number(value)));
            },
        };
    });
}

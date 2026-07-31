export default function registerPwa(Alpine) {
    Alpine.data('oasisPwa', () => ({
        installable: false,
        standalone: false,
        updateAvailable: false,
        updateDismissed: false,
        installDismissed: false,
        applyingUpdate: false,
        deferredPrompt: null,
        registration: null,

        init() {
            this.standalone = this.isStandalone();
            this.installDismissed = window.localStorage?.getItem('oasis.pwa.install.dismissed') === '1';

            window.addEventListener('beforeinstallprompt', (event) => {
                event.preventDefault();
                this.deferredPrompt = event;
                this.installable = true;
            });

            window.addEventListener('appinstalled', () => {
                this.installable = false;
                this.deferredPrompt = null;
                this.standalone = true;
            });

            if (!('serviceWorker' in navigator)) {
                return;
            }
            if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                return;
            }

            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (this.applyingUpdate) {
                    window.location.reload();
                }
            });

            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/service-worker.js').catch((error) => {
                    if (import.meta.env.DEV) {
                        console.warn('OASIS service worker registration failed:', error);
                    }
                });
            });

            navigator.serviceWorker.ready.then((registration) => {
                this.registration = registration;
                registration.addEventListener('updatefound', () => {
                    const worker = registration.installing || registration.waiting;
                    if (!worker) {
                        return;
                    }
                    worker.addEventListener('statechange', () => {
                        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                            this.updateAvailable = true;
                        }
                    });
                });
            }).catch(() => {});
        },

        isStandalone() {
            return window.matchMedia?.('(display-mode: standalone)').matches
                || window.navigator?.standalone === true;
        },

        install() {
            if (!this.deferredPrompt) {
                return;
            }
            this.deferredPrompt.prompt();
            this.deferredPrompt.userChoice.finally(() => {
                this.deferredPrompt = null;
                this.installable = false;
            });
        },

        dismissInstall() {
            this.installDismissed = true;
            window.localStorage?.setItem('oasis.pwa.install.dismissed', '1');
        },

        dismissUpdate() {
            this.updateDismissed = true;
        },

        applyUpdate() {
            this.applyingUpdate = true;
            if (this.registration?.waiting) {
                this.registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            } else {
                this.registration?.update();
            }
        },
    }));
}

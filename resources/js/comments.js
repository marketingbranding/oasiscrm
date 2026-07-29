const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

export default function registerComments(Alpine) {
    Alpine.data('commentsPanel', config => ({
        comments: [], loading: true, loadingOlder: false, loadError: '', retryAction: null,
        page: 0, lastPage: 1, total: Number(config.initialCount || 0),
        canCreate: false, canMention: false, body: '', mentions: [], sending: false,
        replies: {}, editing: { id: null, body: '', mentions: [], lock_version: 0 }, deletingId: null, moderation: null,
        historyOpen: false, historyLoading: false, history: [], historyComment: null, overlayTrigger: null,
        suggestions: [], mentionOpen: false, mentionIndex: 0, mentionTimer: null, mentionRequest: null, activeDraft: null, mentionRange: null, mentionTextarea: null,

        init() { this.load(true); },

        async load(reset = false) {
            if (reset) { this.loading = true; this.loadError = ''; }
            else this.loadingOlder = true;
            const nextPage = reset ? 1 : this.page + 1;
            try {
                const url = new URL(config.indexUrl, window.location.origin);
                url.searchParams.set('alias', config.alias);
                url.searchParams.set('id', config.id);
                url.searchParams.set('page', nextPage);
                const data = await this.fetchJson(url);
                this.comments = reset ? data.data : [...data.data, ...this.comments];
                this.page = data.meta.current_page;
                this.lastPage = data.meta.last_page;
                this.total = data.meta.total;
                this.canCreate = data.meta.can_create;
                this.canMention = data.meta.can_mention;
                this.retryAction = null;
            } catch (error) {
                this.loadError = error.message;
                this.retryAction = () => this.load(reset);
                this.toast(error.message, 'error');
            } finally {
                this.loading = false;
                this.loadingOlder = false;
                if (window.location.hash.startsWith('#comment-')) this.$nextTick(() => this.focusLinkedComment());
            }
        },

        async submitComment(event, parent = null) {
            const draft = parent ? this.replyDraft(parent.id) : this;
            if (this.sending || !draft.body.trim()) return;
            this.sending = true;
            try {
                const data = await this.mutate(config.storeUrl, 'POST', {
                    alias: config.alias, id: config.id, body: draft.body,
                    parent_id: parent?.id || null,
                    mentioned_user_ids: draft.mentions.map(user => user.id),
                });
                if (parent) {
                    parent.replies.push(data.data);
                    parent.reply_count = parent.replies.length;
                    this.replies[parent.id] = { body: '', mentions: [], open: false };
                } else {
                    this.comments.push(data.data);
                    this.body = '';
                    this.mentions = [];
                }
                this.total++;
                this.closeMentions();
                this.toast(parent ? 'Balasan berhasil dikirim.' : 'Komentar berhasil dikirim.', 'success');
            } catch (error) {
                this.networkFailure(error, () => this.submitComment(event, parent));
            } finally { this.sending = false; }
        },

        startEdit(comment) {
            this.editing = { id: comment.id, body: comment.body, mentions: [...comment.mentions], lock_version: comment.lock_version };
            this.closeMentions();
        },

        async saveEdit(event, comment) {
            if (!comment || this.sending || !this.editing?.body.trim()) return;
            this.sending = true;
            try {
                const data = await this.mutate(config.updateUrl.replace('__COMMENT__', comment.id), 'PATCH', {
                    body: this.editing.body,
                    expected_lock_version: this.editing.lock_version,
                    mentioned_user_ids: this.editing.mentions.map(user => user.id),
                }, event.currentTarget, () => { this.clearEdit(); this.load(true); });
                this.replaceComment(data.data);
                this.clearEdit();
                this.toast('Komentar berhasil diperbarui.', 'success');
            } catch (error) {
                this.networkFailure(error, () => this.saveEdit(event, comment));
            } finally { this.sending = false; }
        },

        async deleteComment(comment) {
            if (this.deletingId || !window.confirm('Hapus komentar ini?')) return;
            this.deletingId = comment.id;
            try {
                const data = await this.mutate(config.deleteUrl.replace('__COMMENT__', comment.id), 'DELETE', {
                    expected_lock_version: comment.lock_version,
                }, null, () => this.load(true));
                this.replaceComment(data.data);
                this.total = Math.max(0, this.total - 1);
                this.toast('Komentar berhasil dihapus.', 'success');
            } catch (error) { this.networkFailure(error, () => this.deleteComment(comment)); }
            finally { this.deletingId = null; }
        },

        async restoreComment(comment) {
            if (this.sending) return;
            this.sending = true;
            try {
                const data = await this.mutate(config.restoreUrl.replace('__COMMENT__', comment.id), 'POST', {
                    expected_lock_version: comment.lock_version,
                }, null, () => { this.clearEdit(); this.load(true); });
                this.replaceComment(data.data);
                this.total++;
                this.toast('Komentar berhasil dipulihkan.', 'success');
            } catch (error) { this.networkFailure(error, () => this.restoreComment(comment)); }
            finally { this.sending = false; }
        },

        openModeration(comment) {
            this.overlayTrigger = document.activeElement;
            this.moderation = { comment, reason: '' };
            this.$nextTick(() => this.$refs.moderationDialog?.querySelector('textarea')?.focus());
        },
        closeModeration() { this.moderation = null; this.$nextTick(() => this.overlayTrigger?.focus()); },
        async hideComment(event) {
            if (this.sending || !this.moderation?.reason.trim()) return;
            this.sending = true;
            const comment = this.moderation.comment;
            try {
                const data = await this.mutate(config.moderateUrl.replace('__COMMENT__', comment.id), 'POST', {
                    action: 'hide', reason: this.moderation.reason, expected_lock_version: comment.lock_version,
                }, event.currentTarget, () => { this.clearEdit(); this.load(true); });
                this.replaceComment(data.data);
                this.total = Math.max(0, this.total - 1);
                this.closeModeration();
                this.toast('Komentar berhasil disembunyikan.', 'success');
            } catch (error) { this.networkFailure(error, () => this.hideComment(event)); }
            finally { this.sending = false; }
        },

        async showHistory(comment) {
            this.overlayTrigger = document.activeElement;
            this.historyOpen = true; this.historyLoading = true; this.history = []; this.historyComment = comment;
            this.$nextTick(() => this.$refs.historyDialog?.querySelector('button')?.focus());
            try {
                const data = await this.fetchJson(config.historyUrl.replace('__COMMENT__', comment.id));
                this.history = data.data;
            } catch (error) { this.networkFailure(error, () => this.showHistory(comment)); }
            finally { this.historyLoading = false; }
        },
        closeHistory() { this.historyOpen = false; this.$nextTick(() => this.overlayTrigger?.focus()); },
        trapFocus(event, container) {
            const controls = [...(container?.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=-1])') || [])]
                .filter(control => control.offsetParent !== null);
            if (!controls.length) return;
            if (event.shiftKey && document.activeElement === controls[0]) { event.preventDefault(); controls.at(-1).focus(); }
            else if (!event.shiftKey && document.activeElement === controls.at(-1)) { event.preventDefault(); controls[0].focus(); }
        },

        replyDraft(id) {
            if (!this.replies[id]) this.replies[id] = { body: '', mentions: [], open: false };
            return this.replies[id];
        },
        clearEdit() { this.editing = { id: null, body: '', mentions: [], lock_version: 0 }; },

        mentionInput(event, draft) {
            if (event.isComposing || !this.canMention) return;
            this.activeDraft = draft;
            this.mentionTextarea = event.target;
            const match = event.target.value.slice(0, event.target.selectionStart).match(/(?:^|\s)@([^\s@]*)$/u);
            if (!match) { this.closeMentions(); return; }
            this.mentionRange = { start: event.target.selectionStart - match[1].length - 1, end: event.target.selectionStart };
            window.clearTimeout(this.mentionTimer);
            this.mentionTimer = window.setTimeout(() => this.searchMentions(match[1]), 250);
        },

        async searchMentions(query) {
            this.mentionRequest?.abort();
            this.mentionRequest = new AbortController();
            try {
                const url = new URL(config.mentionUrl, window.location.origin);
                url.searchParams.set('alias', config.alias); url.searchParams.set('id', config.id); url.searchParams.set('query', query);
                const data = await this.fetchJson(url, { signal: this.mentionRequest.signal });
                this.suggestions = data.data.slice(0, 10); this.mentionIndex = 0; this.mentionOpen = this.suggestions.length > 0;
            } catch (error) { if (error.name !== 'AbortError') this.toast(error.message, 'error'); }
        },

        mentionKey(event) {
            if (event.isComposing || !this.mentionOpen) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); this.mentionIndex = (this.mentionIndex + 1) % this.suggestions.length; }
            if (event.key === 'ArrowUp') { event.preventDefault(); this.mentionIndex = (this.mentionIndex - 1 + this.suggestions.length) % this.suggestions.length; }
            if (event.key === 'Enter') { event.preventDefault(); this.selectMention(this.suggestions[this.mentionIndex]); }
            if (event.key === 'Escape') { event.preventDefault(); this.closeMentions(); }
        },

        selectMention(user) {
            if (!this.activeDraft || this.activeDraft.mentions.some(item => item.id === user.id)) { this.closeMentions(); return; }
            const range = this.mentionRange || { start: this.activeDraft.body.length, end: this.activeDraft.body.length };
            const inserted = `@${user.name} `;
            const textarea = this.mentionTextarea;
            const caret = range.start + inserted.length;
            this.activeDraft.body = this.activeDraft.body.slice(0, range.start) + inserted + this.activeDraft.body.slice(range.end);
            this.activeDraft.mentions.push(user);
            this.closeMentions();
            this.$nextTick(() => { textarea?.focus(); textarea?.setSelectionRange(caret, caret); });
        },
        removeMention(draft, id) { draft.mentions = draft.mentions.filter(user => user.id !== id); },
        closeMentions() {
            window.clearTimeout(this.mentionTimer);
            this.mentionRequest?.abort();
            this.mentionOpen = false; this.suggestions = []; this.activeDraft = null; this.mentionRange = null; this.mentionTextarea = null;
        },

        submitShortcut(event, callback) {
            if (event.isComposing || this.mentionOpen) return;
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); callback(); }
        },
        replaceComment(next) {
            const parent = this.comments.find(item => item.id === next.id);
            if (parent) { Object.assign(parent, next); return; }
            for (const item of this.comments) {
                const reply = item.replies.find(candidate => candidate.id === next.id);
                if (reply) { Object.assign(reply, next); return; }
            }
        },
        formatDate(value) { return value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : ''; },
        initials(user) { return (user?.name || '?').split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase(); },
        focusLinkedComment() {
            const id = window.location.hash.match(/^#comment-(\d+)$/)?.[1];
            if (!id) return;
            const element = document.getElementById(`comment-${id}`);
            if (element) {
                element.scrollIntoView({ block: 'center' });
                element.classList.add('bg-[#fff3b0]');
            } else if (this.page < this.lastPage && !this.loadingOlder) {
                this.load(false);
            }
        },
        toast(message, type = 'error') { window.oasisToast?.(message, type); },
        networkFailure(error, retry) {
            if (error.conflict) return;
            this.retryAction = retry;
            this.toast(error.message || 'Koneksi bermasalah. Coba lagi.', 'error');
        },
        async fetchJson(url, options = {}) {
            let response;
            try { response = await fetch(url, { headers: { Accept: 'application/json', ...(options.headers || {}) }, ...options }); }
            catch (_) { throw new Error('Koneksi bermasalah. Coba lagi.'); }
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Permintaan belum dapat diproses.');
            return data;
        },
        async mutate(url, method, payload, form = null, reload = null) {
            let response;
            try {
                response = await fetch(url, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(payload) });
            } catch (_) { throw new Error('Koneksi bermasalah. Coba lagi.'); }
            if (response.status === 409) {
                const data = await response.json();
                window.dispatchEvent(new CustomEvent('oasis-conflict', { detail: { response: data, context: { form, reload, reloadLabel: 'Muat Ulang Komentar' } } }));
                const error = new Error(data.message); error.conflict = true; throw error;
            }
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Komentar belum dapat disimpan.');
            return data;
        },
    }));
}

@auth
<div x-data="aiChatWidget()" class="fixed bottom-4 right-20 z-[998] font-['Times_New_Roman']" @keydown.escape.window="open=false">
    <button type="button" @click="open = !open; if (open && !loaded) loadConversations()"
            class="relative w-12 h-12 bg-[#8c9ae0] hover:bg-[#7585d8] border-2 border-black rounded-none flex items-center justify-center shadow-lg transition-colors duration-200 font-[Helvetica] font-bold text-sm"
            title="Oasis AI - Asisten Magang">
        AI
    </button>

    <div x-show="open" x-cloak @click.outside="open=false"
         class="absolute bottom-16 right-0 w-[360px] max-w-[calc(100vw-2rem)] h-[520px] max-h-[calc(100vh-6rem)] bg-[#f5f0eb] border-2 border-black shadow-[6px_6px_0_#000] flex flex-col overflow-hidden">
        <div class="bg-black text-white px-3 py-2 flex items-center justify-between gap-2 font-[Helvetica] font-bold">
            <div>
                <div class="text-sm">Oasis AI - Asisten Magang</div>
                <div class="text-[10px] text-white/70 font-normal">Masih percobaan. Bisa salah/ngarang, cek ulang data penting. · {{ Auth::user()->branch?->name ?? 'Semua Cabang' }}</div>
            </div>
            <button type="button" @click="open=false" class="text-xl leading-none">×</button>
        </div>

        <div class="border-b-2 border-black bg-white px-2 py-1 flex gap-1 overflow-x-auto">
            <button type="button" @click="newChat()" class="shrink-0 border border-black bg-[#b3bd95] px-2 py-1 text-[11px] font-bold font-[Helvetica]">Chat Baru</button>
            <template x-for="conversation in conversations" :key="conversation.id">
                <button type="button" @click="loadConversation(conversation.id)" class="shrink-0 border border-black bg-white hover:bg-[#fcc20f] px-2 py-1 text-[11px] max-w-32 truncate" x-text="conversation.title"></button>
            </template>
        </div>

        <div x-ref="messages" class="flex-1 overflow-y-auto p-2 space-y-1.5 bg-[#f5f0eb]">
            <template x-if="messages.length === 0">
                <div class="border-2 border-black bg-white p-3 text-sm">
                    <strong class="block font-[Helvetica] mb-1">Tanya data Oasis</strong>
                    <p class="text-xs text-gray-600 mb-2">Contoh:</p>
                    <button type="button" @click="ask('jumlah akad hari ini ada berapa?')" class="block w-full text-left underline mb-1">jumlah akad hari ini ada berapa?</button>
                    <button type="button" @click="ask('jadwal konten untuk minggu ini apa saja?')" class="block w-full text-left underline">jadwal konten untuk minggu ini apa saja?</button>
                </div>
            </template>

            <template x-for="(message, index) in messages" :key="index">
                <div :class="message.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                    <div class="max-w-[88%] min-w-[45%] border-2 border-black bg-white text-sm leading-snug break-words">
                        <div :class="message.role === 'user' ? 'bg-[#8c9ae0]' : 'bg-[#f1c40f]'" class="border-b-2 border-black px-2 py-1 text-[10px] font-[Helvetica] font-bold uppercase" x-text="message.role === 'user' ? 'Kamu' : 'Oasis AI'"></div>
                        <div :class="message.role === 'user' ? 'bg-[#eef1ff]' : 'bg-white'" class="px-2 py-2 whitespace-pre-line leading-snug" x-text="message.content"></div>
                        <div x-show="message.role === 'assistant' && (message.actions || []).length" class="border-t-2 border-black bg-white px-2 py-1.5 space-y-1">
                            <template x-for="action in (message.actions || [])" :key="action.key || action.route">
                                <div>
                                    <p class="text-[10px] leading-tight text-gray-700 mb-1" x-text="action.hint || 'Data mungkin perlu di-sync ulang.'"></p>
                                    <button type="button" @click="triggerSync(action)" :disabled="syncing === action.route"
                                            class="border-2 border-black bg-[#b3bd95] hover:bg-[#9ead82] px-2 py-1 text-[10px] font-[Helvetica] font-bold disabled:opacity-50"
                                            x-text="syncLabel(action)"></button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>

            <div x-show="loading" x-cloak class="flex justify-start">
                <div class="max-w-[88%] min-w-[45%] border-2 border-black bg-white text-sm italic leading-snug">
                    <div class="bg-[#f1c40f] border-b-2 border-black px-2 py-1 text-[10px] font-[Helvetica] font-bold uppercase not-italic">Oasis AI</div>
                    <div class="px-2 py-2">AI sedang membaca data...</div>
                </div>
            </div>
        </div>

        <form @submit.prevent="send()" class="border-t-2 border-black bg-white p-1.5">
            <textarea x-model.trim="input" rows="1" maxlength="1000" :disabled="loading"
                      @keydown.enter.prevent="if (!$event.shiftKey) send()"
                      class="w-full border-2 border-black px-2 py-1 text-sm resize-none focus:outline-none"
                      placeholder="Tanya Oasis..."></textarea>
            <div class="flex items-center justify-between mt-1">
                <span class="text-[10px] text-gray-500" x-text="providerLabel"></span>
                <button type="submit" :disabled="loading || !input" class="bg-black text-white border-2 border-black px-3 py-1 text-xs font-bold font-[Helvetica] disabled:opacity-40">Kirim</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('alpine:init', function () {
    Alpine.data('aiChatWidget', function () {
        return {
            open: false,
            loaded: false,
            loading: false,
            syncing: null,
            syncStatus: {},
            input: '',
            conversationId: null,
            conversations: [],
            messages: [],
            providerLabel: 'read-only',
            csrf: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            showUrl: '{{ route('ai-chat.show', ['conversation' => '__ID__']) }}',
            parseResponse(r) {
                return r.text().then(text => {
                    let data = null;
                    try { data = text ? JSON.parse(text) : {}; }
                    catch (e) {
                        throw new Error('Server mengembalikan HTML, bukan JSON. Status ' + r.status + '. Cek login/migrate/log Laravel.');
                    }

                    if (!r.ok) throw new Error(data.message || data.error || 'Gagal memproses request AI chat.');
                    return data;
                });
            },
            loadConversations() {
                fetch('{{ route('ai-chat.index') }}', { headers: { Accept: 'application/json' } })
                    .then(r => this.parseResponse(r))
                    .then(data => { this.conversations = data.conversations || []; this.loaded = true; })
                    .catch(e => { this.loaded = true; this.providerLabel = 'error'; this.messages.push({ role: 'assistant', content: 'Gagal memuat histori AI: ' + e.message }); });
            },
            loadConversation(id) {
                fetch(this.showUrl.replace('__ID__', id), { headers: { Accept: 'application/json' } })
                    .then(r => this.parseResponse(r))
                    .then(data => {
                        this.conversationId = data.conversation.id;
                        this.messages = data.conversation.messages || [];
                        this.providerLabel = [data.conversation.provider, data.conversation.model].filter(Boolean).join(' · ') || 'read-only';
                        this.scrollDown();
                    })
                    .catch(e => { this.messages.push({ role: 'assistant', content: 'Gagal memuat percakapan: ' + e.message }); });
            },
            newChat() { this.conversationId = null; this.messages = []; this.input = ''; this.providerLabel = 'read-only'; },
            ask(text) { this.input = text; this.send(); },
            syncLabel(action) {
                if (this.syncing === action.route) return 'Sync...';
                if (this.syncStatus[action.route] === 'success') return 'Sync Berhasil';
                if (this.syncStatus[action.route] === 'error') return 'Sync Gagal';
                return action.label || 'Sync Sekarang';
            },
            triggerSync(action) {
                if (!action || !action.route || this.syncing) return;
                this.syncing = action.route;
                this.syncStatus[action.route] = null;
                const body = new URLSearchParams(action.payload || {});
                fetch(action.route, {
                    method: action.method || 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                }).then(r => {
                    if (!r.ok) throw new Error('Sync gagal.');
                    this.syncStatus[action.route] = 'success';
                }).catch(() => {
                    this.syncStatus[action.route] = 'error';
                }).finally(() => {
                    this.syncing = null;
                    setTimeout(() => { this.syncStatus[action.route] = null; }, 2500);
                });
            },
            send() {
                if (this.loading || !this.input) return;
                const text = this.input;
                this.input = '';
                this.messages.push({ role: 'user', content: text });
                this.loading = true;
                this.scrollDown();
                fetch('{{ route('ai-chat.chat') }}', {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify({ message: text, conversation_id: this.conversationId })
                }).then(r => this.parseResponse(r)).then(data => {
                    this.conversationId = data.conversation_id;
                    this.messages.push(data.message);
                    this.providerLabel = [data.provider, data.model].filter(Boolean).join(' · ');
                    this.loadConversations();
                }).catch(e => {
                    this.messages.push({ role: 'assistant', content: 'Gagal: ' + e.message });
                }).finally(() => {
                    this.loading = false;
                    this.scrollDown();
                });
            },
            scrollDown() { this.$nextTick(() => { if (this.$refs.messages) this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight; }); }
        };
    });
});
</script>
@endauth

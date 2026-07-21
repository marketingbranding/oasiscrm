@php
    $feedbackBranches = app(\App\Services\WorkspaceAccessService::class)->accessibleBranches(Auth::user());
    $defaultFeedbackBranch = (int) (request('branch_id') ?: Auth::user()->branch_id ?: $feedbackBranches->first()?->id);
@endphp

<div x-data="{
    open: false, tab: 'create', sending: false, sent: false, loadingHistory: false, error: '', reports: [],
    type: 'bug', branchId: @js((string) $defaultFeedbackBranch), module: '', title: '', description: '', activity: '',
    actualResult: '', expectedResult: '', suggestion: '', impact: '', targetUsers: '', frequency: 'tidak_tahu',
    needLevel: 'sedang', notes: '',
    async submit() {
        if (this.sending) return;
        this.sending = true; this.error = '';
        const form = new FormData();
        const values = {
            type: this.type, branch_id: this.branchId, module: this.module, title: this.title,
            description: this.description, activity: this.activity, actual_result: this.actualResult,
            expected_result: this.expectedResult, suggestion: this.suggestion, impact: this.impact,
            target_users: this.targetUsers, reproduction_frequency: this.frequency, need_level: this.needLevel,
            additional_notes: this.notes, page_url: window.location.origin + window.location.pathname,
            route_name: @js(request()->route()?->getName()), user_agent_summary: this.browserSummary(),
            screen_size: `${window.screen.width}x${window.screen.height}`,
        };
        Object.entries(values).forEach(([key, value]) => form.append(key, value || ''));
        if (this.$refs.screenshot?.files?.[0]) form.append('screenshot', this.$refs.screenshot.files[0]);
        try {
            const response = await fetch(@js(route('feedback-reports.store')), {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }, body: form,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Laporan belum dapat disimpan.');
            this.sent = true; this.reset(); await this.loadHistory();
        } catch (error) { this.error = error.message; }
        finally { this.sending = false; }
    },
    async loadHistory() {
        this.loadingHistory = true;
        try {
            const response = await fetch(@js(route('feedback-reports.history')), { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error();
            this.reports = (await response.json()).reports || [];
        } catch (_) { this.error = 'Riwayat belum dapat dimuat.'; }
        finally { this.loadingHistory = false; }
    },
    reset() {
        this.type = 'bug'; this.module = ''; this.title = ''; this.description = ''; this.activity = '';
        this.actualResult = ''; this.expectedResult = ''; this.suggestion = ''; this.impact = ''; this.targetUsers = '';
        this.frequency = 'tidak_tahu'; this.needLevel = 'sedang'; this.notes = '';
        if (this.$refs.screenshot) this.$refs.screenshot.value = '';
    },
    browserSummary() {
        const ua = navigator.userAgent;
        const browser = ua.includes('Edg/') ? 'Edge' : ua.includes('Chrome/') ? 'Chrome' : ua.includes('Firefox/') ? 'Firefox' : ua.includes('Safari/') ? 'Safari' : 'Browser lain';
        const os = ua.includes('Windows') ? 'Windows' : ua.includes('Android') ? 'Android' : ua.includes('iPhone') || ua.includes('iPad') ? 'iOS' : ua.includes('Mac OS') ? 'macOS' : ua.includes('Linux') ? 'Linux' : 'OS lain';
        return `${browser} / ${os}`;
    },
}" class="fixed bottom-4 right-4 z-50" x-cloak>
    <div x-show="open" @click.outside="open = false" class="absolute bottom-16 right-0 bg-white border-2 border-black shadow-xl w-[380px] max-w-[92vw] max-h-[82vh] flex flex-col font-['Times_New_Roman']">
        <div class="bg-[#c0392b] text-white px-3 py-2 flex items-center justify-between font-[Helvetica] font-bold text-sm">
            <span>Laporan / Masukan</span>
            <button type="button" @click="open = false" class="text-lg" aria-label="Tutup">&times;</button>
        </div>
        <div class="grid grid-cols-2 border-b-2 border-black">
            <button type="button" @click="tab = 'create'" :class="tab === 'create' ? 'bg-black text-white' : 'bg-gray-100'" class="px-3 py-2 text-xs font-bold border-r-2 border-black">Buat Laporan</button>
            <button type="button" @click="tab = 'history'; loadHistory()" :class="tab === 'history' ? 'bg-black text-white' : 'bg-gray-100'" class="px-3 py-2 text-xs font-bold">Riwayat Saya</button>
        </div>
        <div class="overflow-y-auto p-3">
            <div x-show="tab === 'create'">
                <p x-show="sent" class="border-2 border-black bg-[#b3bd95] px-3 py-2 mb-3 text-sm font-bold">Laporan tersimpan. Terima kasih.</p>
                <p x-show="error" class="border-2 border-black bg-[#d77a7a] px-3 py-2 mb-3 text-sm" x-text="error"></p>
                <form @submit.prevent="submit()" class="space-y-3">
                    <label class="block text-xs font-bold">Jenis laporan
                        <select x-model="type" class="mt-1 w-full border-2 border-black px-2 py-1.5 bg-white" required>
                            <option value="bug">Bug / Error</option><option value="masukan">Masukan</option><option value="permintaan_fitur">Permintaan Fitur</option>
                        </select>
                    </label>
                    <label class="block text-xs font-bold">Cabang
                        <select x-model="branchId" class="mt-1 w-full border-2 border-black px-2 py-1.5 bg-white" required>
                            @foreach($feedbackBranches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block text-xs font-bold">Modul
                        <input x-model.trim="module" maxlength="100" placeholder="Contoh: Database, Work Planner" class="mt-1 w-full border-2 border-black px-2 py-1.5" required>
                    </label>
                    <label class="block text-xs font-bold" x-text="type === 'permintaan_fitur' ? 'Judul fitur' : 'Judul singkat'"></label>
                    <input x-model.trim="title" maxlength="255" class="w-full border-2 border-black px-2 py-1.5" required>

                    <template x-if="type === 'bug'"><div class="space-y-3">
                        <label class="block text-xs font-bold">Apa yang sedang Anda lakukan?<textarea x-model.trim="activity" rows="2" maxlength="5000" class="mt-1 w-full border-2 border-black px-2 py-1.5" required></textarea></label>
                        <label class="block text-xs font-bold">Apa yang terjadi?<textarea x-model.trim="actualResult" @input="description = actualResult" rows="3" maxlength="5000" class="mt-1 w-full border-2 border-black px-2 py-1.5" required></textarea></label>
                        <label class="block text-xs font-bold">Seharusnya bagaimana?<textarea x-model.trim="expectedResult" rows="2" maxlength="5000" class="mt-1 w-full border-2 border-black px-2 py-1.5" required></textarea></label>
                        <label class="block text-xs font-bold">Seberapa sering terjadi?<select x-model="frequency" class="mt-1 w-full border-2 border-black px-2 py-1.5 bg-white"><option value="selalu">Selalu</option><option value="sering">Sering</option><option value="kadang">Kadang</option><option value="baru_sekali">Baru sekali</option><option value="tidak_tahu">Tidak tahu</option></select></label>
                    </div></template>
                    <template x-if="type === 'masukan'"><div class="space-y-3">
                        <label class="block text-xs font-bold">Masalah yang dirasakan<textarea x-model.trim="description" rows="3" maxlength="5000" class="mt-1 w-full border-2 border-black px-2 py-1.5" required></textarea></label>
                        <label class="block text-xs font-bold">Saran perbaikan<textarea x-model.trim="suggestion" rows="3" maxlength="5000" class="mt-1 w-full border-2 border-black px-2 py-1.5" required></textarea></label>
                        <label class="block text-xs font-bold">Dampak terhadap pekerjaan<textarea x-model.trim="impact" rows="2" maxlength="3000" class="mt-1 w-full border-2 border-black px-2 py-1.5" required></textarea></label>
                    </div></template>
                    <template x-if="type === 'permintaan_fitur'"><div class="space-y-3">
                        <label class="block text-xs font-bold">Masalah yang ingin diselesaikan<textarea x-model.trim="description" rows="3" maxlength="5000" class="mt-1 w-full border-2 border-black px-2 py-1.5" required></textarea></label>
                        <label class="block text-xs font-bold">Gambaran fitur<textarea x-model.trim="suggestion" rows="3" maxlength="5000" class="mt-1 w-full border-2 border-black px-2 py-1.5" required></textarea></label>
                        <label class="block text-xs font-bold">Siapa yang akan menggunakan<input x-model.trim="targetUsers" maxlength="255" class="mt-1 w-full border-2 border-black px-2 py-1.5" required></label>
                        <label class="block text-xs font-bold">Tingkat kebutuhan<select x-model="needLevel" class="mt-1 w-full border-2 border-black px-2 py-1.5 bg-white"><option value="rendah">Rendah</option><option value="sedang">Sedang</option><option value="tinggi">Tinggi</option><option value="mendesak">Mendesak</option></select></label>
                    </div></template>
                    <label class="block text-xs font-bold">Screenshot (opsional, maks. 5 MB)<input x-ref="screenshot" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-xs"></label>
                    <label class="block text-xs font-bold">Catatan tambahan (opsional)<textarea x-model.trim="notes" rows="2" maxlength="3000" class="mt-1 w-full border-2 border-black px-2 py-1.5"></textarea></label>
                    <button type="submit" :disabled="sending" class="w-full border-2 border-black bg-[#c0392b] text-white px-4 py-2 font-[Helvetica] font-bold text-sm disabled:opacity-50" x-text="sending ? 'Menyimpan...' : 'Kirim Laporan'"></button>
                </form>
            </div>
            <div x-show="tab === 'history'" aria-live="polite">
                <p x-show="loadingHistory" class="py-6 text-center text-sm">Memuat riwayat...</p>
                <p x-show="!loadingHistory && reports.length === 0" class="py-6 text-center text-sm text-gray-500">Belum ada laporan.</p>
                <template x-for="report in reports" :key="report.id"><div class="border-2 border-black mb-2 p-2 text-xs">
                    <div class="flex justify-between gap-2"><strong x-text="report.title"></strong><span x-text="report.status_label"></span></div>
                    <div class="mt-1 text-gray-600"><span x-text="report.type_label"></span> · <span x-text="report.module"></span> · <span x-text="report.created_at"></span></div>
                    <p x-show="report.admin_note" class="mt-2 bg-[#fff3b0] border border-black p-1" x-text="report.admin_note"></p>
                    <p x-show="report.resolved_at" class="mt-1" x-text="'Selesai: ' + report.resolved_at"></p>
                </div></template>
            </div>
        </div>
    </div>
    <button type="button" @click="open = !open; if (open && tab === 'history') loadHistory()" class="w-12 h-12 bg-[#c0392b] text-white border-2 border-black flex items-center justify-center shadow-lg" title="Laporan / Masukan" aria-label="Buka laporan dan masukan">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z"/></svg>
    </button>
</div>

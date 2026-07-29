@props(['commentableType', 'commentableId', 'initialCount' => 0, 'accent' => '#b3bd95'])

<section
    class="border-2 border-black bg-[#f5f0eb] font-['Times_New_Roman']"
    data-comments-panel
    data-commentable-type="{{ $commentableType }}"
    data-commentable-id="{{ $commentableId }}"
    x-data="commentsPanel(@js([
        'alias' => $commentableType,
        'id' => (int) $commentableId,
        'initialCount' => (int) $initialCount,
        'indexUrl' => route('comments.index'),
        'storeUrl' => route('comments.store'),
        'mentionUrl' => route('comments.mentionable-users'),
        'updateUrl' => route('comments.update', ['comment' => '__COMMENT__']),
        'deleteUrl' => route('comments.destroy', ['comment' => '__COMMENT__']),
        'historyUrl' => route('comments.history', ['comment' => '__COMMENT__']),
        'moderateUrl' => route('comments.moderate', ['comment' => '__COMMENT__']),
        'restoreUrl' => route('comments.restore', ['comment' => '__COMMENT__']),
    ]))"
>
    <header class="flex items-center justify-between border-b-2 border-black bg-black px-3 py-2 text-white">
        <h2 class="font-[Helvetica] text-xs font-bold uppercase">Komentar</h2>
        <span class="border border-white px-2 py-0.5 font-[Helvetica] text-[10px] font-bold" x-text="total + ' komentar'">{{ $initialCount }} komentar</span>
    </header>

    <div class="space-y-3 p-3 sm:p-4">
        <form x-show="canCreate" @submit.prevent="submitComment($event)" class="border-2 border-black bg-white p-3" data-comment-composer>
            <label class="mb-1 block font-[Helvetica] text-[10px] font-bold uppercase">Tulis Komentar</label>
            <div class="relative">
                <textarea name="body" x-model="body" maxlength="5000" rows="3" role="combobox" aria-autocomplete="list" :aria-expanded="mentionOpen && activeDraft === $data" class="w-full resize-y rounded-none border-2 border-black p-2 text-sm" placeholder="Tulis komentar..." @input="mentionInput($event, $data)" @keydown="mentionKey($event); submitShortcut($event, () => $el.form.requestSubmit())"></textarea>
                <div x-show="mentionOpen && activeDraft === $data" x-cloak role="listbox" class="absolute z-30 mt-[-2px] max-h-56 w-full overflow-y-auto border-2 border-black bg-white shadow-[4px_4px_0_#000]">
                    <template x-for="(user, index) in suggestions" :key="user.id"><button type="button" role="option" :aria-selected="index === mentionIndex" class="flex w-full items-center gap-2 border-b border-black p-2 text-left last:border-0" :class="index === mentionIndex ? 'bg-[#fff3b0]' : ''" @mousedown.prevent="selectMention(user)"><span class="flex h-7 w-7 shrink-0 items-center justify-center border border-black bg-gray-100 text-[10px] font-bold" x-text="user.initials"></span><span class="min-w-0"><strong class="block truncate text-xs" x-text="user.name"></strong><small class="block truncate" x-text="[user.role, user.context].filter(Boolean).join(' · ')"></small></span></button></template>
                </div>
            </div>
            <div x-show="canMention && mentions.length" class="mt-2 flex flex-wrap gap-1"><template x-for="user in mentions" :key="user.id"><span class="inline-flex items-center gap-1 border border-black bg-[#eef1ff] px-2 py-0.5 text-[10px]"><span x-text="'@' + user.name"></span><button type="button" class="font-bold" :aria-label="'Hapus mention ' + user.name" @click="removeMention($data, user.id)">&times;</button></span></template></div>
            <div class="mt-2 flex items-center justify-between gap-2"><span class="text-[10px]" :class="body.length >= 5000 ? 'font-bold text-[#c0392b]' : ''" x-text="body.length + '/5000'">0/5000</span><button :disabled="sending || !body.trim()" class="border-2 border-black px-3 py-1 font-[Helvetica] text-xs font-bold disabled:opacity-50" style="background-color: {{ $accent }}" x-text="sending ? 'Mengirim...' : 'Kirim'">Kirim</button></div>
            <p class="mt-1 text-[10px] text-gray-600">Ctrl/Cmd+Enter untuk mengirim.</p>
        </form>

        <div x-show="loading" class="border-2 border-black bg-white p-5 text-center text-sm italic">Memuat komentar...</div>
        <div x-show="loadError && !loading" class="border-2 border-black bg-[#fff3b0] p-3 text-sm"><span x-text="loadError"></span> <button type="button" class="font-bold text-[#0000ee] underline" @click="retryAction?.()">Coba Lagi</button></div>
        <button x-show="!loading && page < lastPage" :disabled="loadingOlder" type="button" class="w-full border-2 border-black bg-white px-3 py-2 text-xs font-bold disabled:opacity-50" @click="load(false)" x-text="loadingOlder ? 'Memuat komentar...' : 'Muat komentar sebelumnya'">Muat komentar sebelumnya</button>
        <p x-show="!loading && !loadError && comments.length === 0" class="border-2 border-black bg-white p-5 text-center text-sm italic">Belum ada komentar.</p>

        <div class="space-y-3">
            <template x-for="comment in comments" :key="comment.id">
                <article class="border-2 border-black bg-white" :id="'comment-' + comment.id">
                    <div class="flex gap-2 p-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center border-2 border-black font-[Helvetica] text-[10px] font-bold" style="background-color: {{ $accent }}" x-text="initials(comment.user)"></span>
                        <div class="min-w-0 grow">
                            <div class="flex flex-wrap items-baseline gap-x-2"><strong class="text-sm" x-text="comment.user?.name || 'Pengguna'">Pengguna</strong><time class="text-[10px] text-gray-500" x-text="formatDate(comment.created_at)"></time><span x-show="comment.is_edited" class="border border-black px-1 text-[8px] font-bold">DIEDIT</span></div>
                            <p x-show="editing?.id !== comment.id" class="mt-1 whitespace-pre-wrap break-words text-sm" :class="comment.is_deleted ? 'italic text-gray-500' : ''" x-text="comment.body"></p>

                            <form x-show="editing?.id === comment.id" @submit.prevent="saveEdit($event, comment)" class="mt-2 border-2 border-black bg-gray-50 p-2" data-comment-edit-form>
                                <input type="hidden" name="expected_lock_version" :value="editing?.lock_version">
                                <div class="relative"><textarea name="body" x-model="editing.body" maxlength="5000" rows="3" class="w-full border-2 border-black p-2 text-sm" @input="mentionInput($event, editing)" @keydown="mentionKey($event); submitShortcut($event, () => $el.form.requestSubmit())"></textarea>
                                    <div x-show="mentionOpen && activeDraft === editing" x-cloak class="absolute z-30 mt-[-2px] max-h-56 w-full overflow-y-auto border-2 border-black bg-white shadow-[4px_4px_0_#000]"><template x-for="(user, index) in suggestions" :key="user.id"><button type="button" class="block w-full border-b border-black p-2 text-left text-xs" :class="index === mentionIndex ? 'bg-[#fff3b0]' : ''" @mousedown.prevent="selectMention(user)"><strong x-text="user.name"></strong><span class="ml-1" x-text="[user.role, user.context].filter(Boolean).join(' · ')"></span></button></template></div>
                                </div>
                                <div x-show="canMention && editing?.mentions.length" class="mt-1 flex flex-wrap gap-1"><template x-for="user in editing?.mentions || []" :key="user.id"><span class="border border-black bg-[#eef1ff] px-1 text-[10px]"><span x-text="'@' + user.name"></span> <button type="button" @click="removeMention(editing, user.id)">&times;</button></span></template></div>
                                <div class="mt-2 flex items-center justify-between"><span class="text-[10px]" x-text="editing.body.length + '/5000'"></span><span class="flex gap-1"><button :disabled="sending" class="border-2 border-black px-2 py-1 text-xs font-bold" style="background-color: {{ $accent }}">Simpan</button><button type="button" class="border-2 border-black bg-white px-2 py-1 text-xs" @click="clearEdit()">Batal</button></span></div>
                            </form>

                            <div x-show="!comment.is_deleted && editing?.id !== comment.id" class="mt-2 flex flex-wrap gap-3 font-[Helvetica] text-[10px] font-bold">
                                <button x-show="comment.can_reply" type="button" class="text-[#0000ee] underline" @click="replyDraft(comment.id).open = !replyDraft(comment.id).open">Balas</button>
                                <button x-show="comment.can_update" type="button" class="text-[#0000ee] underline" @click="startEdit(comment)">Edit</button>
                                <button x-show="comment.can_delete" :disabled="deletingId === comment.id" type="button" class="text-[#c0392b] underline" @click="deleteComment(comment)">Hapus</button>
                                <button x-show="comment.can_view_history && comment.is_edited" type="button" class="underline" @click="showHistory(comment)">Riwayat</button>
                                <button x-show="comment.can_moderate" type="button" class="text-[#c0392b] underline" @click="openModeration(comment)">Sembunyikan</button>
                            </div>
                            <div x-show="comment.is_deleted && comment.can_restore" class="mt-2"><button type="button" :disabled="sending" class="font-[Helvetica] text-[10px] font-bold text-[#0000ee] underline" @click="restoreComment(comment)">Pulihkan</button></div>
                        </div>
                    </div>

                    <div x-show="comment.replies.length || replyDraft(comment.id).open" class="border-t-2 border-black bg-gray-50 px-3 pb-3 pl-8 sm:pl-12">
                        <template x-for="reply in comment.replies" :key="reply.id">
                            <div class="border-b border-black py-3" :id="'comment-' + reply.id">
                                <div class="flex gap-2"><span class="flex h-7 w-7 shrink-0 items-center justify-center border border-black text-[9px] font-bold" style="background-color: {{ $accent }}" x-text="initials(reply.user)"></span><div class="min-w-0 grow"><div><strong class="text-xs" x-text="reply.user?.name || 'Pengguna'"></strong> <time class="text-[9px] text-gray-500" x-text="formatDate(reply.created_at)"></time> <span x-show="reply.is_edited" class="border border-black px-1 text-[8px] font-bold">DIEDIT</span></div><p class="mt-1 whitespace-pre-wrap break-words text-sm" :class="reply.is_deleted ? 'italic text-gray-500' : ''" x-text="reply.body"></p><div x-show="!reply.is_deleted" class="mt-1 flex gap-3 text-[10px] font-bold"><button x-show="reply.can_update" type="button" class="text-[#0000ee] underline" @click="startEdit(reply)">Edit</button><button x-show="reply.can_delete" type="button" class="text-[#c0392b] underline" @click="deleteComment(reply)">Hapus</button><button x-show="reply.can_view_history && reply.is_edited" type="button" class="underline" @click="showHistory(reply)">Riwayat</button><button x-show="reply.can_moderate" type="button" class="text-[#c0392b] underline" @click="openModeration(reply)">Sembunyikan</button></div><div x-show="reply.is_deleted && reply.can_restore" class="mt-1"><button type="button" :disabled="sending" class="text-[10px] font-bold text-[#0000ee] underline" @click="restoreComment(reply)">Pulihkan</button></div></div></div>
                            </div>
                        </template>
                        <form x-show="replyDraft(comment.id).open && comment.can_reply" @submit.prevent="submitComment($event, comment)" class="relative mt-3" data-comment-reply-form>
                            <textarea name="body" x-model="replyDraft(comment.id).body" maxlength="5000" rows="2" class="w-full border-2 border-black bg-white p-2 text-sm" placeholder="Tulis balasan..." @input="mentionInput($event, replyDraft(comment.id))" @keydown="mentionKey($event); submitShortcut($event, () => $el.form.requestSubmit())"></textarea>
                            <div x-show="mentionOpen && activeDraft === replyDraft(comment.id)" x-cloak class="absolute z-30 mt-[-2px] max-h-56 w-full overflow-y-auto border-2 border-black bg-white shadow-[4px_4px_0_#000]"><template x-for="(user, index) in suggestions" :key="user.id"><button type="button" class="block w-full border-b border-black p-2 text-left text-xs" :class="index === mentionIndex ? 'bg-[#fff3b0]' : ''" @mousedown.prevent="selectMention(user)"><strong x-text="user.name"></strong><span class="ml-1" x-text="[user.role, user.context].filter(Boolean).join(' · ')"></span></button></template></div>
                            <div x-show="canMention && replyDraft(comment.id).mentions.length" class="mt-1 flex flex-wrap gap-1"><template x-for="user in replyDraft(comment.id).mentions" :key="user.id"><span class="border border-black bg-[#eef1ff] px-1 text-[10px]"><span x-text="'@' + user.name"></span> <button type="button" @click="removeMention(replyDraft(comment.id), user.id)">&times;</button></span></template></div>
                            <div class="mt-1 flex items-center justify-between"><span class="text-[10px]" x-text="replyDraft(comment.id).body.length + '/5000'"></span><span class="flex gap-1"><button :disabled="sending || !replyDraft(comment.id).body.trim()" class="border-2 border-black px-2 py-1 text-xs font-bold disabled:opacity-50" style="background-color: {{ $accent }}">Balas</button><button type="button" class="border-2 border-black bg-white px-2 py-1 text-xs" @click="replyDraft(comment.id).open=false">Batal</button></span></div>
                        </form>
                    </div>
                </article>
            </template>
        </div>

        <form x-show="editing.id && !comments.some(comment => comment.id === editing.id)" x-cloak @submit.prevent="saveEdit($event, comments.flatMap(comment => comment.replies).find(reply => reply.id === editing.id))" class="border-2 border-black bg-white p-3" data-comment-edit-form>
            <input type="hidden" name="expected_lock_version" :value="editing?.lock_version">
            <label class="mb-1 block text-xs font-bold uppercase">Edit Balasan</label>
            <textarea name="body" x-model="editing.body" maxlength="5000" rows="3" class="w-full border-2 border-black p-2 text-sm" @input="mentionInput($event, editing)" @keydown="mentionKey($event); submitShortcut($event, () => $el.form.requestSubmit())"></textarea>
            <div x-show="mentionOpen && activeDraft === editing" x-cloak class="max-h-56 overflow-y-auto border-2 border-t-0 border-black bg-white"><template x-for="(user, index) in suggestions" :key="user.id"><button type="button" class="block w-full border-b border-black p-2 text-left text-xs" :class="index === mentionIndex ? 'bg-[#fff3b0]' : ''" @mousedown.prevent="selectMention(user)"><strong x-text="user.name"></strong><span class="ml-1" x-text="[user.role, user.context].filter(Boolean).join(' · ')"></span></button></template></div>
            <div x-show="canMention && editing?.mentions.length" class="mt-1 flex flex-wrap gap-1"><template x-for="user in editing?.mentions || []" :key="user.id"><span class="border border-black bg-[#eef1ff] px-1 text-[10px]"><span x-text="'@' + user.name"></span> <button type="button" @click="removeMention(editing, user.id)">&times;</button></span></template></div>
            <div class="mt-2 flex items-center justify-between"><span class="text-[10px]" x-text="editing.body.length + '/5000'"></span><span class="flex gap-1"><button :disabled="sending" class="border-2 border-black px-2 py-1 text-xs font-bold" style="background-color: {{ $accent }}">Simpan</button><button type="button" class="border-2 border-black bg-white px-2 py-1 text-xs" @click="clearEdit()">Batal</button></span></div>
        </form>

        <div x-show="retryAction && !loadError" class="border-2 border-black bg-[#fff3b0] p-2 text-sm">Koneksi bermasalah. <button type="button" class="font-bold text-[#0000ee] underline" @click="retryAction()">Coba Lagi</button></div>
    </div>

    <div x-show="historyOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="comment-history-title" class="fixed inset-0 z-[850] flex items-center justify-center bg-black/70 p-4" @keydown.escape.window="closeHistory()" @keydown.tab="trapFocus($event, $refs.historyDialog)"><div x-ref="historyDialog" class="max-h-[80vh] w-full max-w-lg overflow-y-auto border-2 border-black bg-white shadow-[6px_6px_0_#000]" @click.outside="closeHistory()"><header class="flex justify-between border-b-2 border-black bg-black px-3 py-2 text-white"><strong id="comment-history-title" class="text-xs uppercase">Riwayat Komentar</strong><button type="button" aria-label="Tutup riwayat komentar" @click="closeHistory()">&times;</button></header><div class="space-y-2 p-3"><p x-show="historyLoading" class="italic">Memuat riwayat...</p><p x-show="!historyLoading && !history.length" class="italic">Belum ada riwayat perubahan.</p><template x-for="revision in history" :key="revision.id"><article class="border-2 border-black p-2"><p class="whitespace-pre-wrap break-words text-sm" x-text="revision.previous_body"></p><small x-text="(revision.edited_by?.name || 'Pengguna') + ' · ' + formatDate(revision.created_at)"></small></article></template></div></div></div>

    <div x-show="moderation" x-cloak role="dialog" aria-modal="true" aria-labelledby="comment-moderation-title" class="fixed inset-0 z-[850] flex items-center justify-center bg-black/70 p-4" @keydown.escape.window="closeModeration()" @keydown.tab="trapFocus($event, $refs.moderationDialog)"><form x-ref="moderationDialog" @submit.prevent="hideComment($event)" class="w-full max-w-md border-2 border-black bg-white shadow-[6px_6px_0_#000]" @click.outside="closeModeration()" data-comment-moderation-form><header id="comment-moderation-title" class="border-b-2 border-black bg-[#c0392b] px-3 py-2 text-xs font-bold uppercase text-white">Sembunyikan Komentar</header><div class="p-3"><label class="mb-1 block text-xs font-bold uppercase">Alasan Moderasi</label><textarea name="reason" x-model="moderation.reason" maxlength="1000" rows="4" required class="w-full border-2 border-black p-2"></textarea><div class="mt-2 flex justify-end gap-2"><button type="button" class="border-2 border-black bg-white px-3 py-1 text-xs font-bold" @click="closeModeration()">Batal</button><button :disabled="sending || !moderation?.reason.trim()" class="border-2 border-black bg-[#c0392b] px-3 py-1 text-xs font-bold text-white disabled:opacity-50">Sembunyikan</button></div></div></form></div>
</section>

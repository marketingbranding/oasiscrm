<x-guest-layout>
    <div class="border-b-2 border-black pb-4">
        <p class="font-bold uppercase tracking-widest text-[#e91d2a]">OASIS CRM</p>
        <h1 class="mt-2 text-2xl font-black">Aktivasi Akun</h1>
    </div>

    @if($state === 'valid')
        <p class="mt-5 text-sm">Undangan ini ditujukan untuk:</p>
        <p class="mt-1 border-2 border-black bg-[#fcc20f] px-3 py-2 font-bold">{{ $invitation->user->email }}</p>
        <p class="mt-3 text-sm">Buat kata sandi untuk mengaktifkan akun. Tautan berlaku sampai {{ $invitation->expires_at->format('d M Y H:i') }}.</p>

        @error('invitation')
            <p class="mt-4 border-2 border-black bg-red-100 px-3 py-2 text-sm font-bold text-red-800">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('invitations.store', ['token' => $token]) }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <x-input-label for="password" value="Kata Sandi" />
                <x-text-input id="password" class="mt-1 block w-full rounded-none border-2 border-black" type="password" name="password" required autofocus autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="password_confirmation" value="Konfirmasi Kata Sandi" />
                <x-text-input id="password_confirmation" class="mt-1 block w-full rounded-none border-2 border-black" type="password" name="password_confirmation" required autocomplete="new-password" />
            </div>
            <button class="w-full border-2 border-black bg-black px-4 py-3 font-bold text-white hover:bg-gray-800">Aktifkan Akun</button>
        </form>
    @else
        @php
            $messages = [
                'accepted' => 'Undangan ini sudah digunakan. Silakan masuk dengan akun OASIS Anda.',
                'revoked' => 'Undangan ini sudah dicabut. Hubungi administrator OASIS jika Anda memerlukan akses.',
                'expired' => 'Undangan ini sudah kedaluwarsa. Minta administrator mengirim undangan baru.',
                'superseded' => 'Tautan ini sudah digantikan oleh undangan yang lebih baru. Gunakan email undangan terbaru.',
                'invalid' => 'Tautan undangan tidak valid. Periksa kembali tautan dari email OASIS Anda.',
            ];
        @endphp
        <p class="mt-5 border-2 border-black bg-gray-100 px-4 py-4">{{ $messages[$state] }}</p>
        <a href="{{ route('login') }}" class="mt-5 inline-block font-bold text-[#0000ee] underline">Kembali ke halaman masuk</a>
    @endif
</x-guest-layout>

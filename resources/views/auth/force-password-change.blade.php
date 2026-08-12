<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.pwa-head')
    <title>Ganti Password - Oasis CRM</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-['Times_New_Roman'] antialiased bg-white">
    <div class="border-2 border-black m-1 sm:m-2 min-h-screen flex flex-col">
        <div class="bg-black text-white font-[Helvetica] font-bold text-sm sm:text-base px-4 py-3 flex items-center">
            <span class="text-[#fcc20f] text-lg mr-2">◆</span>
            <span>OASIS CRM</span>
        </div>

        <div class="flex-1 flex items-center justify-center p-4">
            <div class="w-full max-w-md border-2 border-black bg-white">
                <div class="bg-[#e91d2a] text-white px-4 py-3">
                    <h1 class="font-['Arial_Black'] font-black text-lg uppercase">Ganti Password</h1>
                </div>
                <div class="p-6">
                    <p class="text-sm font-['Times_New_Roman'] mb-4">
                        Anda menggunakan password sementara. Buat password baru untuk melanjutkan.
                    </p>

                    <form method="POST" action="{{ route('password.change.update') }}" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div>
                            <label for="password" class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Password Baru</label>
                            <x-password-input id="password" name="password" required autocomplete="new-password"
                                class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('password') border-[#e91d2a] @enderror" />
                            @error('password')
                                <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password_confirmation" class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Konfirmasi Password Baru</label>
                            <x-password-input id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                                class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none" />
                        </div>

                        <div class="flex items-center justify-between pt-2">
                            <button type="submit" class="bg-black text-white px-8 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                                Simpan
                            </button>
                            <a href="{{ route('logout') }}"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                               class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">
                                Logout
                            </a>
                        </div>
                    </form>
                    <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">
                        @csrf
                    </form>
                </div>
            </div>
        </div>

        <div class="border-t-2 border-black bg-white px-4 py-3 text-center text-xs font-['Times_New_Roman']">
            © {{ date('Y') }} Oasis CRM — Sistem Manajemen Konten Perumahan
        </div>
    </div>
    <x-pwa-control />
</body>
</html>

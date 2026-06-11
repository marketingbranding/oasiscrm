<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login - Oasis CRM</title>
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
                    <h1 class="font-['Arial_Black'] font-black text-lg uppercase">Silakan Login</h1>
                </div>
                <div class="p-6">
                    @if(session('status'))
                    <div class="bg-[#b3bd95] border-2 border-black px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm">
                        {{ session('status') }}
                    </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" class="space-y-4">
                        @csrf

                        <div>
                            <label for="email" class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Email</label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                                class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('email') border-[#e91d2a] @enderror">
                            @error('email')
                                <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Password</label>
                            <input id="password" type="password" name="password" required autocomplete="current-password"
                                class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('password') border-[#e91d2a] @enderror">
                            @error('password')
                                <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="remember" class="border-2 border-black rounded-none">
                                <span class="text-xs font-['Times_New_Roman']">Ingat saya</span>
                            </label>
                        </div>

                        <div class="flex items-center justify-between pt-2">
                            <button type="submit" class="bg-black text-white px-8 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                                Login
                            </button>
                            @if(Route::has('password.request'))
                            <a href="{{ route('password.request') }}" class="text-[#0000ee] underline text-xs font-[Helvetica] font-bold">
                                Lupa password?
                            </a>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="border-t-2 border-black bg-white px-4 py-3 text-center text-xs font-['Times_New_Roman']">
            © {{ date('Y') }} Oasis CRM — Sistem Manajemen Konten Perumahan
        </div>
    </div>
</body>
</html>

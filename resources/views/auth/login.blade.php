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
    <style>
        .login-scene::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                repeating-linear-gradient(to bottom, rgba(0,0,0,0.08) 0, rgba(0,0,0,0.08) 1px, transparent 1px, transparent 5px),
                radial-gradient(circle at 28% 24%, rgba(252,194,15,0.28), transparent 24%),
                linear-gradient(135deg, rgba(230,145,93,0.22), rgba(179,189,149,0.14));
            mix-blend-mode: multiply;
        }

        .login-scene canvas {
            display: block;
        }
    </style>
</head>
<body class="font-['Times_New_Roman'] antialiased bg-white">
    <div class="border-2 border-black m-1 sm:m-2 min-h-screen flex flex-col">
        <div class="bg-black text-white font-[Helvetica] font-bold text-sm sm:text-base px-4 py-3 flex items-center">
            <span class="text-[#fcc20f] text-lg mr-2">◆</span>
            <span>OASIS CRM</span>
        </div>

        <div class="flex-1 grid min-h-0 grid-cols-1 md:grid-cols-[minmax(0,1fr)_360px] bg-[#f4d28b] overflow-hidden">
            <div class="login-scene relative hidden h-full min-h-0 md:block border-r-2 border-black bg-[#f4d28b]" data-login-voxel>
                <button type="button" data-login-voxel-toggle disabled
                        class="absolute left-1/2 bottom-6 z-10 -translate-x-1/2 border-2 border-black bg-black px-8 py-3 font-[Helvetica] text-xs font-black uppercase tracking-[0.22em] text-white shadow-[5px_5px_0_0_#000] transition hover:bg-[#e91d2a] disabled:cursor-wait disabled:opacity-70">
                    BREAK
                </button>
            </div>

            <div class="flex items-center justify-center bg-[#f4d28b] p-6 md:p-8">
                <div class="w-full max-w-xs">
                    <div class="mb-6 border-b-2 border-black pb-4">
                        <h1 class="font-[Helvetica] text-4xl font-black leading-none tracking-[-0.06em] text-black">OASIS</h1>
                        <p class="mt-2 font-['Times_New_Roman'] text-sm leading-tight text-black">
                            Operational Administration System for Integrated Support
                        </p>
                    </div>

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
    <script type="module" src="{{ asset('js/login-voxel.js') }}"></script>
</body>
</html>

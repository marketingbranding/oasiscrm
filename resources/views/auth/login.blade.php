<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.pwa-head')
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

        .login-auth-overlay {
            opacity: 0;
            pointer-events: none;
            transform: translateY(10px);
            transition: opacity 180ms ease, transform 180ms ease;
        }

        .login-auth-overlay.is-active {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
        }

        .login-auth-overlay::before {
            content: "";
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(to bottom, rgba(255,255,255,0.08) 0, rgba(255,255,255,0.08) 1px, transparent 1px, transparent 5px);
            animation: login-scanline 520ms linear infinite;
        }

        .login-progress-bar {
            width: 8%;
            transition: width 120ms linear;
        }

        .login-auth-form.is-submitting {
            opacity: 0.58;
            pointer-events: none;
        }

        @keyframes login-scanline {
            from { transform: translateY(-5px); }
            to { transform: translateY(5px); }
        }

    </style>
</head>
<body class="font-['Times_New_Roman'] antialiased bg-white">
    <div class="m-1 flex h-[calc(100dvh-0.5rem)] flex-col border-2 border-black sm:m-2 sm:h-[calc(100dvh-1rem)]">
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

            <div class="flex min-h-0 items-center justify-center overflow-y-auto bg-[#f4d28b] p-6 md:p-8">
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

                    <form method="POST" action="{{ route('login') }}" class="login-auth-form space-y-4" data-login-auth-form>
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
                            <button type="submit" data-login-auth-button class="bg-black text-white px-8 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800 disabled:cursor-wait disabled:bg-[#e91d2a]">
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
    <div data-login-auth-overlay aria-hidden="true" class="login-auth-overlay fixed inset-0 z-50 flex items-center justify-center bg-black/88 px-5">
        <div class="relative w-full max-w-sm border-2 border-white bg-black p-5 text-white shadow-[8px_8px_0_0_#e91d2a]">
            <div class="mb-4 font-[Helvetica] text-xs font-black uppercase tracking-[0.28em] text-[#fcc20f]">OASIS</div>
            <div class="font-[Helvetica] text-lg font-black uppercase leading-none">Accessing System</div>
            <div class="mt-2 font-['Times_New_Roman'] text-sm">Initializing support environment...</div>
            <div class="mt-5 h-5 border-2 border-white bg-black p-1">
                <div data-login-progress class="login-progress-bar h-full bg-[#fcc20f]"></div>
            </div>
            <div class="mt-3 flex items-center justify-between font-[Helvetica] text-[10px] font-bold uppercase tracking-[0.2em] text-white/80">
                <span>Please wait</span>
                <span data-login-progress-label>8%</span>
            </div>
        </div>
    </div>
    <script type="module" src="{{ asset('js/login-voxel.js') }}"></script>
    <script>
        (() => {
            const form = document.querySelector('[data-login-auth-form]');
            const button = document.querySelector('[data-login-auth-button]');
            const overlay = document.querySelector('[data-login-auth-overlay]');
            const progress = document.querySelector('[data-login-progress]');
            const progressLabel = document.querySelector('[data-login-progress-label]');

            if (!form || !button || !overlay) return;

            const setProgress = (value) => {
                const percent = Math.max(8, Math.min(100, Math.round(value)));
                if (progress) progress.style.width = `${percent}%`;
                if (progressLabel) progressLabel.textContent = `${percent}%`;
            };

            const animateProgress = (duration) => {
                const startedAt = performance.now();

                const tick = (now) => {
                    const elapsed = now - startedAt;
                    const ratio = Math.min(elapsed / duration, 1);
                    const eased = 1 - Math.pow(1 - ratio, 2.35);
                    const target = ratio < 0.72 ? 8 + eased * 70 : 78 + ((ratio - 0.72) / 0.28) * 17;

                    setProgress(target);
                    if (ratio < 1 && form.dataset.submitting === 'true') requestAnimationFrame(tick);
                };

                requestAnimationFrame(tick);
            };

            form.addEventListener('submit', (event) => {
                if (form.dataset.submitting === 'true') return;
                if (!form.checkValidity()) return;

                const submitDelay = 1100;

                event.preventDefault();
                form.dataset.submitting = 'true';
                form.classList.add('is-submitting');
                button.disabled = true;
                button.textContent = 'AUTHENTICATING...';
                overlay.classList.add('is-active');
                overlay.setAttribute('aria-hidden', 'false');
                setProgress(8);
                animateProgress(submitDelay);

                window.setTimeout(() => {
                    setProgress(100);
                    window.setTimeout(() => HTMLFormElement.prototype.submit.call(form), 120);
                }, submitDelay);
            });
        })();
    </script>
    <x-pwa-control />
</body>
</html>

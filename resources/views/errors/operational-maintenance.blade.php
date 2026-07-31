<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#000000">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <title>{{ $title }} | OASIS</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Helvetica, Arial, sans-serif;
            background: #f1efe7;
            color: #111;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-width: 0;
            min-height: 100%;
            margin: 0;
            overflow-x: hidden;
        }

        body {
            display: grid;
            min-height: 100vh;
            min-height: 100svh;
            place-items: center;
            padding: 24px;
            background:
                linear-gradient(#d6d1c4 1px, transparent 1px),
                linear-gradient(90deg, #d6d1c4 1px, transparent 1px),
                #f1efe7;
            background-size: 24px 24px;
        }

        main {
            width: min(100%, 680px);
            border: 3px solid #111;
            background: #fffdf6;
            box-shadow: 10px 10px 0 #111;
        }

        .masthead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 18px;
            border-bottom: 3px solid #111;
            background: #fcc20f;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .diamond {
            width: 14px;
            height: 14px;
            flex: 0 0 auto;
            border: 2px solid #111;
            background: #fcc20f;
            transform: rotate(45deg);
        }

        .status {
            white-space: nowrap;
        }

        .content {
            padding: clamp(24px, 6vw, 48px);
        }

        h1 {
            max-width: 18ch;
            margin: 0 0 18px;
            overflow-wrap: anywhere;
            font-family: "Arial Black", Helvetica, Arial, sans-serif;
            font-size: clamp(1.75rem, 7vw, 3.25rem);
            line-height: 0.98;
            letter-spacing: -0.035em;
        }

        .message {
            margin: 0;
            overflow-wrap: anywhere;
            font-family: "Times New Roman", Times, serif;
            font-size: clamp(1.08rem, 3vw, 1.3rem);
            line-height: 1.55;
            white-space: pre-line;
        }

        .estimate {
            margin: 28px 0 0;
            padding: 14px 16px;
            border-left: 6px solid #fcc20f;
            background: #f4f1e7;
            line-height: 1.45;
        }

        .estimate strong {
            display: block;
            margin-bottom: 4px;
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .guidance {
            margin: 28px 0 0;
            color: #3f3f3f;
            line-height: 1.5;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
        }

        .button {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border: 2px solid #111;
            border-radius: 0;
            background: #fcc20f;
            color: #111;
            font: inherit;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }

        .button-secondary {
            background: #fff;
        }

        .button:hover {
            background: #111;
            color: #fff;
        }

        .button:focus-visible {
            outline: 4px solid #0000ee;
            outline-offset: 3px;
        }

        @media (max-width: 480px) {
            body {
                padding: 16px;
            }

            main {
                box-shadow: 6px 6px 0 #111;
            }

            .masthead {
                align-items: flex-start;
                flex-direction: column;
                gap: 6px;
            }

            .actions,
            .button,
            .actions form {
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>
<body>
    <main aria-labelledby="maintenance-title">
        <header class="masthead">
            <span class="brand"><span class="diamond" aria-hidden="true"></span>OASIS / Sistem Operasional</span>
            <span class="status">HTTP 503</span>
        </header>

        <section class="content">
            <h1 id="maintenance-title">{{ $title }}</h1>
            <p class="message">{{ $message }}</p>

            @if ($estimatedEndAt)
                <p class="estimate">
                    <strong>Perkiraan selesai</strong>
                    <time datetime="{{ $estimatedEndAt }}">{{ $estimatedEndLabel }}</time>
                </p>
            @endif

            <p class="guidance">Silakan coba lagi setelah beberapa saat. Jika waktu perkiraan tersedia, pemeliharaan dapat selesai lebih cepat atau lebih lambat sesuai kondisi operasional.</p>

            <div class="actions" aria-label="Tindakan">
                <a class="button" href="{{ url('/') }}">Periksa kembali OASIS</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button button-secondary" type="submit">Keluar dari OASIS</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>

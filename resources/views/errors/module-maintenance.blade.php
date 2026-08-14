<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $moduleLabel }} dalam Pemeliharaan | OASIS</title>
    <style>
        * { box-sizing: border-box; }
        body { display: grid; min-height: 100vh; margin: 0; padding: 24px; place-items: center; background: #f1efe7; color: #111; font-family: Helvetica, Arial, sans-serif; }
        main { width: min(100%, 680px); border: 3px solid #111; background: #fffdf6; box-shadow: 10px 10px 0 #111; }
        header { padding: 14px 18px; border-bottom: 3px solid #111; background: #fcc20f; font-weight: 800; text-transform: uppercase; }
        section { padding: clamp(24px, 6vw, 48px); }
        h1 { margin: 0 0 18px; font-family: "Arial Black", Helvetica, sans-serif; font-size: clamp(1.75rem, 7vw, 3.25rem); line-height: 1; }
        .message { font: 1.2rem/1.55 "Times New Roman", Times, serif; white-space: pre-line; }
        .estimate { margin-top: 28px; padding: 14px 16px; border-left: 6px solid #fcc20f; background: #f4f1e7; }
        a { display: inline-flex; min-height: 44px; margin-top: 24px; align-items: center; padding: 10px 16px; border: 2px solid #111; background: #fcc20f; color: #111; font-weight: 800; text-decoration: none; }
        a:focus-visible { outline: 4px solid #0000ee; outline-offset: 3px; }
    </style>
</head>
<body>
    <main aria-labelledby="maintenance-title">
        <header>OASIS / HTTP 503</header>
        <section>
            <h1 id="maintenance-title">{{ $moduleLabel }} sedang dalam pemeliharaan</h1>
            <p class="message">{{ $message }}</p>
            @if ($estimatedEndAt)
                <p class="estimate"><strong>Perkiraan selesai:</strong> <time datetime="{{ $estimatedEndAt }}">{{ $estimatedEndLabel }}</time></p>
            @endif
            <a href="{{ url('/') }}">Kembali ke OASIS</a>
        </section>
    </main>
</body>
</html>

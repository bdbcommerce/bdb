<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 — Server Error | BDB Auto ID</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #fff; color: #111827; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem; }
        .wrap { text-align: center; max-width: 480px; }
        .logo { height: 48px; margin: 0 auto 2rem; }
        .code { font-size: 6rem; font-weight: 800; color: var(--site-link, #dc2626); line-height: 1; }
        h1 { font-size: 1.5rem; font-weight: 700; color: #111827; margin: 0.75rem 0 0.5rem; }
        p { color: #6b7280; font-size: 0.95rem; line-height: 1.6; margin-bottom: 2rem; }
        a { display: inline-block; background: var(--site-btn-bg, #dc2626); color: var(--site-btn-text, #fff); font-size: 0.875rem; font-weight: 600; padding: 0.75rem 1.75rem; border-radius: 0.5rem; text-decoration: none; }
        a:hover { background: var(--site-btn-hover, #b91c1c); }
    </style>
    @php $__t = \App\Models\ColorTheme::active(); @endphp
    @if($__t && ! $__t->is_locked)<style>:root{--site-link:var(--color-brand-600);--site-btn-bg:var(--color-brand-600);--site-btn-hover:var(--color-brand-700);--site-btn-text:#fff}{!! $__t->css() !!}</style>@endif
</head>
<body>
<div class="wrap">
    <img src="/images/logo.jpg" alt="BDB" class="logo">
    <div class="code">500</div>
    <h1>Something went wrong</h1>
    <p>We're working to fix the problem. Please try again in a few moments.</p>
    <a href="/uz">Back to home</a>
</div>
</body>
</html>

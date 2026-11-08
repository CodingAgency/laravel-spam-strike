<!DOCTYPE html>
<html lang="{{ $language ?: 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }}</title>
    <style>
        html, body { margin: 0; background: transparent; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .spam-strike-notice { padding: 16px 20px; border: 1px solid rgba(127, 127, 127, 0.35); border-radius: 10px; font-size: 15px; line-height: 1.6; color: CanvasText; }
    </style>
    @includeIf('vendor.spam-strike.noscript-styles')
</head>
<body>
    @if ($parts !== [])
        <div class="spam-strike-notice" role="alert">
            {{ $parts[0] }}@if (count($parts) > 1)<a href="mailto:{{ $email }}" target="_top">{{ $email }}</a>{{ $parts[1] }}@endif
        </div>
    @endif
</body>
</html>

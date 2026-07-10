<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Join a Kadi game</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0b6b3a;
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: rgba(0, 0, 0, 0.22);
            border-radius: 20px;
            padding: 32px 28px;
            width: 100%;
            max-width: 360px;
            text-align: center;
        }
        .logo { font-size: 52px; line-height: 1; margin-bottom: 12px; }
        h1 { font-size: 22px; font-weight: 800; margin-bottom: 6px; }
        .sub { color: rgba(255, 255, 255, 0.7); font-size: 14px; margin-bottom: 22px; }
        .code-label { color: rgba(255, 255, 255, 0.7); font-size: 13px; }
        .code {
            font-size: 40px;
            font-weight: 900;
            letter-spacing: 8px;
            color: #f5c542;
            margin: 4px 0 24px;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 15px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 800;
            text-decoration: none;
            margin-bottom: 12px;
        }
        .btn-primary { background: #f5c542; color: #0b3d20; }
        .btn-ghost { background: rgba(255, 255, 255, 0.12); color: #fff; }
        .hint { color: rgba(255, 255, 255, 0.6); font-size: 12px; margin-top: 8px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">🃏</div>
        <h1>Join a Kadi game</h1>
        <p class="sub">You've been invited to play!</p>

        <div class="code-label">Room code</div>
        <div class="code">{{ $code }}</div>

        <a class="btn btn-primary" href="kadi://join/{{ $code }}">Open in Kadi</a>
        <a class="btn btn-ghost" href="{{ url('/download') }}">Download the app</a>

        <p class="hint">
            Have the app? Tap “Open in Kadi”. New here? Download it, install, then
            open this link again — or just enter code <strong>{{ $code }}</strong> in the app.
        </p>
    </div>
</body>
</html>

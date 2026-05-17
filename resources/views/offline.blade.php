<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Offline Â· CIHRMS Ghana</title>
<style>
    :root {
        --brand-navy: #0d1452;
        --brand-blue: #1a237e;
        --paper: #f4f6f9;
        --muted: #475569;
    }
    * { box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; height: 100%;
        font-family: 'Open Sans', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        background: var(--paper);
        color: var(--brand-navy);
        -webkit-font-smoothing: antialiased;
    }
    .wrap {
        min-height: 100%;
        display: grid;
        place-items: center;
        padding: 2rem;
    }
    .card {
        max-width: 460px;
        width: 100%;
        background: #fff;
        border: 1px solid rgba(10,31,92,0.10);
        border-radius: 16px;
        padding: 2.5rem 2rem;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
    }
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.4rem 0.85rem;
        border-radius: 999px;
        background: rgba(217, 119, 6, 0.10);
        color: #b45309;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        margin-bottom: 1.25rem;
    }
    h1 { font-size: 1.6rem; line-height: 1.2; margin: 0 0 0.5rem; font-weight: 800; letter-spacing: -0.02em; }
    p  { color: var(--muted); font-size: 14.5px; line-height: 1.55; margin: 0 0 1.5rem; }
    button {
        appearance: none;
        background: var(--brand-navy);
        color: #fff;
        border: none;
        padding: 0.85rem 1.6rem;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.02em;
        cursor: pointer;
        transition: transform 0.15s, background 0.15s;
    }
    button:hover  { background: var(--brand-blue); transform: translateY(-1px); }
    .hint {
        margin-top: 1.25rem;
        font-size: 12px;
        color: var(--muted);
        opacity: 0.75;
    }
    .pulse {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: #d97706;
        display: inline-block;
        animation: pulse 1.6s ease-in-out infinite;
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%      { opacity: 0.3; transform: scale(0.7); }
    }
</style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <span class="badge"><span class="pulse"></span> Offline</span>
            <h1>You're offline.</h1>
            <p>
                Your changes are saved on this device. Clock-in punches and other queued actions
                will sync automatically when your connection returns.
            </p>
            <button type="button" onclick="location.reload()">Try again</button>
            <p class="hint">No data is lost. CIHRMS keeps your queue locally until the network returns.</p>
        </div>
    </div>
    <script>
        // Auto-retry navigation as soon as connectivity returns.
        window.addEventListener('online', () => location.reload());
    </script>
</body>
</html>

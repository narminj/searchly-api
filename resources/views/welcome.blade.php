<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔍</text></svg>">
    <title>Searchly API</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f0f0f;
            color: #fff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .hero {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            width: 700px;
            height: 700px;
            background: radial-gradient(circle, #0891B21e 0%, transparent 70%);
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
        }
        .hero-content {
            text-align: center;
            position: relative;
            z-index: 1;
            max-width: 680px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #0891B21e;
            border: 1px solid #0891B24d;
            color: #22D3EE;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 5px 14px;
            border-radius: 100px;
            margin-bottom: 1.5rem;
        }
        .badge::before {
            content: '';
            width: 6px; height: 6px;
            background: #0891B2;
            border-radius: 50%;
            box-shadow: 0 0 8px #0891B2;
            animation: blink 2s infinite;
        }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        h1 {
            font-size: clamp(2.4rem, 6vw, 3.8rem);
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.02em;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #fff 0%, #22D3EE 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .subtitle {
            font-size: 1.05rem;
            color: #9ca3af;
            line-height: 1.7;
            margin-bottom: 2.5rem;
            max-width: 520px;
            margin-left: auto;
            margin-right: auto;
        }
        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #0891B2;
            color: #fff;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
            box-shadow: 0 4px 20px #0891B259;
        }
        .btn-primary:hover {
            background: #0E7490;
            transform: translateY(-2px);
            box-shadow: 0 8px 30px #0891B280;
        }
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            color: #9ca3af;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            border: 1px solid #262626;
            transition: all 0.2s;
        }
        .btn-secondary:hover { border-color: #3f3f3f; color: #e5e7eb; transform: translateY(-2px); }
        .meta {
            display: flex;
            justify-content: center;
            gap: 2.5rem;
            margin-top: 3.5rem;
            padding-top: 2.5rem;
            border-top: 1px solid #1e1e1e;
        }
        .meta-item { text-align: center; }
        .meta-value { font-size: 1.25rem; font-weight: 700; color: #22D3EE; }
        .meta-label { font-size: 0.72rem; color: #6b7280; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.06em; }
        footer { text-align: center; padding: 1.25rem; color: #4b5563; font-size: 0.78rem; border-top: 1px solid #1a1a1a; }
        footer a { color: #6b7280; text-decoration: none; }
        footer a:hover { color: #22D3EE; }
    </style>
</head>
<body>
    <section class="hero">
        <div class="hero-content">
            <div class="badge">REST API &nbsp;·&nbsp; v1.0</div>
            <h1>Searchly API</h1>
            <p class="subtitle">Elasticsearch əsaslı məhsul axtarış mühərrikinin REST API-si. Tam mətn axtarışı, filtr, sıralama, aqreqasiya və avtotamamlama dəstəyi.</p>
            <div class="actions">
                <a href="/api/documentation" class="btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                    API Documentation
                </a>
                <a href="https://searchly.narmin.dev" class="btn-secondary" target="_blank" rel="noopener">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                    Website
                </a>
            </div>
            <div class="meta">
                <div class="meta-item"><div class="meta-value">REST</div><div class="meta-label">Architecture</div></div>
                <div class="meta-item"><div class="meta-value">JWT</div><div class="meta-label">Auth</div></div>
                <div class="meta-item"><div class="meta-value">JSON</div><div class="meta-label">Format</div></div>
                <div class="meta-item"><div class="meta-value">v1</div><div class="meta-label">Version</div></div>
            </div>
        </div>
    </section>
    <footer>
        <a href="/api/documentation">Swagger Docs</a>
        &nbsp;·&nbsp; Laravel &nbsp;·&nbsp;
        <a href="https://searchly.narmin.dev" target="_blank">searchly.narmin.dev</a>
    </footer>
</body>
</html>

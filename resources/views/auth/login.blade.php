<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Searchly — Sign in</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #1c5fb8 0%, #2563eb 55%, #1e3a8a 100%); color: #1f2933;
        }
        .card {
            background: #fff; width: 100%; max-width: 380px; border-radius: 16px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.25); padding: 36px 34px; margin: 20px;
        }
        .brand { display: flex; align-items: center; gap: 11px; margin-bottom: 6px; }
        .brand-logo { width: 40px; height: 40px; background: #1c5fb8; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
        .brand-name { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #1f2933; }
        .subtitle { color: #6b7280; font-size: 13.5px; margin: 0 0 24px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 6px; color: #374151; }
        input[type=email], input[type=password] {
            width: 100%; padding: 11px 13px; border: 1px solid #d1d5db; border-radius: 9px; font-size: 15px;
            transition: border-color .15s, box-shadow .15s;
        }
        input[type=email]:focus, input[type=password]:focus {
            outline: none; border-color: #1c5fb8; box-shadow: 0 0 0 3px rgba(28,95,184,0.15);
        }
        .remember { display: flex; align-items: center; gap: 7px; margin: 16px 0 4px; font-size: 13.5px; color: #374151; }
        button {
            width: 100%; margin-top: 20px; padding: 12px; background: #1c5fb8; color: #fff; border: none;
            border-radius: 9px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background .15s;
        }
        button:hover { background: #174e98; }
        .error {
            background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; border-radius: 9px;
            padding: 10px 13px; font-size: 13.5px; margin-bottom: 4px;
        }
        .foot { margin-top: 20px; text-align: center; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <span class="brand-logo">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                    <path d="M20 20l-4.5-4.5m1.5-4a6 6 0 11-12 0 6 6 0 0112 0z" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="brand-name">Searchly</span>
        </div>
        <p class="subtitle">Sign in to access internal documentation</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ url('/login') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">

            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">

            <label class="remember"><input type="checkbox" name="remember" value="1"> Remember me</label>

            <button type="submit">Sign in</button>
        </form>

        <p class="foot">Authorized personnel only · Searchly</p>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Searchly — Users</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f7fa; color: #1f2933; }
        .bar { background: #1c5fb8; color: #fff; padding: 16px 28px; display: flex; align-items: center; justify-content: space-between; }
        .bar strong { font-size: 18px; font-weight: 800; letter-spacing: -0.4px; }
        .bar a { color: #dbe7fb; font-size: 13px; text-decoration: none; }
        .wrap { max-width: 820px; margin: 28px auto; padding: 0 20px; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        th, td { padding: 11px 16px; text-align: left; border-bottom: 1px solid #eef1f5; font-size: 14px; }
        th { background: #f0f4fa; font-weight: 600; color: #374151; }
        tr:last-child td { border-bottom: none; }
        .chip { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .chip-admin { background: #fde8ef; color: #b4376b; }
        .chip-viewer { background: #e8f0fb; color: #1c5fb8; }
    </style>
</head>
<body>
    <div class="bar">
        <strong>Searchly · Admin</strong>
        <form method="POST" action="{{ url('/logout') }}">@csrf<a href="#" onclick="this.closest('form').submit();return false;">Sign out</a></form>
    </div>
    <div class="wrap">
        <h1>Users ({{ $users->count() }})</h1>
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td><span class="chip chip-{{ $user->role }}">{{ $user->role }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>

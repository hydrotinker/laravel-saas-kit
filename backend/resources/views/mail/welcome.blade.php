<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {{ $tenant->name }}</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <h1 style="font-size: 20px;">Welcome, {{ $user->name }}!</h1>

    <p>
        Your organization <strong>{{ $tenant->name }}</strong> is ready to go.
        You're set up as the owner, so you can start creating projects and
        inviting your team right away.
    </p>

    <p>
        We're glad to have you on board.
    </p>

    <p style="color: #6b7280; font-size: 13px;">
        — The {{ config('app.name') }} Team
    </p>
</body>
</html>

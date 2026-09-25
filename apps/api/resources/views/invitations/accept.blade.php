<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Accept invitation</title>
</head>
<body>
    @if (! empty($error))
        <p>{{ $error }}</p>
    @endif

    @if (($mode ?? 'new') === 'invalid')
        <h1>Invitation unavailable</h1>
        <p>This invitation is no longer valid.</p>
    @elseif (($mode ?? 'new') === 'existing')
        <h1>Confirm this invitation</h1>
        <p>Sign in with the invited staff account, then confirm. A new password is not set for an existing account.</p>
        <form method="POST" action="{{ route('invitations.accept') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit">Confirm invitation</button>
        </form>
    @else
        <h1>Choose your password</h1>
        <form method="POST" action="{{ route('invitations.accept') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label>Password <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
            <label>Confirm <input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
            <button type="submit">Save password</button>
        </form>
    @endif
</body>
</html>

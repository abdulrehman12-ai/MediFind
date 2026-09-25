<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invitation accepted</title>
</head>
<body>
    @if ($createdAccount)
        <h1>Password saved</h1>
        <p>Sign in to your MediFind panel and set up an authenticator app before using pharmacy or admin tools.</p>
    @else
        <h1>Invitation accepted</h1>
        <p>Your existing password was not changed. Sign in to the pharmacy panel with that password and your authenticator app.</p>
    @endif
</body>
</html>

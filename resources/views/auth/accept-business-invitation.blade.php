<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accept business invitation</title>
    <style>
        body{font-family:system-ui,sans-serif;background:#f4f6f8;color:#17202a;display:grid;min-height:100vh;place-items:center;margin:0;padding:24px}
        main{background:#fff;width:min(100%,440px);padding:28px;border-radius:16px;box-shadow:0 12px 36px #15223814}
        label{display:block;margin:16px 0 6px;font-weight:600}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #ccd3db;border-radius:8px;font:inherit}
        button{margin-top:20px;width:100%;padding:12px;border:0;border-radius:8px;background:#153e75;color:#fff;font:inherit;font-weight:700;cursor:pointer}
        #status{margin-top:16px;white-space:pre-wrap}small{color:#536273}
    </style>
</head>
<body>
<main>
    <h1>Accept business invitation</h1>
    <p>Sign in with the <strong>verified email address</strong> that received the invitation.</p>
    <form id="accept-form">
        <input id="invite-token" type="hidden" value="{{ request('token') }}">
        <label for="email">Email address</label>
        <input id="email" type="email" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in and accept invitation</button>
    </form>
    <p id="status" role="status" aria-live="polite"></p>
    <small>If your email is not verified, verify it and reopen this invitation link.</small>
</main>
<script>
document.getElementById('accept-form').addEventListener('submit', async function (event) {
    event.preventDefault();
    const status = document.getElementById('status');
    const button = this.querySelector('button');
    const token = document.getElementById('invite-token').value;
    status.textContent = 'Signing in…';
    button.disabled = true;
    try {
        const login = await fetch('/api/v1/auth/login', {
            method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({email: document.getElementById('email').value, password: document.getElementById('password').value})
        });
        const loginData = await login.json();
        if (!login.ok) throw new Error(loginData.message || 'Sign-in failed.');
        const accepted = await fetch('/api/v1/business/invitations/accept', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + loginData.access_token},
            body: JSON.stringify({token: token})
        });
        const acceptedData = await accepted.json();
        if (!accepted.ok) throw new Error(acceptedData.message || 'The invitation could not be accepted.');
        status.textContent = acceptedData.message + ' You can now return to the app and select your business.';
        this.reset();
        document.getElementById('invite-token').value = '';
    } catch (error) {
        status.textContent = error.message || 'Unable to accept invitation. Please retry.';
    } finally {
        button.disabled = false;
    }
});
</script>
</body>
</html>

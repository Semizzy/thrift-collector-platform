<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password — Thrift Collector Platform</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; background: #f4f6f8; color: #18212f; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; }
        main { width: min(100%, 26rem); background: white; border: 1px solid #dce2e8; border-radius: 1rem; padding: 1.5rem; box-sizing: border-box; }
        h1 { margin-top: 0; font-size: 1.45rem; } p { line-height: 1.5; color: #526071; }
        label { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
        input { width: 100%; box-sizing: border-box; padding: .8rem; border: 1px solid #b9c4d0; border-radius: .5rem; font: inherit; }
        button { width: 100%; margin-top: 1.2rem; border: 0; border-radius: .5rem; padding: .85rem; background: #173f35; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        #message { margin-top: 1rem; overflow-wrap: anywhere; }
    </style>
</head>
<body>
<main>
    <h1>Reset your password</h1>
    <p>Choose a new password with at least 12 characters. The reset link can only be used once and expires.</p>
    <form id="reset-form">
        <input type="hidden" id="email" autocomplete="email" value="{{ request('email') }}">
        <input type="hidden" id="token" value="{{ request('token') }}">
        <label for="password">New password</label>
        <input id="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required>
        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" type="password" minlength="12" maxlength="200" autocomplete="new-password" required>
        <button type="submit">Update password</button>
    </form>
    <p id="message" role="status" aria-live="polite"></p>
</main>
<script>
document.getElementById('reset-form').addEventListener('submit', async function (event) {
    event.preventDefault();
    const message = document.getElementById('message');
    const button = this.querySelector('button');
    button.disabled = true;
    message.textContent = 'Updating password…';
    try {
        const response = await fetch('/api/v1/auth/password/reset', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                email: document.getElementById('email').value,
                token: document.getElementById('token').value,
                password: document.getElementById('password').value,
                password_confirmation: document.getElementById('password_confirmation').value
            })
        });
        const result = await response.json();
        message.textContent = result.message || (result.errors ? Object.values(result.errors).flat().join(' ') : 'Unable to reset password.');
        if (response.ok) this.reset();
    } catch (error) {
        message.textContent = 'Unable to connect. Please try again.';
    } finally {
        button.disabled = false;
    }
});
</script>
</body>
</html>

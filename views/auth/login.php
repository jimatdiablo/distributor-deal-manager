<?php

declare(strict_types=1);

ob_start();
?>
<section class="card" style="max-width: 460px; margin-inline: auto;">
    <h2>Login</h2>
    <p class="muted"><?= \App\Core\MaatAuth::enabled() ? 'Sign in with Maat using a code sent to your email.' : 'Sign in with your assigned account credentials.' ?></p>
    <?php if (!empty($message)): ?>
        <p><?= htmlspecialchars((string)$message) ?></p>
    <?php endif; ?>
    <?php if (\App\Core\MaatAuth::enabled()): ?>
    <?php if (!empty($_SESSION['maat_sign_in_email'])): ?>
    <form method="post" class="grid">
        <?= $csrfField() ?>
        <input type="hidden" name="action" value="verify">
        <label>Sign-in code for <?= htmlspecialchars((string)$_SESSION['maat_sign_in_email']) ?>
            <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
        </label>
        <button type="submit">Verify code</button>
    </form>
    <?php endif; ?>
    <form method="post" class="grid">
        <?= $csrfField() ?>
        <input type="hidden" name="action" value="request">
        <label>Email <input name="email" type="email" autocomplete="email" required></label>
        <button type="submit">Send sign-in code</button>
    </form>
    <?php else: ?>
    <form method="post" class="grid">
        <?= $csrfField() ?>
        <input name="email" type="email" placeholder="Email">
        <input name="password" type="password" placeholder="Password">
        <button type="submit">Sign in</button>
    </form>
    <?php endif; ?>
</section>
<?php
$content = (string)ob_get_clean();
require __DIR__ . '/../layouts/app.php';

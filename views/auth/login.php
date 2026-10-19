<?php

declare(strict_types=1);

$maatEnabled = \App\Core\MaatAuth::enabled();
$showCodeStep = $maatEnabled && !empty($showCodeStep);
$signInEmail = (string)($signInEmail ?? '');
ob_start();
?>
<section class="card" style="max-width: 460px; margin-inline: auto;">
    <h2>Sign In</h2>
    <p class="muted"><?= $maatEnabled ? 'Enter your account email and use the one-time code sent to you.' : 'Sign in with your assigned account credentials.' ?></p>
    <?php if (!empty($message)): ?>
        <p><?= htmlspecialchars((string)$message) ?></p>
    <?php endif; ?>
    <?php if ($maatEnabled): ?>
    <?php if (!$showCodeStep): ?>
    <form id="signin-email" method="post" action="/login" class="grid">
        <?= $csrfField() ?>
        <input type="hidden" name="action" value="request">
        <div><h3>1. Email Address</h3><p class="muted">We will send a short one-time sign-in code.</p></div>
        <label>Email <input name="email" type="email" value="<?= htmlspecialchars($signInEmail) ?>" autocomplete="email" autofocus required></label>
        <button type="submit">Send Code</button>
    </form>
    <?php else: ?>
    <form id="signin-code" method="post" action="/login" class="grid">
        <?= $csrfField() ?>
        <input type="hidden" name="action" value="verify">
        <div><h3>2. Verification Code</h3><p class="muted">Use the newest code from your email.</p></div>
        <p><?= htmlspecialchars($signInEmail) ?></p>
        <label>Code
            <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" autofocus required>
        </label>
        <button type="submit">Sign In</button>
    </form>
    <form id="signin-resend" method="post" action="/login">
        <?= $csrfField() ?>
        <input type="hidden" name="action" value="request">
        <input name="email" type="hidden" value="<?= htmlspecialchars($signInEmail) ?>">
        <button type="submit">Send a new code</button>
    </form>
    <?php endif; ?>
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

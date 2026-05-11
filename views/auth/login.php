<?php

declare(strict_types=1);

ob_start();
?>
<section class="card" style="max-width: 460px; margin-inline: auto;">
    <h2>Login</h2>
    <p class="muted">Sign in with your assigned account credentials.</p>
    <?php if (!empty($message)): ?>
        <p><?= htmlspecialchars((string)$message) ?></p>
    <?php endif; ?>
    <form method="post" class="grid">
        <?= $csrfField() ?>
        <input name="email" type="email" placeholder="Email">
        <input name="password" type="password" placeholder="Password">
        <button type="submit">Sign in</button>
    </form>
</section>
<?php
$content = (string)ob_get_clean();
require __DIR__ . '/../layouts/app.php';

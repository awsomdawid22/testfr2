<?php
// Shared auth page header — used by login, register, forgot-password, reset-password, verify-email
// Sets $authTitle and $authSubtitle before including this file.
?>
<div class="auth-header">
    <img src="<?= SITE_URL ?>/public/images/LARPWhite.png"
         alt="Los Angeles Experience"
         style="width:160px;height:auto;display:block;margin:0 auto 18px;filter:drop-shadow(0 2px 12px rgba(0,0,0,0.6))">
    <?php if (!empty($authTitle)): ?>
    <h1 style="font-family:var(--font-head);font-size:2rem;font-weight:900;letter-spacing:2px;text-transform:uppercase;text-align:center;margin:0"><?= e($authTitle) ?></h1>
    <?php endif; ?>
    <?php if (!empty($authSubtitle)): ?>
    <p style="color:var(--t1);font-size:0.82rem;margin-top:6px;text-align:center;letter-spacing:1px"><?= e($authSubtitle) ?></p>
    <?php endif; ?>
</div>

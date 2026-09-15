<?php $profileEscape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }; ?>
<div class="profile-area">
    <button type="button" class="profile-static profile-trigger" aria-label="Your profile" aria-expanded="false" aria-controls="profile-panel">
        <span class="avatar"><?= $profileEscape($avatarLetters) ?></span>
        <span class="profile-identity"><span class="profile-name"><?= $profileEscape($displayName) ?></span><span class="profile-role"><?= $profileEscape(tdc_role_name()) ?></span></span>
    </button>
    <section id="profile-panel" class="profile-panel" aria-label="Your account" hidden>
        <strong><?= $profileEscape($_SESSION['userlegalname']) ?></strong>
        <span>@<?= $profileEscape($_SESSION['username']) ?></span>
        <span><?= $profileEscape(tdc_role_name()) ?></span>
        <?php if (tdc_can('setup.users.manage')): ?><a href="setup.php?section=users">Manage users</a><?php endif; ?>
        <a href="home.php?logout=1&amp;csrf=<?= urlencode($csrfToken) ?>">Log out</a>
    </section>
</div>

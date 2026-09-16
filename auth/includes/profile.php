<?php require_once __DIR__.'/ui.php'; $profileEscape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }; ?>
<div class="profile-area">
    <button type="button" class="profile-static profile-trigger" aria-label="Your profile" aria-expanded="false" aria-controls="profile-panel">
        <span class="avatar"><?= $profileEscape($avatarLetters) ?></span>
        <span class="profile-identity"><span class="profile-name"><?= $profileEscape($displayName) ?></span><span class="profile-role"><?= $profileEscape(tdc_role_name()) ?></span></span>
    </button>
    <section id="profile-panel" class="profile-panel" aria-label="Your account" hidden>
        <div class="profile-account"><span class="avatar"><?= $profileEscape($avatarLetters) ?></span><div><strong><?= $profileEscape($_SESSION['userlegalname']) ?></strong>
        <span>@<?= $profileEscape($_SESSION['username']) ?></span>
        <span><?= $profileEscape(tdc_role_name()) ?></span></div></div>
        <?php if (tdc_can('setup.users.manage')): ?><a href="setup.php?section=users"><?= tdc_icon('users') ?><span>Manage users</span></a><?php endif; ?>
        <a class="profile-logout" href="home.php?logout=1&amp;csrf=<?= urlencode($csrfToken) ?>"><?= tdc_icon('logout') ?><span>Log out</span></a>
    </section>
</div>

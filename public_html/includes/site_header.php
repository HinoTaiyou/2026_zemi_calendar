<?php
declare(strict_types=1);

require_once __DIR__ . '/assets.php';

/**
 * @param 'calendar'|'chat'|'manage'|'' $active
 */
function renderSiteHeader(string $active = ''): void
{
    $navItems = [
        'calendar' => ['href' => 'index.php', 'label' => 'カレンダー'],
        'chat' => ['href' => 'chat.php', 'label' => 'AIチャット'],
        'manage' => ['href' => 'event_manage.php', 'label' => '予定を整理'],
    ];
    ?>
  <header class="site-header">
    <div class="site-header-inner">
      <div class="site-header-start">
        <div class="site-user-menu" id="site-user-menu">
          <button
            type="button"
            class="user-chip-btn"
            id="user-chip-btn"
            hidden
            aria-expanded="false"
            aria-haspopup="menu"
            aria-controls="user-dropdown"
            aria-label="アカウントメニュー"
          >
            <span class="user-avatar" id="user-avatar" aria-hidden="true">?</span>
            <span class="user-chip-text">
              <span class="user-chip-label">ログイン中</span>
              <span class="user-nickname" id="user-nickname"></span>
            </span>
            <span class="user-chevron" id="user-chevron" aria-hidden="true"></span>
          </button>
          <div class="site-user-dropdown" id="user-dropdown" role="menu" hidden>
            <div class="site-user-dropdown-head">
              <span class="user-avatar user-avatar-large" id="user-avatar-menu" aria-hidden="true">?</span>
              <div>
                <p class="site-user-dropdown-name" id="user-nickname-menu"></p>
                <p class="site-user-dropdown-meta">学習カレンダー</p>
              </div>
            </div>
            <a class="site-user-dropdown-item" role="menuitem" href="login/home.html">マイページ</a>
            <button class="site-user-dropdown-item site-user-dropdown-logout" type="button" role="menuitem" id="user-logout-btn">ログアウト</button>
          </div>
          <a class="login-entry-btn" href="login/index.html" id="login-link">
            <span class="login-entry-icon" aria-hidden="true">→</span>
            <span>ログイン</span>
          </a>
        </div>
        <a class="site-brand" href="index.php">
          <span class="site-brand-mark">C</span>
          <span class="site-brand-name">学習カレンダー</span>
        </a>
      </div>
      <nav class="site-nav" aria-label="メインナビゲーション">
        <?php foreach ($navItems as $key => $item): ?>
          <a
            class="site-nav-link"
            href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
            <?= $active === $key ? 'aria-current="page"' : '' ?>
          ><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </header>
    <?php
}

function renderSiteUserScripts(): void
{
    ?>
  <script src="<?= htmlspecialchars(publicAssetUrl('login/auth.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
  <script src="<?= htmlspecialchars(publicAssetUrl('assets/js/site_user.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <?php
}

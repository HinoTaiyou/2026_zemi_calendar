<?php
declare(strict_types=1);

function publicAssetRoot(): string
{
    return dirname(__DIR__);
}

function publicAssetVersion(string $relativePath): string
{
    $fullPath = publicAssetRoot() . '/' . ltrim($relativePath, '/');

    return is_file($fullPath) ? (string) filemtime($fullPath) : '0';
}

function publicAssetUrl(string $relativePath): string
{
    return $relativePath . '?v=' . rawurlencode(publicAssetVersion($relativePath));
}

function renderSiteHead(string $title): void
{
    $styleUrl = publicAssetUrl('style.css');
    ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($styleUrl, ENT_QUOTES, 'UTF-8') ?>">
  <style>
    .site-header { position: sticky; top: 0; z-index: 20; padding: 16px 16px 0; }
    .site-header-inner {
      max-width: 1280px; margin: 0 auto; min-height: 72px; padding: 8px 16px;
      display: flex; align-items: center; justify-content: space-between; gap: 16px;
      border: 1px solid rgba(148, 163, 184, 0.22); border-radius: 32px;
      background: rgba(255, 255, 255, 0.92); box-shadow: 0 24px 80px rgba(15, 23, 42, 0.14);
    }
    .site-header-start { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1 1 auto; }
    .site-brand { display: inline-flex; align-items: center; gap: 8px; color: #0f172a; text-decoration: none; font-weight: 700; }
    .site-brand-mark {
      width: 36px; height: 36px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, #14b8a6, #22c55e); color: #fff; font-weight: 800;
    }
    .site-nav { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .site-nav-link {
      display: inline-flex; align-items: center; height: 42px; padding: 0 16px; border-radius: 16px;
      color: #64748b; text-decoration: none; font-size: 14px; font-weight: 700;
    }
    .site-nav-link[aria-current="page"] { background: rgba(20, 184, 166, 0.12); color: #0f766e; }
    .user-chip-btn, .login-entry-btn {
      display: inline-flex; align-items: center; gap: 10px; min-height: 48px; padding: 6px 14px 6px 6px;
      border: 1px solid rgba(20, 184, 166, 0.18); border-radius: 999px; background: rgba(20, 184, 166, 0.1);
      color: #0f766e; font-size: 14px; font-weight: 700; text-decoration: none; cursor: pointer;
    }
    .user-avatar {
      width: 36px; height: 36px; border-radius: 50%; display: inline-grid; place-items: center;
      background: linear-gradient(135deg, #14b8a6, #22c55e); color: #fff; font-weight: 800;
    }
    .user-chip-btn[hidden], .login-entry-btn[hidden] { display: none !important; }
    .calendar-grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .day-link { display: block; height: 100%; color: inherit; text-decoration: none; }
  </style>
    <?php
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/study_planner.php';
require_once __DIR__ . '/includes/event_admin.php';

$now = appNow();
$error = '';
$preview = null;
$filter = bulkFilterDefaults();

/** Map a quick preset to filter inputs based on "now". */
function bulkPresetInputs(string $preset, DateTimeImmutable $now): array
{
    $today = $now->format('Y-m-d');
    switch ($preset) {
        case 'future':
            return ['future_only' => '1'];
        case 'this_week':
            $start = $now->modify('monday this week');
            return ['start_date' => $start->format('Y-m-d'), 'end_date' => $start->modify('+6 days')->format('Y-m-d')];
        case 'this_month':
            return ['start_date' => $now->format('Y-m-01'), 'end_date' => $now->modify('last day of this month')->format('Y-m-d')];
        case 'next_month':
            $nm = $now->modify('first day of next month');
            return ['start_date' => $nm->format('Y-m-d'), 'end_date' => $nm->modify('last day of this month')->format('Y-m-d')];
        case 'next_3_months':
            return ['start_date' => $today, 'end_date' => $now->modify('+3 months')->format('Y-m-d')];
        default:
            return [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = readInputString($_POST, 'action', 'preview');

    if (!isValidCsrfPost()) {
        $error = csrfErrorMessage();
    } elseif ($action === 'delete') {
        $stored = $_SESSION['bulk_preview'] ?? null;
        $postedFingerprint = readInputString($_POST, 'fingerprint', '');
        $confirmWord = readInputString($_POST, 'confirm_word', '');

        if (!is_array($stored) || ($stored['fingerprint'] ?? '') === '') {
            $error = '先に「プレビュー」で対象を確認してください。';
        } elseif (!hash_equals((string) $stored['fingerprint'], $postedFingerprint)) {
            $error = '条件が変わっています。もう一度プレビューしてください。';
        } else {
            $storedFilter = $stored['filter'];
            try {
                $currentCount = countBulkEvents($storedFilter);
                if ($currentCount === 0) {
                    $error = '対象の予定がありません。';
                    unset($_SESSION['bulk_preview']);
                } elseif ($currentCount !== (int) $stored['count']) {
                    $error = 'プレビュー後に対象が変化しました（現在 ' . $currentCount . ' 件）。もう一度プレビューしてください。';
                    unset($_SESSION['bulk_preview']);
                } elseif (!empty($stored['requires_strong']) && $confirmWord !== BULK_CONFIRM_WORD) {
                    $error = '確認のため、入力欄に「' . BULK_CONFIRM_WORD . '」と入力してください。';
                    // keep preview so the user can retry
                    $preview = previewBulkEvents($storedFilter);
                    $filter = $storedFilter;
                } else {
                    $result = deleteBulkEvents($storedFilter);
                    unset($_SESSION['bulk_preview']);
                    setFlash('success', $result['deleted'] . '件の予定を削除しました。（' . bulkFilterSummaryText($storedFilter) . '）');
                    header('Location: event_manage.php');
                    exit;
                }
            } catch (Throwable $e) {
                $error = '削除に失敗しました: ' . publicDatabaseErrorMessage($e);
            }
        }
    } elseif ($action === 'csv') {
        $filter = normalizeBulkFilter($_POST, $now);
        if (isBulkFilterEmpty($filter)) {
            $error = '条件を1つ以上指定してください。';
        } else {
            try {
                $csv = bulkCsvString($filter);
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="events-' . $now->format('Ymd-His') . '.csv"');
                echo "\xEF\xBB\xBF" . $csv; // UTF-8 BOM for Excel
                exit;
            } catch (Throwable $e) {
                $error = 'CSV出力に失敗しました: ' . publicDatabaseErrorMessage($e);
            }
        }
    } else {
        // preview (optionally seeded by a quick preset)
        $input = $_POST;
        $preset = readInputString($_POST, 'preset', '');
        if ($preset !== '') {
            $input = array_merge($input, bulkPresetInputs($preset, $now));
        }
        $filter = normalizeBulkFilter($input, $now);
        if (isBulkFilterEmpty($filter)) {
            $error = '条件を1つ以上指定してください（期間・曜日・キーワード・登録元など）。';
            unset($_SESSION['bulk_preview']);
        } else {
            try {
                $preview = previewBulkEvents($filter);
                $requiresStrong = bulkFilterRequiresStrongConfirm($filter, $preview['count']);
                $_SESSION['bulk_preview'] = [
                    'filter' => $filter,
                    'fingerprint' => bulkFilterFingerprint($filter),
                    'count' => $preview['count'],
                    'requires_strong' => $requiresStrong,
                ];
            } catch (Throwable $e) {
                $error = 'プレビューに失敗しました: ' . publicDatabaseErrorMessage($e);
            }
        }
    }
}

// GET seed: ?batch=<id> preselects a study-plan batch.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $batchSeed = normalizeSourceBatchId($_GET['batch'] ?? null);
    if ($batchSeed !== null) {
        $filter['batch_id'] = $batchSeed;
        $filter['source'] = 'study_plan';
    }
}

$flash = getFlash();
try {
    $batches = listStudyBatches();
} catch (Throwable $e) {
    $batches = [];
    if ($error === '') {
        $error = 'データベースエラー: ' . publicDatabaseErrorMessage($e);
    }
}
$fingerprint = $_SESSION['bulk_preview']['fingerprint'] ?? '';
$requiresStrong = !empty($_SESSION['bulk_preview']['requires_strong']);
$weekdayChecked = array_fill_keys($filter['weekdays'], true);
$presets = [
    'future' => '今日以降',
    'this_week' => '今週',
    'this_month' => '今月',
    'next_month' => '来月',
    'next_3_months' => '今後3か月',
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>予定を整理 - カレンダー</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <header class="site-header">
    <div class="site-header-inner">
      <a class="site-brand" href="index.php">
        <span class="site-brand-mark">C</span>
        <span>Calm Focus Calendar</span>
      </a>
      <nav class="site-nav" aria-label="メインナビゲーション">
        <a class="site-nav-link" href="index.php">カレンダー</a>
        <a class="site-nav-link" href="chat.php">AIチャット</a>
        <a class="site-nav-link" href="event_manage.php" aria-current="page">予定を整理</a>
      </nav>
    </div>
  </header>

  <main class="app">
    <div class="page-head">
      <div class="page-head-titles">
        <h1 class="page-title">予定を整理</h1>
        <p class="page-subtitle">条件で絞り込み、プレビューで確認してからまとめて削除します</p>
      </div>
      <div class="page-head-actions">
        <a class="back-link" href="index.php">← カレンダーに戻る</a>
      </div>
    </div>

    <?php if ($flash !== null): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>" role="status"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="alert alert-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="panel">
      <h2 class="panel-title">絞り込み条件</h2>
      <form method="post" class="generate-form">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="preview">

        <div class="form-row">
          <span class="form-label">クイック指定</span>
          <div class="quickstart">
            <?php foreach ($presets as $key => $label): ?>
              <button type="submit" name="preset" value="<?= $key ?>" class="quickstart-chip"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-grid form-grid-3">
          <div class="form-row">
            <label class="form-label" for="start_date">開始日</label>
            <input class="form-input" type="date" id="start_date" name="start_date" value="<?= htmlspecialchars((string) $filter['start_date'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="form-row">
            <label class="form-label" for="end_date">終了日</label>
            <input class="form-input" type="date" id="end_date" name="end_date" value="<?= htmlspecialchars((string) $filter['end_date'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="form-row">
            <label class="form-label" for="source">登録元</label>
            <select class="form-input" id="source" name="source">
              <?php foreach (BULK_SOURCE_OPTIONS as $opt): ?>
                <option value="<?= $opt ?>"<?= $filter['source'] === $opt ? ' selected' : '' ?>><?= htmlspecialchars(bulkSourceLabel($opt), ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <span class="form-label">曜日（複数選択可）</span>
          <div class="weekday-row">
            <?php foreach (STUDY_WEEKDAYS_JA as $iso => $ja): ?>
              <label class="weekday-chip">
                <input type="checkbox" name="weekdays[]" value="<?= $iso ?>"<?= isset($weekdayChecked[$iso]) ? ' checked' : '' ?>>
                <span><?= $ja ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-grid form-grid-3">
          <div class="form-row">
            <label class="form-label" for="keyword">タイトルキーワード</label>
            <input class="form-input" type="text" id="keyword" name="keyword" maxlength="<?= BULK_KEYWORD_MAX_LENGTH ?>" value="<?= htmlspecialchars($filter['keyword'], ENT_QUOTES, 'UTF-8') ?>" placeholder="例：統計">
          </div>
          <div class="form-row">
            <label class="form-label" for="batch_id">学習プラン</label>
            <select class="form-input" id="batch_id" name="batch_id">
              <option value="">すべて</option>
              <?php foreach ($batches as $batch): ?>
                <option value="<?= htmlspecialchars($batch['id'], ENT_QUOTES, 'UTF-8') ?>"<?= $filter['batch_id'] === $batch['id'] ? ' selected' : '' ?>>
                  <?= htmlspecialchars($batch['label'] . '（' . $batch['count'] . '件 / ' . $batch['first'] . '〜' . $batch['last'] . '）', ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <span class="form-label">対象期間</span>
            <label class="weekday-chip">
              <input type="checkbox" name="future_only" value="1"<?= $filter['future_only'] ? ' checked' : '' ?>>
              <span>今日以降のみ</span>
            </label>
          </div>
        </div>

        <div class="form-actions">
          <button class="primary-btn" type="submit">プレビュー</button>
          <button class="secondary-btn" type="submit" name="action" value="csv" formnovalidate>CSVで保存</button>
        </div>
      </form>
    </div>

    <?php if ($preview !== null): ?>
      <div class="panel">
        <h2 class="panel-title">削除プレビュー</h2>
        <p class="plan-section-desc"><?= htmlspecialchars(bulkFilterSummaryText($filter), ENT_QUOTES, 'UTF-8') ?></p>
        <dl class="plan-preview">
          <div class="plan-field"><dt>削除候補</dt><dd><?= (int) $preview['count'] ?>件</dd></div>
          <div class="plan-field"><dt>期間</dt><dd><?= htmlspecialchars((string) ($preview['first'] ?? '-') . ' 〜 ' . (string) ($preview['last'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div class="plan-field"><dt>合計時間</dt><dd><?= htmlspecialchars(formatMinutesAsHours((int) $preview['total_minutes']), ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div class="plan-field"><dt>登録元別</dt><dd><?php
            $srcParts = [];
            foreach ($preview['by_source'] as $st => $c) {
                $srcParts[] = bulkSourceLabel($st === 'unknown' ? 'unknown' : $st) . ' ' . $c;
            }
            echo htmlspecialchars(implode(' / ', $srcParts) ?: '-', ENT_QUOTES, 'UTF-8');
          ?></dd></div>
        </dl>

        <?php if ($preview['count'] === 0): ?>
          <p class="day-view-empty">条件に一致する予定はありません。</p>
        <?php else: ?>
          <ul class="event-list event-list-compact">
            <?php foreach ($preview['rows'] as $event): ?>
              <li class="event-list-item">
                <span class="event-list-date"><?= htmlspecialchars((string) $event['date'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="event-list-time"><?= htmlspecialchars(formatEventTime($event), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="event-list-title"><?= htmlspecialchars((string) $event['title'], ENT_QUOTES, 'UTF-8') ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if ($preview['count'] > BULK_PREVIEW_ROWS): ?>
            <p class="plan-section-desc">…ほか <?= $preview['count'] - BULK_PREVIEW_ROWS ?> 件（先頭 <?= BULK_PREVIEW_ROWS ?> 件を表示）</p>
          <?php endif; ?>

          <div class="danger-zone">
            <h3 class="danger-zone-title">⚠ 削除の確認</h3>
            <p class="danger-zone-text">この操作は取り消せません。<strong><?= (int) $preview['count'] ?>件</strong>の予定を削除します。</p>
            <form method="post" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
              <?= csrfInput() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="fingerprint" value="<?= htmlspecialchars($fingerprint, ENT_QUOTES, 'UTF-8') ?>">
              <?php if ($requiresStrong): ?>
                <div class="form-row">
                  <label class="form-label" for="confirm_word">確認のため「<?= BULK_CONFIRM_WORD ?>」と入力してください</label>
                  <input class="form-input" type="text" id="confirm_word" name="confirm_word" autocomplete="off" placeholder="<?= BULK_CONFIRM_WORD ?>" required>
                </div>
              <?php endif; ?>
              <button class="danger-btn" type="submit"><?= (int) $preview['count'] ?>件を削除する</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>

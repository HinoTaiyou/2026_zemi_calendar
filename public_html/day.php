<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$dateParam = $_GET['date'] ?? '';
$dateParam = is_string($dateParam) ? $dateParam : '';
$date = isStrictDateYmd($dateParam) ? DateTime::createFromFormat('!Y-m-d', $dateParam) : false;

if ($date === false) {
    header('Location: index.php');
    exit;
}

$year = (int) $date->format('Y');
$month = (int) $date->format('n');
$day = (int) $date->format('j');
$dateKey = $date->format('Y-m-d');
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
$weekday = $weekdays[(int) $date->format('w')];

$todayYear = (int) date('Y');
$todayMonth = (int) date('n');
$todayDay = (int) date('j');
$isToday = ($year === $todayYear && $month === $todayMonth && $day === $todayDay);

$flash = getFlash();
$dayEvents = [];

try {
    $dayEvents = getEventsForDate($dateKey);
} catch (Throwable $e) {
    $flash = ['type' => 'error', 'message' => 'データベースエラー: ' . publicDatabaseErrorMessage($e)];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $year ?>年<?= $month ?>月<?= $day ?>日 - カレンダー</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="app">
    <header class="app-header app-header-split">
      <a class="back-link" href="index.php?year=<?= $year ?>&month=<?= $month ?>">← カレンダーに戻る</a>
      <a class="primary-btn primary-btn-small" href="event_edit.php?date=<?= htmlspecialchars($dateKey, ENT_QUOTES, 'UTF-8') ?>">予定を追加</a>
    </header>

    <?php if ($flash !== null): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="day-view">
      <div class="day-view-header">
        <p class="day-view-weekday"><?= $weekday ?>曜日<?= $isToday ? ' · 今日' : '' ?></p>
        <h1 class="day-view-date"><?= $month ?>月<?= $day ?>日</h1>
        <p class="day-view-year"><?= $year ?>年</p>
      </div>

      <div class="day-view-body">
        <?php if ($dayEvents === []): ?>
          <p class="day-view-empty">予定はありません</p>
        <?php else: ?>
          <ul class="event-list">
            <?php foreach ($dayEvents as $event): ?>
              <li class="event-list-item event-list-item-with-actions">
                <div class="event-list-main">
                  <span class="event-list-time"><?= htmlspecialchars(formatEventTime($event), ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="event-list-title"><?= htmlspecialchars((string) ($event['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="event-actions">
                  <a class="text-btn" href="event_edit.php?id=<?= (int) $event['id'] ?>">編集</a>
                  <form class="inline-form" method="post" action="event_delete.php" onsubmit="return confirm('この予定を削除しますか？');">
                    <?= csrfInput() ?>
                    <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($dateKey, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="text-btn text-btn-danger" type="submit">削除</button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>

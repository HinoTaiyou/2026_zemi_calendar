<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');

if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

$firstTimestamp = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int) date('t', $firstTimestamp);
$startWeekday = (int) date('w', $firstTimestamp);

$prevMonth = $month === 1 ? 12 : $month - 1;
$prevYear = $month === 1 ? $year - 1 : $year;
$nextMonth = $month === 12 ? 1 : $month + 1;
$nextYear = $month === 12 ? $year + 1 : $year;

$prevMonthDays = (int) date('t', mktime(0, 0, 0, $prevMonth, 1, $prevYear));

$todayYear = (int) date('Y');
$todayMonth = (int) date('n');
$todayDay = (int) date('j');

$flash = getFlash();
$eventsByDate = [];

try {
    $eventsByDate = getEventsGroupedByDate($year, $month);
} catch (Throwable $e) {
    $flash = ['type' => 'error', 'message' => 'データベースエラー: ' . $e->getMessage()];
}

$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
$weekdayClasses = ['sun', '', '', '', '', '', 'sat'];

function dayUrl(int $year, int $month, int $day): string
{
    return 'day.php?date=' . sprintf('%04d-%02d-%02d', $year, $month, $day);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>カレンダー</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="app">
    <header class="app-header app-header-split">
      <h1 class="app-title">カレンダー</h1>
      <a class="primary-btn primary-btn-small" href="chat.php">AIチャット</a>
    </header>

    <?php if ($flash !== null): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="calendar">
      <div class="calendar-toolbar">
        <div class="calendar-nav">
          <a class="nav-btn" href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" aria-label="前月">◀</a>
          <a class="nav-btn" href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" aria-label="次月">▶</a>
          <a class="today-btn" href="index.php">今日</a>
        </div>
        <h2 class="calendar-month"><?= $year ?>年<?= $month ?>月</h2>
      </div>

      <table class="calendar-grid">
        <thead>
          <tr>
            <?php foreach ($weekdays as $index => $label): ?>
              <th class="<?= $weekdayClasses[$index] ?>"><?= $label ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <tr>
            <?php
            for ($i = 0; $i < $startWeekday; $i++) {
                $day = $prevMonthDays - $startWeekday + $i + 1;
                $url = dayUrl($prevYear, $prevMonth, $day);
                $dateKey = sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, $day);
                $dayEvents = $eventsByDate[$dateKey] ?? [];
                echo '<td class="empty ' . $weekdayClasses[$i] . '">';
                echo '<a class="day-link" href="' . $url . '">';
                echo '<div class="day-cell other-month">';
                echo '<span class="day-number">' . $day . '</span>';
                echo renderDayEventsHtml($dayEvents);
                echo '</div></a></td>';
            }

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $weekday = ($startWeekday + $day - 1) % 7;
                $isToday = ($year === $todayYear && $month === $todayMonth && $day === $todayDay);
                $cellClass = $weekdayClasses[$weekday];
                $dayClass = 'day-cell' . ($isToday ? ' today' : '');
                $url = dayUrl($year, $month, $day);
                $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $dayEvents = $eventsByDate[$dateKey] ?? [];

                echo '<td class="' . $cellClass . '">';
                echo '<a class="day-link" href="' . $url . '">';
                echo '<div class="' . $dayClass . '">';
                echo '<span class="day-number">' . $day . '</span>';
                echo renderDayEventsHtml($dayEvents);
                echo '</div></a></td>';

                if ($weekday === 6 && $day < $daysInMonth) {
                    echo '</tr><tr>';
                }
            }

            $lastWeekday = ($startWeekday + $daysInMonth - 1) % 7;
            if ($lastWeekday < 6) {
                for ($i = $lastWeekday + 1, $day = 1; $i <= 6; $i++, $day++) {
                    $url = dayUrl($nextYear, $nextMonth, $day);
                    $dateKey = sprintf('%04d-%02d-%02d', $nextYear, $nextMonth, $day);
                    $dayEvents = $eventsByDate[$dateKey] ?? [];
                    echo '<td class="empty ' . $weekdayClasses[$i] . '">';
                    echo '<a class="day-link" href="' . $url . '">';
                    echo '<div class="day-cell other-month">';
                    echo '<span class="day-number">' . $day . '</span>';
                    echo renderDayEventsHtml($dayEvents);
                    echo '</div></a></td>';
                }
            }
            ?>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$error = '';
$conflicts = [];
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;
$event = null;

if ($isEdit) {
    try {
        $event = getEventById($id);
    } catch (Throwable $e) {
        $error = 'データベースエラー: ' . publicDatabaseErrorMessage($e);
        $isEdit = false;
    }
}

if ($error === '' && $isEdit && $event === null) {
    setFlash('error', '予定が見つかりませんでした。');
    header('Location: index.php');
    exit;
}

$defaults = [
    'date' => $event['date'] ?? readInputString($_GET, 'date', date('Y-m-d')),
    'time' => $event['time'] ?? '09:00',
    'duration_minutes' => (string) ($event['duration_minutes'] ?? 30),
    'title' => $event['title'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = readInputInt($_POST, 'id', 0);
    $isEdit = $id > 0;
    $date = readInputString($_POST, 'date', '');
    $time = readInputString($_POST, 'time', '');
    $durationMinutes = readInputInt($_POST, 'duration_minutes', 30);
    $title = readInputString($_POST, 'title', '');
    $allowConflict = readInputString($_POST, 'allow_conflict', '') === '1';

    $defaults = [
        'date' => $date,
        'time' => $time,
        'duration_minutes' => (string) $durationMinutes,
        'title' => $title,
    ];

    if (!isValidCsrfPost()) {
        $error = csrfErrorMessage();
    } else {
        $validation = validateEventData($date, $time, $durationMinutes, $title);

        if (!$validation['valid']) {
            $error = implode(' ', $validation['errors']);
        } else {
            try {
                $candidate = $validation['event'];
                $candidate['ai_idempotency_key'] = null;
                $conflicts = findEventConflicts($candidate, $isEdit ? $id : null);

                if ($conflicts !== [] && !$allowConflict) {
                    $error = '同じ時間帯に予定があります。内容を確認してください。';
                } else {
                    if ($isEdit) {
                        updateEvent($id, $date, $time, $durationMinutes, $title, $allowConflict);
                        setFlash('success', '予定を更新しました。');
                    } else {
                        createEvent($date, $time, $durationMinutes, $title, $allowConflict);
                        setFlash('success', '予定を追加しました。');
                    }

                    header('Location: day.php?date=' . urlencode($date));
                    exit;
                }
            } catch (EventConflictException $e) {
                $conflicts = $e->getConflicts();
                $error = '同じ時間帯に予定があります。内容を確認してください。';
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                $error = 'データベースエラー: ' . publicDatabaseErrorMessage($e);
            }
        }
    }
}

$dateObj = isStrictDateYmd($defaults['date']) ? DateTime::createFromFormat('!Y-m-d', $defaults['date']) : false;
$backDate = $dateObj !== false ? $defaults['date'] : date('Y-m-d');
$year = $dateObj ? (int) $dateObj->format('Y') : (int) date('Y');
$month = $dateObj ? (int) $dateObj->format('n') : (int) date('n');
$pageTitle = $isEdit ? '予定を編集' : '予定を追加';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - カレンダー</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="app">
    <header class="app-header">
      <a class="back-link" href="day.php?date=<?= htmlspecialchars($backDate, ENT_QUOTES, 'UTF-8') ?>">← 日付に戻る</a>
    </header>

    <div class="panel">
      <h1 class="panel-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

      <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($conflicts !== []): ?>
        <div class="alert alert-warning">
          <p>以下の予定と時間が重なっています。</p>
          <ul class="conflict-list">
            <?php foreach ($conflicts as $conflict): ?>
              <?php $target = formatConflictTarget($conflict); ?>
              <li>
                <strong><?= htmlspecialchars($target['label'], ENT_QUOTES, 'UTF-8') ?>:</strong>
                <?= htmlspecialchars((string) ($target['event']['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                （<?= htmlspecialchars(formatEventRangeText($target['event']), ENT_QUOTES, 'UTF-8') ?>）
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form class="generate-form" method="post">
        <?= csrfInput() ?>
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= $id ?>">
        <?php endif; ?>

        <div class="form-row">
          <label class="form-label" for="date">日付</label>
          <input class="form-input" type="date" id="date" name="date" value="<?= htmlspecialchars($defaults['date'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>

        <div class="form-row">
          <label class="form-label" for="time">開始時刻</label>
          <input class="form-input" type="time" id="time" name="time" value="<?= htmlspecialchars($defaults['time'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>

        <div class="form-row">
          <label class="form-label" for="duration_minutes">所要時間（分）</label>
          <input class="form-input" type="number" id="duration_minutes" name="duration_minutes" min="15" max="480" step="5" value="<?= htmlspecialchars($defaults['duration_minutes'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>

        <div class="form-row">
          <label class="form-label" for="title">タイトル</label>
          <input class="form-input" type="text" id="title" name="title" maxlength="255" value="<?= htmlspecialchars($defaults['title'], ENT_QUOTES, 'UTF-8') ?>" placeholder="例：英語" required>
        </div>

        <div class="form-actions">
          <button class="primary-btn" type="submit"><?= $isEdit ? '更新する' : '追加する' ?></button>
          <?php if ($conflicts !== []): ?>
            <button class="secondary-btn" type="submit" name="allow_conflict" value="1">それでも登録する</button>
          <?php endif; ?>
          <a class="secondary-btn" href="day.php?date=<?= htmlspecialchars($backDate, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/plan_constraints.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/chat_session.php';

$error = '';
$usedDemo = GEMINI_API_KEY === '';

initChatSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'message';

    if ($action === 'reset') {
        resetChatSession();
        header('Location: chat.php');
        exit;
    }

    if ($action === 'select_plan') {
        $planId = trim($_POST['plan_id'] ?? '');
        $plan = findChatPlanById($planId);

        if ($plan === null) {
            $error = '選択したプランが見つかりませんでした。';
        } else {
            setSelectedPlanId($planId);
            setChatProposedEvents($plan['events'] ?? []);
        }
    }

    if ($action === 'confirm') {
        $proposedEvents = getChatProposedEvents();

        if ($proposedEvents === []) {
            $error = '追加する予定案がありません。プランを選ぶか、AIと相談して予定案を作成してください。';
        } else {
            try {
                addEvents($proposedEvents);
                $count = count($proposedEvents);
                resetChatSession();
                setFlash('success', $count . '件の予定をカレンダーに追加しました。');
                header('Location: index.php');
                exit;
            } catch (Throwable $e) {
                $error = 'データベースエラー: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'message') {
        $message = trim($_POST['message'] ?? '');

        if ($message === '') {
            $error = 'メッセージを入力してください。';
        } else {
            try {
                addChatUserMessage($message);
                $result = chatWithScheduleAssistant(getChatMessages());
                addChatAssistantMessage($result['reply']);

                if ($result['constraints'] !== []) {
                    setChatConstraints($result['constraints']);
                }

                if ($result['plans'] !== []) {
                    setChatPlans($result['plans']);
                    setChatProposedEvents([]);
                    setSelectedPlanId('');
                } elseif ($result['events'] !== []) {
                    setChatPlans([]);
                    setChatProposedEvents($result['events']);
                }
            } catch (Throwable $e) {
                array_pop($_SESSION['chat_messages']);
                $error = 'AIエラー: ' . $e->getMessage();
            }
        }
    }
}

$messages = getChatMessages();
$proposedEvents = getChatProposedEvents();
$plans = getChatPlans();
$constraints = getChatConstraints();
$selectedPlanId = getSelectedPlanId();
$constraintsSummary = buildConstraintsSummary($constraints);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AI相談 - カレンダー</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="app">
    <header class="app-header app-header-split">
      <a class="back-link" href="index.php">← カレンダーに戻る</a>
      <h1 class="app-title">AIチャット</h1>
    </header>

    <?php if ($error !== ''): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($usedDemo): ?>
      <div class="panel-note chat-note">デモモードです。config.php に Gemini API キーを設定すると本格的な相談ができます。</div>
    <?php endif; ?>

    <?php if ($constraintsSummary !== ''): ?>
      <div class="constraints-banner">
        <strong>学習条件:</strong> <?= htmlspecialchars($constraintsSummary, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="chat-panel">
      <div class="chat-messages">
        <?php foreach ($messages as $message): ?>
          <?php
          $role = $message['role'] ?? 'assistant';
          $class = $role === 'user' ? 'chat-bubble chat-bubble-user' : 'chat-bubble chat-bubble-ai';
          $label = $role === 'user' ? 'あなた' : 'AI';
          ?>
          <div class="<?= $class ?>">
            <p class="chat-label"><?= $label ?></p>
            <p class="chat-text"><?= nl2br(htmlspecialchars((string) ($message['content'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
          </div>
        <?php endforeach; ?>
      </div>

      <form class="chat-form" method="post">
        <input type="hidden" name="action" value="message">
        <textarea class="form-input form-textarea chat-input" name="message" rows="3" placeholder="例：基本情報の資格を2026年12月までに。必要時間120時間。平日夜がいい" required></textarea>
        <button class="primary-btn" type="submit">送信</button>
      </form>
    </div>

    <?php if ($plans !== []): ?>
      <div class="panel">
        <h2 class="panel-title">プランを選ぶ</h2>
        <p class="panel-desc">AIが提案した3つのプランです。ベースにしたいものを選んでから、チャットで調整できます。</p>
        <div class="plan-grid">
          <?php foreach ($plans as $plan): ?>
            <?php
            $planId = (string) ($plan['id'] ?? '');
            $weeklyHours = calculatePlanWeeklyHours($plan['events'] ?? []);
            $minHours = $constraints['min_hours_per_week'] ?? null;
            $meets = planMeetsConstraint($plan['events'] ?? [], is_float($minHours) ? $minHours : null);
            $isSelected = $selectedPlanId === $planId;
            ?>
            <div class="plan-card<?= $isSelected ? ' plan-card-selected' : '' ?>">
              <div class="plan-card-header">
                <span class="plan-id">プラン<?= htmlspecialchars($planId, ENT_QUOTES, 'UTF-8') ?></span>
                <h3 class="plan-name"><?= htmlspecialchars((string) ($plan['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
              </div>
              <p class="plan-summary"><?= htmlspecialchars((string) ($plan['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
              <p class="plan-hours">週あたり <?= $weeklyHours ?> 時間</p>
              <?php if ($minHours !== null): ?>
                <p class="plan-status<?= $meets ? ' plan-status-ok' : ' plan-status-warn' ?>">
                  <?= $meets ? '✓ 必要時間を確保' : '⚠ 週' . $minHours . '時間以上が推奨' ?>
                </p>
              <?php endif; ?>
              <p class="plan-count"><?= count($plan['events'] ?? []) ?>件の予定</p>
              <form method="post">
                <input type="hidden" name="action" value="select_plan">
                <input type="hidden" name="plan_id" value="<?= htmlspecialchars($planId, ENT_QUOTES, 'UTF-8') ?>">
                <button class="secondary-btn plan-select-btn" type="submit">
                  <?= $isSelected ? '選択中' : 'このプランを選ぶ' ?>
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($proposedEvents !== []): ?>
      <div class="panel">
        <h2 class="panel-title">
          <?= $selectedPlanId !== '' ? 'プラン' . htmlspecialchars($selectedPlanId, ENT_QUOTES, 'UTF-8') . ' の予定' : '提案された予定' ?>
        </h2>
        <p class="panel-desc">内容を確認して、問題なければカレンダーに追加してください。修正はチャットで伝えられます。</p>
        <ul class="event-list event-list-compact">
          <?php foreach (array_slice($proposedEvents, 0, 8) as $event): ?>
            <li class="event-list-item">
              <span class="event-list-date"><?= htmlspecialchars($event['date'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="event-list-time"><?= htmlspecialchars(formatEventTime($event), ENT_QUOTES, 'UTF-8') ?></span>
              <span class="event-list-title"><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($proposedEvents) > 8): ?>
          <p class="panel-desc">…他 <?= count($proposedEvents) - 8 ?> 件</p>
        <?php endif; ?>
        <div class="form-actions">
          <form method="post">
            <input type="hidden" name="action" value="confirm">
            <button class="primary-btn" type="submit">この内容でカレンダーに追加</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" class="chat-reset-form">
      <input type="hidden" name="action" value="reset">
      <button class="text-btn" type="submit">新しい相談を始める</button>
    </form>
  </div>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/plan_constraints.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/chat_session.php';

$error = '';
$conflicts = [];
$usedDemo = isGeminiDemoMode();

initChatSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = readInputString($_POST, 'action', 'message');

    if (!isValidCsrfPost()) {
        $error = csrfErrorMessage();
    } else {
        if ($action === 'reset') {
            resetChatSession();
            header('Location: chat.php');
            exit;
        }

        if ($action === 'select_plan') {
            $planId = readInputString($_POST, 'plan_id', '');
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
            $allowConflict = readInputString($_POST, 'allow_conflict', '') === '1';

            if ($proposedEvents === []) {
                $error = '追加する予定案がありません。プランを選ぶか、AIと相談して予定案を作成してください。';
            } else {
                try {
                    $validatedEvents = validateEventList($proposedEvents);
                    $ignoreKeys = array_values(array_filter(array_map(
                        static fn(array $event): ?string => $event['ai_idempotency_key'] ?? null,
                        $validatedEvents
                    )));
                    $conflicts = findEventListConflicts($validatedEvents, null, $ignoreKeys);

                    if ($conflicts !== [] && !$allowConflict) {
                        $error = '同じ時間帯に予定があります。内容を確認してください。';
                    } else {
                        $summary = addEvents($validatedEvents, $allowConflict);
                        resetChatSession();

                        if ($summary['inserted'] > 0 && $summary['skipped'] > 0) {
                            setFlash('success', $summary['inserted'] . '件の予定を追加しました。'
                                . $summary['skipped'] . '件は登録済みでした。');
                        } elseif ($summary['inserted'] > 0) {
                            setFlash('success', $summary['inserted'] . '件の予定をカレンダーに追加しました。');
                        } else {
                            setFlash('success', 'このAI提案はすでに登録済みです。');
                        }

                        header('Location: index.php');
                        exit;
                    }
                } catch (EventConflictException $e) {
                    $conflicts = $e->getConflicts();
                    $error = '同じ時間帯に予定があります。内容を確認してください。';
                } catch (InvalidArgumentException $e) {
                    $error = 'AI提案の予定形式に問題があります: ' . $e->getMessage();
                } catch (Throwable $e) {
                    $error = 'データベースエラー: ' . publicDatabaseErrorMessage($e);
                }
            }
        }

        if ($action === 'message') {
            $message = readInputString($_POST, 'message', '');

            if ($message === '') {
                $error = 'メッセージを入力してください。';
            } else {
                try {
                    addChatUserMessage($message);
                    setChatPlans([]);
                    setChatProposedEvents([]);
                    setSelectedPlanId('');
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
        <?= csrfInput() ?>
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
                <?= csrfInput() ?>
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
        <div class="form-actions">
          <form method="post" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="confirm">
            <button class="primary-btn" type="submit">この内容でカレンダーに追加</button>
          </form>
          <?php if ($conflicts !== []): ?>
            <form method="post" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
              <?= csrfInput() ?>
              <input type="hidden" name="action" value="confirm">
              <input type="hidden" name="allow_conflict" value="1">
              <button class="secondary-btn" type="submit">それでも登録する</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" class="chat-reset-form">
      <?= csrfInput() ?>
      <input type="hidden" name="action" value="reset">
      <button class="text-btn" type="submit">新しい相談を始める</button>
    </form>
  </div>
</body>
</html>

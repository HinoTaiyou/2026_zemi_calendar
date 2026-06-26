<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/plan_constraints.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/chat_session.php';

$error = '';
$conflicts = [];
$usedDemo = isGeminiDemoMode();
$postAction = '';
$plansGenerated = false;
$eventsGenerated = false;
$messageAdded = false;

initChatSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = readInputString($_POST, 'action', 'message');
    $postAction = $action;

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
                $eventsGenerated = true;
            }
        }

        if ($action === 'confirm') {
            $proposedEvents = getChatProposedEvents();
            $allowConflict = readInputString($_POST, 'allow_conflict', '') === '1';

            if ($proposedEvents === []) {
                $error = '追加する予定案がありません。プランを選ぶか、AIと相談して予定案を作成してください。';
            } else {
                try {
                    // Tag this registration as a study-plan batch so the events can be
                    // managed together later (filter by source_batch_id).
                    $batchGoal = getStudyGoalState();
                    $batchPlanId = getSelectedPlanId();
                    $batchQual = (string) ($batchGoal['qualification_name'] ?? '');
                    $batchInfo = null;
                    if ($batchQual !== '' || $batchPlanId !== '') {
                        $batchPlan = $batchPlanId !== '' ? findChatPlanById($batchPlanId) : null;
                        $batchInfo = [
                            'id' => studyBatchId($batchQual, $batchPlanId !== '' ? $batchPlanId : 'events'),
                            'label' => studyBatchLabel($batchQual, (string) ($batchPlan['name'] ?? '')),
                        ];
                        foreach ($proposedEvents as $i => $pe) {
                            $proposedEvents[$i]['source_type'] = 'study_plan';
                            $proposedEvents[$i]['source_batch_id'] = $batchInfo['id'];
                            $proposedEvents[$i]['source_label'] = $batchInfo['label'];
                        }
                    }

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

                        if ($batchInfo !== null && $summary['inserted'] > 0) {
                            $_SESSION['last_study_batch'] = $batchInfo;
                        }

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
                    $messageAdded = true;

                    if ($result['constraints'] !== []) {
                        setChatConstraints($result['constraints']);
                    }

                    if ($result['plans'] !== []) {
                        setChatPlans($result['plans']);
                        setChatProposedEvents([]);
                        setSelectedPlanId('');
                        $plansGenerated = true;
                    } elseif ($result['events'] !== []) {
                        setChatPlans([]);
                        setChatProposedEvents($result['events']);
                        $eventsGenerated = true;
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
$studyGoal = getStudyGoalState();
$missingFields = missingStudyFields($studyGoal);
$goalRows = studyGoalDisplayRows($studyGoal);
$quickStarts = [
    '統計検定2級を取りたい',
    '基本情報を3か月で取りたい',
    'TOEICを650点まで上げたい',
    '週10時間で勉強したい',
    '就活向けの資格を相談したい',
];
$scrollTarget = determineChatScrollTarget(
    $postAction,
    $error,
    $plansGenerated,
    $eventsGenerated,
    $messageAdded
);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AI相談 - カレンダー</title>
  <link rel="stylesheet" href="style.css">
</head>
<body data-scroll-intent="<?= htmlspecialchars($scrollTarget, ENT_QUOTES, 'UTF-8') ?>">
  <header class="site-header">
    <div class="site-header-inner">
      <a class="site-brand" href="index.php">
        <span class="site-brand-mark">C</span>
        <span>Calm Focus Calendar</span>
      </a>
      <nav class="site-nav" aria-label="メインナビゲーション">
        <a class="site-nav-link" href="index.php">カレンダー</a>
        <a class="site-nav-link" href="chat.php" aria-current="page">AIチャット</a>
        <a class="site-nav-link" href="event_manage.php">予定を整理</a>
      </nav>
    </div>
  </header>

  <main class="app app--wide">
    <div class="page-head">
      <div class="page-head-titles">
        <h1 class="page-title">AIチャット</h1>
        <p class="page-subtitle">やりたいこと・期間・頻度を伝えると、3つのプランを提案します</p>
      </div>
      <div class="page-head-actions">
        <a class="back-link" href="index.php">← カレンダーに戻る</a>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div id="chat-feedback" class="alert alert-error" role="alert" aria-live="assertive"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="chat-layout">
      <div class="chat-main">
        <?php if ($usedDemo): ?>
          <div class="panel-note chat-note">デモモードです。config.php に Gemini API キーを設定すると本格的な相談ができます。</div>
        <?php endif; ?>

        <?php if ($constraintsSummary !== ''): ?>
          <div class="constraints-banner">
            <strong>学習条件:</strong> <?= htmlspecialchars($constraintsSummary, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <div class="chat-panel">
          <div class="chat-messages" id="chat-messages" role="log" aria-label="AIとの会話">
            <?php foreach ($messages as $message): ?>
              <?php
              $role = ($message['role'] ?? 'assistant') === 'user' ? 'user' : 'assistant';
              $class = $role === 'user' ? 'chat-bubble chat-bubble-user' : 'chat-bubble chat-bubble-ai';
              $label = $role === 'user' ? 'あなた' : 'AI';
              ?>
              <div class="<?= $class ?>" data-chat-role="<?= $role ?>">
                <p class="chat-label"><?= $label ?></p>
                <p class="chat-text"><?= nl2br(htmlspecialchars((string) ($message['content'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
              </div>
            <?php endforeach; ?>
          </div>

          <form class="chat-form" method="post">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="message">
            <label class="form-label" for="chat-message">取りたい資格や目標を入力してください</label>
            <textarea class="form-input form-textarea chat-input" id="chat-message" name="message" rows="3" placeholder="例：統計検定2級を、今日から週8時間で勉強したい" required></textarea>
            <div class="quickstart" aria-label="入力のヒント">
              <?php foreach ($quickStarts as $qs): ?>
                <button type="button" class="quickstart-chip" data-fill="<?= htmlspecialchars($qs, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($qs, ENT_QUOTES, 'UTF-8') ?></button>
              <?php endforeach; ?>
            </div>
            <button class="primary-btn" type="submit">送信</button>
          </form>
        </div>

        <?php if ($plans !== []): ?>
          <a class="plan-jump" href="#chat-plans"><?= count($plans) ?>件のプランを見る ↓</a>
        <?php endif; ?>
      </div>

      <aside class="chat-side" aria-label="相談内容とプラン">
        <div class="panel consult-panel">
          <h2 class="plan-section-title">現在の相談内容</h2>
          <dl class="consult-list">
            <?php foreach ($goalRows as $row): ?>
              <div class="consult-row">
                <dt><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></dt>
                <dd><?= htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8') ?></dd>
              </div>
            <?php endforeach; ?>
          </dl>
          <?php if ($missingFields !== [] && $plans === []): ?>
            <div class="missing-block">
              <p class="missing-title">プラン作成に必要な情報</p>
              <ul class="missing-list">
                <?php foreach ($missingFields as $field): ?>
                  <li><?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($plans !== []): ?>
          <div id="chat-plans" class="panel">
            <h2 class="plan-section-title">プランを選ぶ</h2>
            <p class="plan-section-desc">A/B/Cはすべて同じ項目で比較できます。ベースを選び、チャットで調整できます。</p>
            <div class="plan-grid">
              <?php foreach ($plans as $plan): ?>
                <?php
                $planId = (string) ($plan['id'] ?? '');
                $isSelected = $selectedPlanId === $planId;
                $fields = planCardFields($plan);
                ?>
                <div class="plan-card<?= $isSelected ? ' plan-card-selected' : '' ?>">
                  <div class="plan-card-header">
                    <span class="plan-id" aria-label="プラン<?= htmlspecialchars($planId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($planId, ENT_QUOTES, 'UTF-8') ?></span>
                    <h3 class="plan-name"><?= htmlspecialchars((string) ($plan['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                    <span class="plan-selected-badge">✓ 選択中</span>
                  </div>
                  <p class="plan-summary"><?= htmlspecialchars((string) ($plan['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                  <?php if ($fields !== []): ?>
                    <dl class="plan-fields">
                      <?php foreach ($fields as $field): ?>
                        <div class="plan-field<?= isset($field['status']) ? ' plan-field-' . $field['status'] : '' ?>">
                          <dt><?= htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8') ?></dt>
                          <dd><?= htmlspecialchars($field['value'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                      <?php endforeach; ?>
                    </dl>
                  <?php else: ?>
                    <p class="plan-count"><?= count($plan['events'] ?? []) ?>件の予定</p>
                  <?php endif; ?>
                  <form method="post">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="select_plan">
                    <input type="hidden" name="plan_id" value="<?= htmlspecialchars($planId, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="<?= $isSelected ? 'primary-btn' : 'secondary-btn' ?> plan-select-btn" type="submit">
                      <?= $isSelected ? '選択中' : 'このプランを選ぶ' ?>
                    </button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($proposedEvents !== []): ?>
          <?php
          $previewTotal = 0;
          $previewDates = [];
          foreach ($proposedEvents as $pe) {
              $previewTotal += (int) ($pe['duration_minutes'] ?? 0);
              $previewDates[] = (string) ($pe['date'] ?? '');
          }
          sort($previewDates);
          ?>
          <div id="chat-proposed-events" class="panel">
            <h2 class="plan-section-title">
              <?= $selectedPlanId !== '' ? 'プラン' . htmlspecialchars($selectedPlanId, ENT_QUOTES, 'UTF-8') . ' の予定' : '提案された予定' ?>
            </h2>
            <p class="plan-section-desc">登録前に内容を確認してください。問題なければ全期間まとめて追加します。</p>
            <dl class="plan-preview">
              <div class="plan-field"><dt>予定数</dt><dd><?= count($proposedEvents) ?>件</dd></div>
              <div class="plan-field"><dt>合計学習時間</dt><dd><?= htmlspecialchars(formatMinutesAsHours($previewTotal), ENT_QUOTES, 'UTF-8') ?></dd></div>
              <div class="plan-field"><dt>期間</dt><dd><?= htmlspecialchars(($previewDates[0] ?? '') . ' 〜 ' . ($previewDates[count($previewDates) - 1] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd></div>
            </dl>
            <p class="plan-section-desc">最初の数件のプレビュー:</p>
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
              <p class="plan-section-desc">…他 <?= count($proposedEvents) - 8 ?> 件</p>
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

      </aside>
    </div>

    <form method="post" class="chat-reset-form">
      <?= csrfInput() ?>
      <input type="hidden" name="action" value="reset">
      <button class="text-btn" type="submit">新しい相談を始める</button>
    </form>
  </main>
  <script src="assets/js/chat.js" defer></script>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/plan_constraints.php';
require_once __DIR__ . '/includes/plans.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/chat_session.php';
require_once __DIR__ . '/includes/site_header.php';

$error = '';
$conflicts = [];
$usedDemo = isGeminiDemoMode();
$step = 'questionnaire';

$planId = readInputInt($_GET, 'id', 0);
if ($planId <= 0) {
    $bannerPlan = getPlanForReviewBanner();
    if ($bannerPlan !== null) {
        $planId = (int) $bannerPlan['id'];
    }
}

$plan = $planId > 0 ? getAdoptedPlanById($planId) : null;

if ($plan === null) {
    setFlash('error', '振り返り対象のプランが見つかりませんでした。');
    header('Location: index.php');
    exit;
}

if (!canReviewPlan($plan)) {
    setFlash('error', 'まだ振り返りの時期ではありません。config.php で ALLOW_EARLY_PLAN_REVIEW を有効にすると、7日待たずに試せます。');
    header('Location: index.php');
    exit;
}

$reviewPlanIsDue = isPlanFollowUpDue($plan);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = readInputString($_POST, 'action', '');

    if (!isValidCsrfPost()) {
        $error = csrfErrorMessage();
    } else {
        if ($action === 'fit') {
            try {
                markPlanReviewFit((int) $plan['id']);
                resetChatSession();
                setFlash('success', 'このままのプランで続けましょう。お疲れさまでした！');
                header('Location: index.php');
                exit;
            } catch (Throwable $e) {
                $error = 'データベースエラー: ' . publicDatabaseErrorMessage($e);
            }
        }

        if ($action === 'not_fit') {
            try {
                markPlanReviewNotFit((int) $plan['id']);
                header('Location: review.php?id=' . (int) $plan['id']);
                exit;
            } catch (Throwable $e) {
                $error = 'データベースエラー: ' . publicDatabaseErrorMessage($e);
            }
        }

        if ($action === 'adjust') {
            $adjustment = readInputString($_POST, 'adjustment', '');
            $note = readInputString($_POST, 'review_note', '');

            if (!in_array($adjustment, ['tighten', 'loosen'], true)) {
                $error = '調整方向を選んでください。';
            } else {
                try {
                    savePlanReviewAdjustment((int) $plan['id'], $adjustment, $note);
                    $plan = getAdoptedPlanById((int) $plan['id']) ?? $plan;
                    $weeklyHours = calculateAdoptedPlanWeeklyHours($plan);
                    $aiResult = chatWithReviewAssistant(
                        $plan,
                        $adjustment,
                        [['role' => 'user', 'content' => buildReviewContextMessage($plan, $adjustment, $note)]],
                        $note,
                        $weeklyHours
                    );
                    startReviewChat($plan, $adjustment, $note, $aiResult);
                    header('Location: review.php?id=' . (int) $plan['id']);
                    exit;
                } catch (Throwable $e) {
                    $error = 'AIエラー: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'select_plan') {
            initReviewChatSession((int) $plan['id']);
            $selectedPlanId = readInputString($_POST, 'plan_id', '');
            $selectedPlan = findChatPlanById($selectedPlanId);

            if ($selectedPlan === null) {
                $error = '選択したプランが見つかりませんでした。';
            } else {
                setSelectedPlanId($selectedPlanId);
                setChatProposedEvents($selectedPlan['events'] ?? []);
            }
        }

        if ($action === 'confirm') {
            $proposedEvents = getChatProposedEvents();
            $allowConflict = readInputString($_POST, 'allow_conflict', '') === '1';

            if ($proposedEvents === []) {
                $error = '追加する予定案がありません。プランを選ぶか、AIと相談して調整してください。';
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
                        $oldPlanId = (int) $plan['id'];
                        deleteEventsByAdoptedPlanId($oldPlanId);
                        markPlanFollowUpDone($oldPlanId);

                        $selectedPlanId = getSelectedPlanId();
                        $selectedPlan = $selectedPlanId !== '' ? findChatPlanById($selectedPlanId) : null;
                        $newPlanId = $selectedPlanId !== '' ? $selectedPlanId : 'AI';
                        $newPlanName = (string) ($selectedPlan['name'] ?? '調整後プラン');
                        $newPlanSummary = (string) ($selectedPlan['summary'] ?? '');
                        $constraints = getChatConstraints() !== [] ? getChatConstraints() : ($plan['constraints'] ?? []);

                        $newAdoptedPlanId = createAdoptedPlan($newPlanId, $newPlanName, $newPlanSummary, $constraints);
                        $summary = addEvents($validatedEvents, $allowConflict, $newAdoptedPlanId);
                        resetChatSession();

                        if ($summary['inserted'] > 0 && $summary['skipped'] > 0) {
                            setFlash('success', 'プランを更新しました。'
                                . $summary['inserted'] . '件の予定を追加し、'
                                . $summary['skipped'] . '件は登録済みでした。');
                        } elseif ($summary['inserted'] > 0) {
                            setFlash('success', 'プランを更新し、' . $summary['inserted'] . '件の予定をカレンダーに反映しました。');
                        } else {
                            setFlash('success', 'プランを更新しました。');
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
            $adjustment = (string) ($plan['review_adjustment'] ?? '');

            if ($message === '') {
                $error = 'メッセージを入力してください。';
            } elseif ($adjustment === '') {
                $error = '振り返りの調整方向が設定されていません。';
            } else {
                try {
                    initReviewChatSession((int) $plan['id']);
                    addChatUserMessage($message);
                    setChatPlans([]);
                    setChatProposedEvents([]);
                    setSelectedPlanId('');

                    $weeklyHours = calculateAdoptedPlanWeeklyHours($plan);
                    $result = chatWithReviewAssistant(
                        $plan,
                        $adjustment,
                        getChatMessages(),
                        (string) ($plan['review_note'] ?? ''),
                        $weeklyHours
                    );
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

$plan = getAdoptedPlanById((int) $plan['id']) ?? $plan;

if (($plan['review_fit'] ?? '') === 'fit' || ($plan['follow_up_done_at'] ?? null) !== null) {
    $step = 'done';
} elseif (($plan['review_fit'] ?? '') === 'not_fit' && ($plan['review_adjustment'] ?? '') !== '') {
    $step = 'review_chat';
    if (!isReviewChatMode() || getReviewAdoptedPlanId() !== (int) $plan['id']) {
        $adjustment = (string) $plan['review_adjustment'];
        $note = (string) ($plan['review_note'] ?? '');
        $weeklyHours = calculateAdoptedPlanWeeklyHours($plan);
        $aiResult = chatWithReviewAssistant(
            $plan,
            $adjustment,
            [['role' => 'user', 'content' => buildReviewContextMessage($plan, $adjustment, $note)]],
            $note,
            $weeklyHours
        );
        startReviewChat($plan, $adjustment, $note, $aiResult);
    }
} elseif (($plan['review_fit'] ?? '') === 'not_fit') {
    $step = 'adjustment';
}

$messages = $step === 'review_chat' ? getChatMessages() : [];
$proposedEvents = $step === 'review_chat' ? getChatProposedEvents() : [];
$plans = $step === 'review_chat' ? getChatPlans() : [];
$constraints = $step === 'review_chat' ? getChatConstraints() : ($plan['constraints'] ?? []);
$selectedPlanId = $step === 'review_chat' ? getSelectedPlanId() : '';
$constraintsSummary = buildConstraintsSummary($constraints);
$planLabel = 'プラン' . ($plan['plan_id'] ?? '') . '「' . htmlspecialchars((string) ($plan['plan_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '」';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <?php renderSiteHead('プラン振り返り - カレンダー'); ?>
</head>
<body>
  <?php renderSiteHeader('calendar'); ?>

  <main class="app">
    <div class="page-head">
      <a class="back-link" href="index.php">← カレンダーに戻る</a>
      <div class="page-head-titles">
        <h1 class="page-title">プラン振り返り</h1>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($usedDemo && $step === 'review_chat'): ?>
      <div class="panel-note chat-note">デモモードです。config.php に Gemini API キーを設定すると本格的な相談ができます。</div>
    <?php endif; ?>

    <?php if (!$reviewPlanIsDue && $step !== 'done'): ?>
      <div class="panel-note review-early-note">早期確認モードです。本番では採用から<?= getFollowUpDays() ?>日後に振り返りが促されます。</div>
    <?php endif; ?>

    <?php if ($step === 'questionnaire'): ?>
      <div class="panel review-panel">
        <h2 class="panel-title">1週間お疲れさまでした</h2>
        <p class="panel-desc">
          <?= $planLabel ?> を試してから1週間が経ちました。<br>
          今の予定のペースは自分に合っていましたか？
        </p>
        <?php if (($plan['plan_summary'] ?? '') !== ''): ?>
          <p class="review-plan-summary"><?= htmlspecialchars((string) $plan['plan_summary'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <div class="review-choice-row">
          <form method="post">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="fit">
            <button class="primary-btn review-choice-btn" type="submit">合っていた</button>
          </form>
          <form method="post">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="not_fit">
            <button class="secondary-btn review-choice-btn" type="submit">合っていなかった</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($step === 'adjustment'): ?>
      <div class="panel review-panel">
        <h2 class="panel-title">プランを調整しましょう</h2>
        <p class="panel-desc">どちらの方向に近づけたいですか？</p>
        <form method="post" class="review-adjust-form">
          <?= csrfInput() ?>
          <input type="hidden" name="action" value="adjust">
          <div class="review-choice-row">
            <button class="primary-btn review-choice-btn" type="submit" name="adjustment" value="tighten">よりきつくする</button>
            <button class="secondary-btn review-choice-btn" type="submit" name="adjustment" value="loosen">よりゆるくする</button>
          </div>
          <label class="form-label" for="review_note">理由・感想（任意）</label>
          <textarea class="form-input form-textarea" id="review_note" name="review_note" rows="3" placeholder="例：平日夜は続かなかった / もっと勉強したい"></textarea>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($step === 'done'): ?>
      <div class="panel review-panel">
        <h2 class="panel-title">振り返り完了</h2>
        <p class="panel-desc">このプランの振り返りは完了しています。</p>
        <a class="primary-btn" href="index.php">カレンダーに戻る</a>
      </div>
    <?php endif; ?>

    <?php if ($step === 'review_chat'): ?>
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
          <textarea class="form-input form-textarea chat-input" name="message" rows="3" placeholder="例：土日だけにしたい / 1回を45分にしてほしい" required></textarea>
          <button class="primary-btn" type="submit">送信</button>
        </form>
      </div>

      <?php if ($plans !== []): ?>
        <div class="panel">
          <h2 class="panel-title">調整後のプランを選ぶ</h2>
          <p class="panel-desc">AIが提案した3つのプランです。ベースにしたいものを選んでから、チャットで微調整できます。</p>
          <div class="plan-grid">
            <?php foreach ($plans as $reviewPlan): ?>
              <?php
              $reviewPlanId = (string) ($reviewPlan['id'] ?? '');
              $weeklyHours = calculatePlanWeeklyHours($reviewPlan['events'] ?? []);
              $minHours = $constraints['min_hours_per_week'] ?? null;
              $meets = planMeetsConstraint($reviewPlan['events'] ?? [], is_float($minHours) ? $minHours : null);
              $isSelected = $selectedPlanId === $reviewPlanId;
              ?>
              <div class="plan-card<?= $isSelected ? ' plan-card-selected' : '' ?>">
                <div class="plan-card-header">
                  <span class="plan-id">プラン<?= htmlspecialchars($reviewPlanId, ENT_QUOTES, 'UTF-8') ?></span>
                  <h3 class="plan-name"><?= htmlspecialchars((string) ($reviewPlan['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                </div>
                <p class="plan-summary"><?= htmlspecialchars((string) ($reviewPlan['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="plan-hours">週あたり <?= $weeklyHours ?> 時間</p>
                <?php if ($minHours !== null): ?>
                  <p class="plan-status<?= $meets ? ' plan-status-ok' : ' plan-status-warn' ?>">
                    <?= $meets ? '✓ 必要時間を確保' : '⚠ 週' . $minHours . '時間以上が推奨' ?>
                  </p>
                <?php endif; ?>
                <p class="plan-count"><?= count($reviewPlan['events'] ?? []) ?>件の予定</p>
                <form method="post">
                  <?= csrfInput() ?>
                  <input type="hidden" name="action" value="select_plan">
                  <input type="hidden" name="plan_id" value="<?= htmlspecialchars($reviewPlanId, ENT_QUOTES, 'UTF-8') ?>">
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
          <p class="panel-desc">内容を確認して、問題なければカレンダーを更新してください。古いプランの予定は置き換えられます。</p>
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
              <button class="primary-btn" type="submit">この内容でカレンダーを更新</button>
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
    <?php endif; ?>
  </main>
  <?php renderSiteUserScripts(); ?>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/study_planner.php';

function initChatSession(): void
{
    if (!isset($_SESSION['chat_messages'])) {
        $_SESSION['chat_messages'] = [
            [
                'role' => 'assistant',
                'content' => 'こんにちは！資格・目標の学習計画づくりをお手伝いします。'
                    . '「統計検定2級を取りたい」「TOEICを650点まで」「基本情報を3か月で」など、'
                    . 'ざっくりで大丈夫です。一般的な学習時間の目安をお伝えしながら、'
                    . '使える曜日・時間を一緒に決めて、全期間の学習予定をカレンダーに登録できます。',
            ],
        ];
        $_SESSION['chat_proposed_events'] = [];
        $_SESSION['chat_plans'] = [];
        $_SESSION['chat_constraints'] = [];
        $_SESSION['chat_selected_plan_id'] = '';
        $_SESSION['chat_study_goal'] = studyGoalDefaults();
    }
}

function getStudyGoalState(): array
{
    initChatSession();

    $goal = $_SESSION['chat_study_goal'] ?? null;

    return is_array($goal) ? array_merge(studyGoalDefaults(), $goal) : studyGoalDefaults();
}

function setStudyGoalState(array $goal): void
{
    $_SESSION['chat_study_goal'] = $goal;
}

function mergeStudyGoalState(array $patch): array
{
    $goal = mergeStudyGoal(getStudyGoalState(), $patch);
    setStudyGoalState($goal);

    return $goal;
}

function getChatMessages(): array
{
    initChatSession();

    return $_SESSION['chat_messages'];
}

function getChatProposedEvents(): array
{
    initChatSession();

    return $_SESSION['chat_proposed_events'] ?? [];
}

function setChatProposedEvents(array $events): void
{
    $_SESSION['chat_proposed_events'] = ensureChatEventTokens($events);
}

function getChatPlans(): array
{
    initChatSession();

    return $_SESSION['chat_plans'] ?? [];
}

function setChatPlans(array $plans): void
{
    $_SESSION['chat_plans'] = ensureChatPlanTokens($plans);
}

function getChatConstraints(): array
{
    initChatSession();

    return $_SESSION['chat_constraints'] ?? [];
}

function setChatConstraints(array $constraints): void
{
    $_SESSION['chat_constraints'] = $constraints;
}

function getSelectedPlanId(): string
{
    initChatSession();

    return $_SESSION['chat_selected_plan_id'] ?? '';
}

function setSelectedPlanId(string $planId): void
{
    $_SESSION['chat_selected_plan_id'] = $planId;
}

function addChatUserMessage(string $content): void
{
    $_SESSION['chat_messages'][] = [
        'role' => 'user',
        'content' => $content,
    ];
}

function addChatAssistantMessage(string $content): void
{
    $_SESSION['chat_messages'][] = [
        'role' => 'assistant',
        'content' => $content,
    ];
}

function resetChatSession(): void
{
    unset(
        $_SESSION['chat_messages'],
        $_SESSION['chat_proposed_events'],
        $_SESSION['chat_plans'],
        $_SESSION['chat_constraints'],
        $_SESSION['chat_selected_plan_id'],
        $_SESSION['chat_study_goal'],
        $_SESSION['chat_mode'],
        $_SESSION['review_adopted_plan_id']
    );
    initChatSession();
}

function isReviewChatMode(): bool
{
    return ($_SESSION['chat_mode'] ?? '') === 'review';
}

function getReviewAdoptedPlanId(): int
{
    return (int) ($_SESSION['review_adopted_plan_id'] ?? 0);
}

function initReviewChatSession(int $adoptedPlanId): void
{
    $_SESSION['chat_mode'] = 'review';
    $_SESSION['review_adopted_plan_id'] = $adoptedPlanId;
}

function startReviewChat(array $plan, string $adjustment, string $note, array $aiResult): void
{
    require_once __DIR__ . '/plans.php';

    unset(
        $_SESSION['chat_messages'],
        $_SESSION['chat_proposed_events'],
        $_SESSION['chat_plans'],
        $_SESSION['chat_constraints'],
        $_SESSION['chat_selected_plan_id']
    );

    initReviewChatSession((int) $plan['id']);

    $intro = '1週間お疲れさまでした。'
        . 'プラン' . ($plan['plan_id'] ?? '') . '「' . ($plan['plan_name'] ?? '') . '」'
        . 'の振り返りですね。';

    $_SESSION['chat_messages'] = [
        [
            'role' => 'assistant',
            'content' => $intro,
        ],
        [
            'role' => 'user',
            'content' => buildReviewContextMessage($plan, $adjustment, $note),
        ],
        [
            'role' => 'assistant',
            'content' => (string) ($aiResult['reply'] ?? ''),
        ],
    ];
    $_SESSION['chat_proposed_events'] = [];
    $_SESSION['chat_plans'] = ensureChatPlanTokens($aiResult['plans'] ?? []);
    $_SESSION['chat_constraints'] = $aiResult['constraints'] ?? ($plan['constraints'] ?? []);
    $_SESSION['chat_selected_plan_id'] = '';
}

function countChatUserMessages(): int
{
    return count(array_filter(
        getChatMessages(),
        static fn(array $message): bool => ($message['role'] ?? '') === 'user'
    ));
}

function findChatPlanById(string $planId): ?array
{
    foreach (getChatPlans() as $plan) {
        if (($plan['id'] ?? '') === $planId) {
            return $plan;
        }
    }

    return null;
}

function createProposalItemToken(): string
{
    return 'ai_' . bin2hex(random_bytes(30));
}

function hasValidProposalItemToken(mixed $value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1;
}

function ensureChatEventTokens(array $events): array
{
    foreach ($events as $index => $event) {
        if (!is_array($event)) {
            continue;
        }

        if (!hasValidProposalItemToken($event['ai_idempotency_key'] ?? null)) {
            $event['ai_idempotency_key'] = createProposalItemToken();
        }

        $events[$index] = $event;
    }

    return $events;
}

/**
 * Decide where the chat page should scroll after a request, expressed as a
 * semantic intent (resolved to a real element in assets/js/chat.js):
 *   ''           - no scroll (initial GET, reload, reset)
 *   'feedback'   - the error/notice banner
 *   'plans'      - the freshly generated plan list
 *   'events'     - the freshly generated proposed-events panel
 *   'latest-reply' - the newest AI reply inside the chat scroll container
 *
 * Only what happened in THIS request matters: plans left over in the session
 * from an earlier turn must not pull the view to the plan list.
 */
function determineChatScrollTarget(string $action, string $error, bool $plansGenerated, bool $eventsGenerated, bool $messageAdded): string
{
    if ($action === '') {
        return '';
    }
    if ($error !== '') {
        return 'feedback';
    }
    if ($plansGenerated) {
        return 'plans';
    }
    if ($eventsGenerated) {
        return 'events';
    }
    if ($messageAdded) {
        return 'latest-reply';
    }

    return '';
}

function ensureChatPlanTokens(array $plans): array
{
    foreach ($plans as $index => $plan) {
        if (!is_array($plan)) {
            continue;
        }

        $events = $plan['events'] ?? [];
        if (is_array($events)) {
            $plan['events'] = ensureChatEventTokens($events);
        }

        $plans[$index] = $plan;
    }

    return $plans;
}

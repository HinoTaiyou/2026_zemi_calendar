<?php
declare(strict_types=1);

function initChatSession(): void
{
    if (!isset($_SESSION['chat_messages'])) {
        $_SESSION['chat_messages'] = [
            [
                'role' => 'assistant',
                'content' => 'こんにちは！予定づくりを一緒に考えましょう。'
                    . 'やりたいこと、期間、頻度を教えてください。'
                    . '資格勉強の場合は「必要時間」と「試験日」も教えてもらえると、'
                    . 'しっかり確保したプランA/B/Cを提案できます。',
            ],
        ];
        $_SESSION['chat_proposed_events'] = [];
        $_SESSION['chat_plans'] = [];
        $_SESSION['chat_constraints'] = [];
        $_SESSION['chat_selected_plan_id'] = '';
    }
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
        $_SESSION['chat_selected_plan_id']
    );
    initChatSession();
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

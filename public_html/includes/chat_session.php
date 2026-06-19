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
    $_SESSION['chat_proposed_events'] = $events;
}

function getChatPlans(): array
{
    initChatSession();

    return $_SESSION['chat_plans'] ?? [];
}

function setChatPlans(array $plans): void
{
    $_SESSION['chat_plans'] = $plans;
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

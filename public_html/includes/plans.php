<?php
declare(strict_types=1);

require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/plan_constraints.php';

function getFollowUpDays(): int
{
    $days = (int) appConfigValue('FOLLOW_UP_DAYS', '7');

    return max(0, min(365, $days));
}

function isEarlyPlanReviewAllowed(): bool
{
    return appConfigValue('ALLOW_EARLY_PLAN_REVIEW', '0') === '1';
}

function mapAdoptedPlanRow(array $row): array
{
    $constraints = [];
    $rawConstraints = $row['constraints'] ?? null;
    if (is_string($rawConstraints) && $rawConstraints !== '') {
        $decoded = json_decode($rawConstraints, true);
        if (is_array($decoded)) {
            $constraints = $decoded;
        }
    } elseif (is_array($rawConstraints)) {
        $constraints = $rawConstraints;
    }

    $adoptedAt = $row['adopted_at'] ?? '';
    if ($adoptedAt instanceof DateTimeInterface) {
        $adoptedAt = $adoptedAt->format('Y-m-d H:i:s');
    } else {
        $adoptedAt = substr((string) $adoptedAt, 0, 19);
    }

    $followUpDueAt = $row['follow_up_due_at'] ?? '';
    if ($followUpDueAt instanceof DateTimeInterface) {
        $followUpDueAt = $followUpDueAt->format('Y-m-d H:i:s');
    } else {
        $followUpDueAt = substr((string) $followUpDueAt, 0, 19);
    }

    $followUpDoneAt = $row['follow_up_done_at'] ?? null;
    if ($followUpDoneAt instanceof DateTimeInterface) {
        $followUpDoneAt = $followUpDoneAt->format('Y-m-d H:i:s');
    } elseif ($followUpDoneAt !== null) {
        $followUpDoneAt = substr((string) $followUpDoneAt, 0, 19);
    }

    return [
        'id' => (int) $row['id'],
        'plan_id' => (string) ($row['plan_id'] ?? ''),
        'plan_name' => (string) ($row['plan_name'] ?? ''),
        'plan_summary' => (string) ($row['plan_summary'] ?? ''),
        'constraints' => $constraints,
        'adopted_at' => $adoptedAt,
        'follow_up_due_at' => $followUpDueAt,
        'follow_up_done_at' => $followUpDoneAt,
        'review_fit' => $row['review_fit'] ?? null,
        'review_adjustment' => $row['review_adjustment'] ?? null,
        'review_note' => $row['review_note'] ?? null,
        'status' => (string) ($row['status'] ?? 'active'),
    ];
}

function encodeConstraintsForDb(array $constraints): string
{
    $payload = [
        'required_hours' => $constraints['required_hours'] ?? null,
        'exam_date' => $constraints['exam_date'] ?? null,
        'label' => $constraints['label'] ?? null,
        'daily_hours' => $constraints['daily_hours'] ?? null,
        'time_preference' => $constraints['time_preference'] ?? null,
        'min_hours_per_week' => $constraints['min_hours_per_week'] ?? null,
    ];

    return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';
}

function archiveActiveAdoptedPlans(): void
{
    dbExec(
        "UPDATE adopted_plans SET status = 'archived' WHERE status = 'active'"
    );
}

function createAdoptedPlan(
    string $planId,
    string $planName,
    string $planSummary,
    array $constraints
): int {
    archiveActiveAdoptedPlans();

    $row = dbFetchOne(
        'INSERT INTO adopted_plans (
            plan_id,
            plan_name,
            plan_summary,
            constraints,
            follow_up_due_at
         ) VALUES ($1, $2, $3, $4::jsonb, NOW() + make_interval(days => $5))
         RETURNING id',
        [
            $planId,
            $planName,
            $planSummary,
            encodeConstraintsForDb($constraints),
            getFollowUpDays(),
        ]
    );

    return (int) ($row['id'] ?? 0);
}

function getAdoptedPlanById(int $id): ?array
{
    $row = dbFetchOne('SELECT * FROM adopted_plans WHERE id = $1', [$id]);

    return $row ? mapAdoptedPlanRow($row) : null;
}

function getPendingFollowUpPlan(): ?array
{
    $row = dbFetchOne(
        "SELECT * FROM adopted_plans
         WHERE status = 'active'
           AND follow_up_done_at IS NULL
           AND follow_up_due_at <= NOW()
         ORDER BY follow_up_due_at ASC
         LIMIT 1"
    );

    return $row ? mapAdoptedPlanRow($row) : null;
}

function getActiveAdoptedPlan(): ?array
{
    $row = dbFetchOne(
        "SELECT * FROM adopted_plans
         WHERE status = 'active'
           AND follow_up_done_at IS NULL
         ORDER BY adopted_at DESC
         LIMIT 1"
    );

    return $row ? mapAdoptedPlanRow($row) : null;
}

function isPlanFollowUpDue(array $plan): bool
{
    $dueAt = (string) ($plan['follow_up_due_at'] ?? '');
    if ($dueAt === '') {
        return false;
    }

    return strtotime($dueAt) <= time();
}

function canReviewPlan(array $plan): bool
{
    if (($plan['follow_up_done_at'] ?? null) !== null) {
        return false;
    }

    if (($plan['status'] ?? '') !== 'active') {
        return false;
    }

    return isPlanFollowUpDue($plan) || isEarlyPlanReviewAllowed();
}

function getPlanForReviewBanner(): ?array
{
    $pending = getPendingFollowUpPlan();
    if ($pending !== null) {
        return $pending;
    }

    if (!isEarlyPlanReviewAllowed()) {
        return null;
    }

    return getActiveAdoptedPlan();
}

function markPlanReviewFit(int $id): void
{
    dbExec(
        "UPDATE adopted_plans
         SET review_fit = 'fit',
             follow_up_done_at = NOW(),
             status = 'reviewed'
         WHERE id = $1",
        [$id]
    );
}

function markPlanReviewNotFit(int $id): void
{
    dbExec(
        "UPDATE adopted_plans SET review_fit = 'not_fit' WHERE id = $1",
        [$id]
    );
}

function savePlanReviewAdjustment(int $id, string $adjustment, string $note = ''): void
{
    if (!in_array($adjustment, ['tighten', 'loosen'], true)) {
        throw new InvalidArgumentException('調整方向が不正です。');
    }

    dbExec(
        "UPDATE adopted_plans
         SET review_fit = 'not_fit',
             review_adjustment = $2,
             review_note = $3
         WHERE id = $1",
        [$id, $adjustment, $note !== '' ? $note : null]
    );
}

function markPlanFollowUpDone(int $id): void
{
    dbExec(
        'UPDATE adopted_plans
         SET follow_up_done_at = NOW(),
             status = CASE WHEN status = \'active\' THEN \'reviewed\' ELSE status END
         WHERE id = $1',
        [$id]
    );
}

function markPlanReviewed(int $id): void
{
    dbExec(
        "UPDATE adopted_plans SET status = 'reviewed' WHERE id = $1",
        [$id]
    );
}

function linkEventsToAdoptedPlan(int $adoptedPlanId, array $eventIds): void
{
    if ($eventIds === []) {
        return;
    }

    foreach ($eventIds as $eventId) {
        dbExec(
            'UPDATE events SET adopted_plan_id = $1 WHERE id = $2',
            [$adoptedPlanId, (int) $eventId]
        );
    }
}

function deleteEventsByAdoptedPlanId(int $adoptedPlanId): int
{
    $result = dbQuery('DELETE FROM events WHERE adopted_plan_id = $1', [$adoptedPlanId]);

    return pg_affected_rows($result);
}

function getEventsForAdoptedPlan(int $adoptedPlanId): array
{
    $rows = dbFetchAll(
        'SELECT * FROM events WHERE adopted_plan_id = $1 ORDER BY event_date ASC, event_time ASC',
        [$adoptedPlanId]
    );

    return array_map('mapEventRow', $rows);
}

function calculateAdoptedPlanWeeklyHours(array $plan): float
{
    $events = getEventsForAdoptedPlan((int) $plan['id']);
    if ($events === []) {
        return 0.0;
    }

    return calculatePlanWeeklyHours($events);
}

function buildReviewContextMessage(array $plan, string $adjustment, string $note = ''): string
{
    $constraints = $plan['constraints'] ?? [];
    $weeklyHours = calculateAdoptedPlanWeeklyHours($plan);
    $constraintsSummary = buildConstraintsSummary($constraints);
    $adjustmentLabel = $adjustment === 'tighten' ? 'よりきつくする' : 'よりゆるくする';
    $planLabel = 'プラン' . ($plan['plan_id'] ?? '') . '「' . ($plan['plan_name'] ?? '') . '」';

    $lines = [
        '1週間試したプランの振り返りです。',
        '採用プラン: ' . $planLabel,
        '概要: ' . ($plan['plan_summary'] ?? ''),
        '採用日: ' . substr((string) ($plan['adopted_at'] ?? ''), 0, 10),
        '現在の週あたり学習時間: 約' . $weeklyHours . '時間',
        '調整希望: ' . $adjustmentLabel,
    ];

    if ($constraintsSummary !== '') {
        $lines[] = '当初の条件: ' . $constraintsSummary;
    }

    if ($note !== '') {
        $lines[] = '理由・感想: ' . $note;
    }

    $lines[] = 'このプランに合うよう、調整した3つのプランを提案してください。';

    return implode("\n", $lines);
}

function reviewAdjustmentLabel(string $adjustment): string
{
    return match ($adjustment) {
        'tighten' => 'よりきつくする',
        'loosen' => 'よりゆるくする',
        default => '',
    };
}

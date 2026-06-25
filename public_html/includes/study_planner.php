<?php
declare(strict_types=1);

require_once __DIR__ . '/validation.php';

/*
 * Qualification study planner — pure scheduling logic.
 *
 * Responsibilities live in PHP (never the AI): the current date/timezone, date
 * validation, week math, expanding a weekly template across the FULL date range,
 * total-time accounting, last-session trimming, and safety caps. The AI only
 * fills a structured goal; these functions turn that goal into concrete events.
 */

const STUDY_TIMEZONE = 'Asia/Tokyo';
const STUDY_MAX_MONTHS = 18;
const STUDY_MAX_EVENTS = 500;
const STUDY_MIN_WEEKLY_HOURS = 1;
const STUDY_MAX_WEEKLY_HOURS = 60;
const STUDY_MAX_AVAILABILITY = 21;
const STUDY_WEEKDAYS_JA = [1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日'];

/** Injectable "now" so tests never depend on the system clock. */
function setAppNowForTest(?DateTimeImmutable $now): void
{
    if ($now === null) {
        unset($GLOBALS['app_now_for_test']);
        return;
    }
    $GLOBALS['app_now_for_test'] = $now;
}

function appNow(): DateTimeImmutable
{
    $override = $GLOBALS['app_now_for_test'] ?? null;
    if ($override instanceof DateTimeImmutable) {
        return $override;
    }

    return new DateTimeImmutable('now', new DateTimeZone(STUDY_TIMEZONE));
}

/** Text block injected into every Gemini request so the model knows "today". */
function buildPlanningDateContext(DateTimeImmutable $now): string
{
    $iso = (int) $now->format('N');
    $ja = STUDY_WEEKDAYS_JA[$iso] ?? '';

    return "現在日時: " . $now->format('Y-m-d H:i') . "\n"
        . "今日の日付: " . $now->format('Y-m-d') . "\n"
        . "タイムゾーン: " . STUDY_TIMEZONE . "\n"
        . "曜日: {$ja}曜日 (ISO {$iso})";
}

function studyGoalDefaults(): array
{
    return [
        'qualification_name' => null,
        'qualification_level' => null,
        'goal_type' => null,
        'current_level' => null,
        'current_score' => null,
        'target_score' => null,
        'estimated_hours' => null,
        'selected_total_hours' => null,
        'start_date' => null,
        'target_date' => null,
        'duration_months' => null,
        'desired_weekly_hours' => null,
        'weekly_hours_mode' => null,
        'availability' => [],
        'preferred_session_minutes' => null,
        'planning_status' => 'collecting',
    ];
}

function clampInt(mixed $value, int $min, int $max): ?int
{
    if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
        if (is_float($value)) {
            $value = (int) round($value);
        } else {
            return null;
        }
    }
    $int = (int) $value;
    if ($int < $min || $int > $max) {
        return null;
    }
    return $int;
}

function normalizeAvailability(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $slots = [];
    $seen = [];
    foreach ($raw as $entry) {
        if (!is_array($entry) || count($slots) >= STUDY_MAX_AVAILABILITY) {
            continue;
        }
        $weekday = clampInt($entry['weekday'] ?? null, 1, 7);
        $start = scalarToStringOrNull($entry['start'] ?? null);
        $end = scalarToStringOrNull($entry['end'] ?? null);
        if ($weekday === null || $start === null || $end === null) {
            continue;
        }
        if (!isStrictTimeHm($start) || !isStrictTimeHm($end)) {
            continue;
        }
        if (timeToMinutes($end) - timeToMinutes($start) < EVENT_DURATION_MINUTES_MIN) {
            continue;
        }
        $key = $weekday . '|' . $start . '|' . $end;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $slots[] = ['weekday' => $weekday, 'start' => $start, 'end' => $end];
    }

    usort($slots, static fn(array $a, array $b): int => [$a['weekday'], $a['start']] <=> [$b['weekday'], $b['start']]);

    return $slots;
}

function normalizeEstimatedHours(mixed $raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }
    $min = clampInt($raw['min'] ?? null, 1, 100000);
    $max = clampInt($raw['max'] ?? null, 1, 100000);
    $recommended = clampInt($raw['recommended'] ?? null, 1, 100000);
    if ($min !== null && $max !== null && $min > $max) {
        [$min, $max] = [$max, $min];
    }
    if ($recommended === null) {
        if ($min !== null && $max !== null) {
            $recommended = (int) round(($min + $max) / 2);
        } else {
            $recommended = $min ?? $max;
        }
    }
    if ($min === null && $max === null && $recommended === null) {
        return null;
    }

    $source = scalarToStringOrNull($raw['source'] ?? null);
    $confidence = scalarToStringOrNull($raw['confidence'] ?? null);
    if (!in_array($confidence, ['low', 'medium', 'high'], true)) {
        $confidence = 'medium';
    }
    $assumptions = [];
    if (is_array($raw['assumptions'] ?? null)) {
        foreach ($raw['assumptions'] as $a) {
            $s = scalarToStringOrNull($a);
            if ($s !== null && $s !== '' && eventStringLength($s) <= 200) {
                $assumptions[] = $s;
            }
        }
    }

    return [
        'min' => $min,
        'max' => $max,
        'recommended' => $recommended,
        'source' => $source !== null && $source !== '' ? mb_substr($source, 0, 40) : 'ai',
        'confidence' => $confidence,
        'assumptions' => array_slice($assumptions, 0, 5),
    ];
}

/**
 * Validate and normalize the AI's goal_patch. Invalid fields are dropped (never
 * trusted); past dates are rejected so they cannot reach the calendar.
 */
function validateStudyGoalPatch(array $patch, DateTimeImmutable $now): array
{
    $today = $now->format('Y-m-d');
    $out = [];

    $name = scalarToStringOrNull($patch['qualification_name'] ?? null);
    if ($name !== null && $name !== '') {
        $out['qualification_name'] = mb_substr($name, 0, 120);
    }

    $level = scalarToStringOrNull($patch['qualification_level'] ?? null);
    if ($level !== null && $level !== '') {
        $out['qualification_level'] = mb_substr($level, 0, 60);
    }

    $goalType = scalarToStringOrNull($patch['goal_type'] ?? null);
    if (in_array($goalType, ['pass_fail', 'score', 'undecided'], true)) {
        $out['goal_type'] = $goalType;
    }

    $currentLevel = scalarToStringOrNull($patch['current_level'] ?? null);
    if ($currentLevel !== null && $currentLevel !== '') {
        $out['current_level'] = mb_substr($currentLevel, 0, 60);
    }

    foreach (['current_score', 'target_score'] as $scoreKey) {
        if (array_key_exists($scoreKey, $patch) && $patch[$scoreKey] !== null) {
            $score = clampInt($patch[$scoreKey], 0, 1000000);
            if ($score !== null) {
                $out[$scoreKey] = $score;
            }
        }
    }

    $est = normalizeEstimatedHours($patch['estimated_hours'] ?? null);
    if ($est !== null) {
        $out['estimated_hours'] = $est;
    }

    $total = clampInt($patch['selected_total_hours'] ?? null, 1, STUDY_MAX_WEEKLY_HOURS * 5 * STUDY_MAX_MONTHS);
    if ($total !== null) {
        $out['selected_total_hours'] = $total;
    }

    foreach (['start_date', 'target_date'] as $dateKey) {
        $date = scalarToStringOrNull($patch[$dateKey] ?? null);
        if ($date !== null && isStrictDateYmd($date) && $date >= $today) {
            $out[$dateKey] = $date;
        }
    }
    // target before start is incoherent -> drop target.
    if (isset($out['start_date'], $out['target_date']) && $out['target_date'] < $out['start_date']) {
        unset($out['target_date']);
    }

    $months = clampInt($patch['duration_months'] ?? null, 1, STUDY_MAX_MONTHS);
    if ($months !== null) {
        $out['duration_months'] = $months;
    }

    if (array_key_exists('desired_weekly_hours', $patch) && $patch['desired_weekly_hours'] !== null) {
        $weekly = $patch['desired_weekly_hours'];
        if (is_numeric($weekly)) {
            $weeklyHours = (float) $weekly;
            if ($weeklyHours >= STUDY_MIN_WEEKLY_HOURS && $weeklyHours <= STUDY_MAX_WEEKLY_HOURS) {
                $out['desired_weekly_hours'] = round($weeklyHours, 1);
            }
        }
    }

    $mode = scalarToStringOrNull($patch['weekly_hours_mode'] ?? null);
    if (in_array($mode, ['desired', 'maximum', 'minimum', 'unknown'], true)) {
        $out['weekly_hours_mode'] = $mode;
    }

    if (array_key_exists('availability', $patch)) {
        $availability = normalizeAvailability($patch['availability']);
        if ($availability !== []) {
            $out['availability'] = $availability;
        }
    }

    $session = clampInt($patch['preferred_session_minutes'] ?? null, EVENT_DURATION_MINUTES_MIN, EVENT_DURATION_MINUTES_MAX);
    if ($session !== null) {
        $out['preferred_session_minutes'] = $session;
    }

    return $out;
}

function mergeStudyGoal(array $base, array $patch): array
{
    foreach ($patch as $key => $value) {
        if ($value === null) {
            continue;
        }
        if ($key === 'availability' && $value === []) {
            continue;
        }
        $base[$key] = $value;
    }

    return $base;
}

/** Total study minutes to schedule: explicit selection, else recommended estimate. */
function studyTotalMinutes(array $goal): ?int
{
    $hours = $goal['selected_total_hours'] ?? null;
    if (is_int($hours) && $hours > 0) {
        return $hours * 60;
    }
    $recommended = $goal['estimated_hours']['recommended'] ?? null;
    if (is_int($recommended) && $recommended > 0) {
        return $recommended * 60;
    }

    return null;
}

/** Sum of weekly available minutes across all availability windows. */
function weeklyAvailableMinutes(array $availability): int
{
    $total = 0;
    foreach ($availability as $slot) {
        $total += timeToMinutes($slot['end']) - timeToMinutes($slot['start']);
    }

    return $total;
}

/**
 * Resolve [start, end] dates. start is never in the past; end comes from an
 * explicit target date, a calendar-month duration, or (weekly mode) is derived
 * from total minutes / weekly minutes. Returns null when undeterminable.
 */
function resolveStudyWindow(array $goal, DateTimeImmutable $now, ?int $weeklyMinutesOverride = null): ?array
{
    $tz = $now->getTimezone();
    $today = $now->setTime(0, 0);
    $start = $today;
    if (!empty($goal['start_date']) && isStrictDateYmd((string) $goal['start_date'])) {
        $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $goal['start_date'], $tz);
        if ($candidate instanceof DateTimeImmutable && $candidate >= $today) {
            $start = $candidate;
        }
    }

    $end = null;
    if (!empty($goal['target_date']) && isStrictDateYmd((string) $goal['target_date'])) {
        $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $goal['target_date'], $tz);
        if ($candidate instanceof DateTimeImmutable && $candidate >= $start) {
            $end = $candidate;
        }
    }
    if ($end === null && !empty($goal['duration_months'])) {
        $months = (int) $goal['duration_months'];
        if ($months >= 1) {
            $end = $start->modify('+' . $months . ' months');
        }
    }
    if ($end === null) {
        $totalMinutes = studyTotalMinutes($goal);
        $weeklyMinutes = $weeklyMinutesOverride;
        if ($weeklyMinutes === null && !empty($goal['desired_weekly_hours'])) {
            $weeklyMinutes = (int) round(((float) $goal['desired_weekly_hours']) * 60);
        }
        if ($totalMinutes !== null && $weeklyMinutes !== null && $weeklyMinutes > 0) {
            $weeks = (int) ceil($totalMinutes / $weeklyMinutes);
            $end = $start->modify('+' . max(1, $weeks) . ' weeks');
        }
    }

    if ($end === null) {
        return null;
    }

    // Cap the horizon.
    $maxEnd = $start->modify('+' . STUDY_MAX_MONTHS . ' months');
    if ($end > $maxEnd) {
        $end = $maxEnd;
    }

    return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
}

/**
 * Build a weekly template (one session per availability window) that targets
 * ~weeklyMinutes, using sessionMinutes as the preferred chunk and optionally
 * restricting to the highest-capacity days (for the intensive method).
 */
function buildWeeklyTemplate(array $availability, int $weeklyMinutes, int $sessionMinutes, ?int $dayLimit = null): array
{
    $windows = [];
    foreach ($availability as $slot) {
        $windows[] = [
            'weekday' => $slot['weekday'],
            'start' => $slot['start'],
            'cap' => timeToMinutes($slot['end']) - timeToMinutes($slot['start']),
        ];
    }
    if ($windows === []) {
        return [];
    }

    if ($dayLimit !== null && $dayLimit < count($windows)) {
        usort($windows, static fn(array $a, array $b): int => $b['cap'] <=> $a['cap']);
        $windows = array_slice($windows, 0, max(1, $dayLimit));
        usort($windows, static fn(array $a, array $b): int => [$a['weekday'], $a['start']] <=> [$b['weekday'], $b['start']]);
    }

    $remaining = $weeklyMinutes;
    $entries = [];
    foreach ($windows as $i => $w) {
        if ($remaining <= 0) {
            break;
        }
        $dur = min($sessionMinutes, $w['cap'], $remaining);
        if ($dur < EVENT_DURATION_MINUTES_MIN) {
            continue;
        }
        $entries[$i] = ['weekday' => $w['weekday'], 'start' => $w['start'], 'duration_minutes' => $dur];
        $remaining -= $dur;
    }

    // Second pass: extend existing sessions up to their window cap to absorb the rest.
    if ($remaining > 0) {
        foreach ($entries as $i => $entry) {
            if ($remaining <= 0) {
                break;
            }
            $extra = min($windows[$i]['cap'] - $entry['duration_minutes'], $remaining);
            if ($extra > 0) {
                $entries[$i]['duration_minutes'] += $extra;
                $remaining -= $extra;
            }
        }
    }

    return array_values($entries);
}

/**
 * THE full-range expansion. Walk every date from start..end, emit one event per
 * matching template entry, skip past slots, accumulate integer minutes, and trim
 * the final session so the total does not overshoot. Enforces the event cap.
 */
function expandTemplateAcrossRange(
    array $template,
    string $startDate,
    string $endDate,
    int $totalMinutes,
    DateTimeImmutable $now,
    string $title,
    int $maxEvents = STUDY_MAX_EVENTS
): array {
    if ($template === []) {
        return [];
    }

    $byWeekday = [];
    foreach ($template as $entry) {
        $byWeekday[$entry['weekday']][] = $entry;
    }
    foreach ($byWeekday as &$entries) {
        usort($entries, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
    }
    unset($entries);

    $tz = $now->getTimezone();
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $tz);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $tz);
    if ($start === false || $end === false || $start > $end) {
        return [];
    }

    $occurrences = [];
    $accumulated = 0;
    $day = $start;
    while ($day <= $end && count($occurrences) < $maxEvents) {
        $iso = (int) $day->format('N');
        if (isset($byWeekday[$iso])) {
            foreach ($byWeekday[$iso] as $entry) {
                if ($accumulated >= $totalMinutes || count($occurrences) >= $maxEvents) {
                    break;
                }
                $slotStart = $day->setTime(
                    (int) substr($entry['start'], 0, 2),
                    (int) substr($entry['start'], 3, 2)
                );
                if ($slotStart <= $now) {
                    continue; // past slot (e.g. earlier today) -> skip to a future one
                }

                $duration = $entry['duration_minutes'];
                $remainingTotal = $totalMinutes - $accumulated;
                if ($duration > $remainingTotal) {
                    if ($remainingTotal < EVENT_DURATION_MINUTES_MIN) {
                        $accumulated = $totalMinutes;
                        break; // remainder too small for its own session
                    }
                    $duration = $remainingTotal;
                }

                $occurrences[] = [
                    'date' => $day->format('Y-m-d'),
                    'time' => $entry['start'],
                    'duration_minutes' => $duration,
                    'title' => $title,
                ];
                $accumulated += $duration;
            }
        }
        if ($accumulated >= $totalMinutes) {
            break;
        }
        $day = $day->modify('+1 day');
    }

    return $occurrences;
}

function summarizeOccurrences(array $occurrences, int $targetMinutes): array
{
    $count = count($occurrences);
    $total = 0;
    $dates = [];
    $weekdayTimes = [];
    foreach ($occurrences as $occ) {
        $total += (int) $occ['duration_minutes'];
        $dates[] = $occ['date'];
        $iso = (int) DateTimeImmutable::createFromFormat('!Y-m-d', $occ['date'])->format('N');
        $weekdayTimes[$iso . '|' . $occ['time']] = (STUDY_WEEKDAYS_JA[$iso] ?? '') . $occ['time'];
    }
    sort($dates);
    $first = $dates[0] ?? null;
    $last = $dates !== [] ? $dates[count($dates) - 1] : null;

    $weeklyMinutes = 0;
    if ($first !== null && $last !== null) {
        $days = (int) DateTimeImmutable::createFromFormat('!Y-m-d', $first)
            ->diff(DateTimeImmutable::createFromFormat('!Y-m-d', $last))->days;
        $weeks = max(1, (int) ceil(($days + 1) / 7));
        $weeklyMinutes = (int) round($total / $weeks);
    }

    ksort($weekdayTimes);

    return [
        'count' => $count,
        'total_minutes' => $total,
        'weekly_minutes' => $weeklyMinutes,
        'first_date' => $first,
        'last_date' => $last,
        'weekday_time_summary' => implode(', ', array_values($weekdayTimes)),
        'feasible' => $total >= $targetMinutes,
        'shortfall_minutes' => max(0, $targetMinutes - $total),
    ];
}

function studyOccurrenceIdempotencyKey(string $planId, string $qualification, array $event): string
{
    $material = implode('|', [
        $planId,
        $qualification,
        $event['date'] ?? '',
        $event['time'] ?? '',
        (string) ($event['duration_minutes'] ?? ''),
        $event['title'] ?? '',
    ]);

    return 'sp_' . substr(hash('sha256', $material), 0, 60);
}

/** Method presets that give A/B/C meaningfully different shapes. */
function studyPlanMethods(): array
{
    return [
        'A' => ['name' => '短期集中', 'summary' => '長めのセッションで早めに仕上げる', 'session' => 150, 'weekly_factor' => 1.25],
        'B' => ['name' => 'バランス', 'summary' => '利用可能日に均等配分（おすすめ）', 'session' => 90, 'weekly_factor' => 1.0],
        'C' => ['name' => '余裕重視', 'summary' => '短めのセッションを無理なく', 'session' => 60, 'weekly_factor' => 0.8],
    ];
}

/**
 * Build the A/B/C plan options. Each plan carries the FULL expansion plus a
 * fixed stats block so the cards always render the same fields.
 */
function buildStudyPlanOptions(array $goal, DateTimeImmutable $now): array
{
    $availability = $goal['availability'] ?? [];
    $totalMinutes = studyTotalMinutes($goal);
    if ($availability === [] || $totalMinutes === null) {
        return [];
    }

    $weeklyAvail = weeklyAvailableMinutes($availability);
    if ($weeklyAvail < EVENT_DURATION_MINUTES_MIN) {
        return [];
    }

    $desiredWeekly = null;
    if (!empty($goal['desired_weekly_hours'])) {
        $desiredWeekly = (int) round(((float) $goal['desired_weekly_hours']) * 60);
    }
    $deadlineMode = !empty($goal['target_date']) || !empty($goal['duration_months']);

    // Minimum weekly minutes required to finish on time (deadline mode only).
    $requiredWeekly = null;
    if ($deadlineMode) {
        $fixedWindow = resolveStudyWindow($goal, $now);
        if ($fixedWindow === null) {
            return [];
        }
        $requiredWeekly = (int) ceil($totalMinutes / studyWeeksBetween($fixedWindow['start'], $fixedWindow['end']));
    }

    // Base weekly intensity we aim for, honoring the user's wish but capped by
    // what the availability windows can actually hold and what the deadline needs.
    $base = $desiredWeekly ?? $requiredWeekly ?? $weeklyAvail;
    if ($requiredWeekly !== null) {
        $base = max($base, $requiredWeekly);
    }
    $base = max(EVENT_DURATION_MINUTES_MIN, min($base, $weeklyAvail, STUDY_MAX_WEEKLY_HOURS * 60));

    $qualification = (string) ($goal['qualification_name'] ?? '学習');
    $title = $qualification . ' 学習';
    $sessionPref = $goal['preferred_session_minutes'] ?? null;

    $plans = [];
    foreach (studyPlanMethods() as $id => $method) {
        $weeklyTarget = (int) round($base * $method['weekly_factor']);
        if ($requiredWeekly !== null) {
            // Never drop below what the deadline needs (would miss it).
            $weeklyTarget = max($weeklyTarget, min($requiredWeekly, $weeklyAvail));
        }
        $weeklyTarget = max(EVENT_DURATION_MINUTES_MIN, min($weeklyTarget, $weeklyAvail, STUDY_MAX_WEEKLY_HOURS * 60));

        if ($deadlineMode) {
            $window = $fixedWindow;
        } else {
            $window = resolveStudyWindow($goal, $now, $weeklyTarget);
            if ($window === null) {
                continue;
            }
        }

        $session = $sessionPref ?? (int) $method['session'];
        $template = buildWeeklyTemplate($availability, $weeklyTarget, $session);
        if ($template === []) {
            continue;
        }

        $events = expandTemplateAcrossRange($template, $window['start'], $window['end'], $totalMinutes, $now, $title);
        if ($events === []) {
            continue;
        }
        foreach ($events as &$event) {
            $event['ai_idempotency_key'] = studyOccurrenceIdempotencyKey($id, $qualification, $event);
        }
        unset($event);

        $stats = summarizeOccurrences($events, $totalMinutes);
        $stats['start_date'] = $window['start'];
        $stats['end_date'] = $window['end'];
        $stats['session_minutes'] = $session;

        $plans[] = [
            'id' => $id,
            'name' => $method['name'],
            'summary' => $method['summary'],
            'method' => $id,
            'events' => $events,
            'stats' => $stats,
        ];
    }

    return $plans;
}

function studyWeeksBetween(string $startDate, string $endDate): int
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
    if ($start === false || $end === false || $end < $start) {
        return 1;
    }
    $days = (int) $start->diff($end)->days;

    return max(1, (int) ceil(($days + 1) / 7));
}

/** Which required fields are still missing before plans can be generated. */
function missingStudyFields(array $goal): array
{
    $missing = [];
    if (empty($goal['qualification_name'])) {
        $missing[] = '資格・目標';
    }
    if (studyTotalMinutes($goal) === null) {
        $missing[] = '学習時間の目安';
    }
    if (empty($goal['target_date']) && empty($goal['duration_months']) && empty($goal['desired_weekly_hours'])) {
        $missing[] = '目標期限または週の学習時間';
    }
    if (empty($goal['availability'])) {
        $missing[] = '学習できる曜日・時間帯';
    }

    return $missing;
}

function isStudyGoalReadyForPlans(array $goal, DateTimeImmutable $now): bool
{
    if (missingStudyFields($goal) !== []) {
        return false;
    }

    return resolveStudyWindow($goal, $now) !== null
        || (studyTotalMinutes($goal) !== null && !empty($goal['desired_weekly_hours']));
}

function formatMinutesAsHours(int $minutes): string
{
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    if ($hours > 0 && $rest > 0) {
        return $hours . '時間' . $rest . '分';
    }
    if ($hours > 0) {
        return $hours . '時間';
    }

    return $rest . '分';
}

function formatAvailabilitySummary(array $availability): string
{
    if ($availability === []) {
        return '';
    }
    $parts = [];
    foreach ($availability as $slot) {
        $ja = STUDY_WEEKDAYS_JA[$slot['weekday']] ?? '';
        $parts[] = $ja . ' ' . $slot['start'] . '〜' . $slot['end'];
    }

    return implode(' / ', $parts);
}

/** Fixed-order rows for the "現在の相談内容" panel (未設定/相談中 for blanks). */
function studyGoalDisplayRows(array $goal): array
{
    $unset = '未設定';
    $talking = '相談中';

    $qualification = $goal['qualification_name'] ?? null;
    if ($qualification !== null && !empty($goal['qualification_level'])) {
        $qualification .= '（' . $goal['qualification_level'] . '）';
    }

    $estimate = $unset;
    if (is_array($goal['estimated_hours'] ?? null)) {
        $min = $goal['estimated_hours']['min'] ?? null;
        $max = $goal['estimated_hours']['max'] ?? null;
        $rec = $goal['estimated_hours']['recommended'] ?? null;
        if ($min !== null && $max !== null) {
            $estimate = "約{$min}〜{$max}時間";
        } elseif ($rec !== null) {
            $estimate = "約{$rec}時間";
        }
    }

    $goalLabel = $talking;
    if (($goal['goal_type'] ?? null) === 'score' && !empty($goal['target_score'])) {
        $goalLabel = $goal['target_score'] . '点';
    } elseif (($goal['goal_type'] ?? null) === 'pass_fail') {
        $goalLabel = '合格';
    }

    $current = $unset;
    if (!empty($goal['current_level'])) {
        $current = (string) $goal['current_level'];
    } elseif (($goal['current_score'] ?? null) !== null) {
        $current = $goal['current_score'] . '点';
    }

    $deadline = $talking;
    if (!empty($goal['target_date'])) {
        $deadline = (string) $goal['target_date'];
    } elseif (!empty($goal['duration_months'])) {
        $deadline = $goal['duration_months'] . 'か月';
    }

    $weekly = $talking;
    if (!empty($goal['desired_weekly_hours'])) {
        $modeLabel = ['desired' => '希望', 'maximum' => '上限', 'minimum' => '最低', 'unknown' => ''][$goal['weekly_hours_mode'] ?? 'unknown'] ?? '';
        $weekly = '週' . $goal['desired_weekly_hours'] . '時間' . ($modeLabel !== '' ? "（{$modeLabel}）" : '');
    }

    $session = '自動';
    if (!empty($goal['preferred_session_minutes'])) {
        $session = formatMinutesAsHours((int) $goal['preferred_session_minutes']);
    }

    return [
        ['label' => '資格・目標', 'value' => $qualification ?: $unset],
        ['label' => '現在のレベル', 'value' => $current],
        ['label' => '目標', 'value' => $goalLabel],
        ['label' => '一般的な目安', 'value' => $estimate],
        ['label' => '今回の総学習時間', 'value' => !empty($goal['selected_total_hours']) ? $goal['selected_total_hours'] . '時間' : $talking],
        ['label' => '開始日', 'value' => !empty($goal['start_date']) ? (string) $goal['start_date'] : '今日から'],
        ['label' => '目標日', 'value' => $deadline],
        ['label' => '週の学習時間', 'value' => $weekly],
        ['label' => '学習できる曜日・時間', 'value' => formatAvailabilitySummary($goal['availability'] ?? []) ?: $unset],
        ['label' => '1回の学習時間', 'value' => $session],
    ];
}

/** Fixed-order display fields for a plan card, from its stats block. */
function planCardFields(array $plan): array
{
    $stats = $plan['stats'] ?? [];
    if ($stats === []) {
        return [];
    }
    $feasible = !empty($stats['feasible']);
    $achievement = $feasible
        ? '✓ 目標時間を確保'
        : '⚠ ' . formatMinutesAsHours((int) ($stats['shortfall_minutes'] ?? 0)) . '不足';

    return [
        ['label' => '期間', 'value' => ($stats['start_date'] ?? '') . ' 〜 ' . ($stats['end_date'] ?? '')],
        ['label' => '週あたり', 'value' => formatMinutesAsHours((int) ($stats['weekly_minutes'] ?? 0))],
        ['label' => '1回の学習', 'value' => formatMinutesAsHours((int) ($stats['session_minutes'] ?? 0))],
        ['label' => '予定数', 'value' => (int) ($stats['count'] ?? 0) . '件'],
        ['label' => '合計学習時間', 'value' => formatMinutesAsHours((int) ($stats['total_minutes'] ?? 0))],
        ['label' => '最初の予定', 'value' => (string) ($stats['first_date'] ?? '')],
        ['label' => '最後の予定', 'value' => (string) ($stats['last_date'] ?? '')],
        ['label' => '曜日・時間', 'value' => (string) ($stats['weekday_time_summary'] ?? '')],
        ['label' => '達成見込み', 'value' => $achievement, 'status' => $feasible ? 'ok' : 'warn'],
    ];
}

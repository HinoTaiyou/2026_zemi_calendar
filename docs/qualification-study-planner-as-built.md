# Qualification Study Planner — As-Built

Lets a user describe a qualification/goal in free text and turn it into a full-period
study schedule on the calendar. Built on the existing chat + plan + registration flow;
**no database schema change**.

## Responsibility split

- **AI (Gemini)** — conversation only: understands the goal, extracts a structured
  `goal_patch`, estimates study hours as a *range*, asks for missing info (≤2 items/turn).
  The AI never enumerates dates or does scheduling math.
- **PHP** (`public_html/includes/study_planner.php`) — all scheduling: "today" in
  `Asia/Tokyo`, date validation, calendar-month windows, weekly templates, **expanding the
  template across the whole date range**, totals, last-session trim, safety caps, and the
  deterministic idempotency key.
- **Registration** — reuses the existing `addEvents()` (transactional bulk insert +
  `ON CONFLICT DO NOTHING` idempotency + conflict checks). The full expansion flows through
  it unchanged.

## Conversation flow

free input → AI fills `goal_patch` → PHP validates + merges into the session goal →
right panel shows "現在の相談内容" + "不足している情報" → once qualification + total hours +
(deadline or weekly hours) + availability are known, PHP builds **A/B/C** → preview → the
existing select-plan / confirm forms register every occurrence.

## Current date handling (fixes "past dates")

`appNow()` returns `Asia/Tokyo` now (injectable via `setAppNowForTest()` for tests).
`buildPlanningDateContext()` injects 今日/現在時刻/タイムゾーン/曜日 into every Gemini system
prompt. `validateStudyGoalPatch()` drops any `start_date`/`target_date` before today and any
`target < start`; the start never resolves earlier than today, and a slot earlier today is
skipped to the next future slot.

## Study-goal state (session)

`chat_study_goal` (see `chat_session.php`: `getStudyGoalState` / `setStudyGoalState` /
`mergeStudyGoalState`, reset by `resetChatSession`). Fields: qualification_name/level,
goal_type (`pass_fail|score|undecided`), current_level/score, target_score,
estimated_hours{min,max,recommended,source,confidence,assumptions}, selected_total_hours,
start_date, target_date, duration_months, desired_weekly_hours,
weekly_hours_mode (`desired|maximum|minimum|unknown`), availability[] (ISO weekday 1–7 +
HH:MM start/end), preferred_session_minutes, planning_status.

## Structured Gemini response

`getChatSystemPrompt($goal,$now,$missing)` asks for one JSON object
`{reply, action, goal_patch}`. `parseStudyGoalResponse()` extracts it (fenced or bare),
reusing `extractJsonCandidate`/`decodeJsonObject`; on failure `chatWithScheduleAssistant()`
does **one** repair retry, then surfaces a safe message (never raw JSON, never fatal).

## A/B/C generation

`buildStudyPlanOptions($goal,$now)`:
1. `studyTotalMinutes()` = selected hours (else recommended estimate) × 60.
2. Base weekly minutes = desired weekly (capped by availability) and, in deadline mode, at
   least `ceil(total / weeks)`.
3. Methods (`studyPlanMethods()`): **A 短期集中** (×1.25, 150-min sessions),
   **B バランス** (×1.0, 90-min), **C 余裕重視** (×0.8, 60-min). `preferred_session_minutes`
   overrides the session length when set.
4. `buildWeeklyTemplate()` lays one session per availability window (extends to fill).
5. `expandTemplateAcrossRange()` walks start→end, emitting events, skipping past slots,
   accumulating integer minutes, trimming the final session, capping at 500 events.
6. `summarizeOccurrences()` → count, total minutes, weekly minutes, first/last date,
   weekday/time summary, `feasible` + `shortfall_minutes`.

Each plan carries the **full** `events` (with deterministic
`studyOccurrenceIdempotencyKey()` = `sp_` + sha256[0..60]) and a `stats` block.
`planCardFields()` renders a fixed field order so A/B/C always show the same rows incl.
weekday/time (fixes the inconsistent-card bug).

## Full-range expansion (fixes "only the first week")

The AI no longer lists dates. PHP expands the weekly template across every matching weekday
from start to end. Regression test "expansion exceeds the first week" asserts week-2+ and
final-month coverage, only available weekdays, nothing before start / after end, exact total.

## Caps (`study_planner.php`)

≤18 months horizon, ≤500 events, weekly 1–60h, session 15–480 min (reusing
`EVENT_DURATION_MINUTES_MIN/MAX`).

## Feasibility / shortfall

When availability can't reach the hours by the deadline, the plan is marked not feasible and
the card shows the shortfall (e.g. 180h in 3 months on 13h/week → ~169h, flagged short).

## UI

`chat.php` right column: "現在の相談内容" (`studyGoalDisplayRows`, 未設定/相談中/今日から for
blanks) + "不足している情報", then A/B/C cards (`planCardFields`), then a registration preview
(count / total time / range). Quick-start chips (`assets/js/chat.js`, fill the textarea, no
auto-submit, JS-free fallback). New CSS is additive (`.consult-*`, `.plan-fields`,
`.plan-preview`, `.quickstart*`); the redesign's tokens/sticky/scroll-margin are unchanged.

## Preserved contracts

Form `action`/`method`, every `name=` (`action`, `csrf_token`, `message`, `plan_id`,
`allow_conflict`, `id`, `date`, `time`, `duration_minutes`, `title`), `csrfInput()`, plan
ids, idempotency keys, conflict handling, and the chat scroll behavior
(`determineChatScrollTarget` intents + `assets/js/chat.js`).

## Testing

`tests/run.php` uses `setAppNowForTest(2026-06-25 10:00 Asia/Tokyo)`; the AI path is
exercised with a fake HTTP client (no live Gemini). 113 tests total (67 prior + 46 new).

## Extending later

A local qualification catalog can front-run the AI estimate: add an editable data file and
prefer it in the estimate step (catalog → Gemini → ask the user), without hardcoding numbers
into the scheduling logic.

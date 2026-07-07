# 07 — Deadline Reminder/Notification

**Goal:** Highlight approaching deadlines (within 3 days) visually in the calendar view card, and optionally show a subtle countdown badge.

## Changes Required

### 1. Index Blade (`index.blade.php`)
- Currently only checks `$isOverdue` (past deadline + not completed)
- Add `$isApproaching` check: deadline is within next 3 days and not completed
- Add approaching visual treatment:
  - Orange/yellow left border stripe
  - Small countdown text: "2 hari lagi" or "Batas hari ini!"
  - Use a pulse/attention animation on the card

### 2. Logic in the Blade loop
```
$isApproaching = !$isOverdue 
    && $item->status !== 'completed' 
    && $deadline->isFuture() 
    && $deadline->diffInDays(now()) <= 3;
$daysLeft = $isApproaching ? now()->diffInDays($deadline, false) : null;
```

### 3. Visual treatment ideas
- Orange left-border: `border-l-4 border-l-[#e6915d]`
- If 0 days left (today): red text "Deadline hari ini!"
- If 1 day left: "Besok deadline!"
- If 2-3 days: "N hari lagi"
- Keep the card readable — don't overload with too many visual cues

### 4. No migration/controller change needed
- Pure Blade view logic using existing `deadline_date`

## Acceptance Criteria
- Tasks due within 3 days get a visual indicator (orange border + day count)
- Tasks due today show "Deadline hari ini!"
- Overdue tasks keep their red border (separate treatment)
- Completed tasks show neither overdue nor approaching treatment
- Visual is subtle but noticeable

import test from 'node:test';
import assert from 'node:assert/strict';
import { calculateAgendaSlot, formatAgendaTime, scheduleForDate } from '../../resources/js/sales-agenda-slot.js';

test('formats agenda minutes as HH:MM', () => {
    assert.equal(formatAgendaTime(0), '00:00');
    assert.equal(formatAgendaTime(510), '08:30');
});

test('starts an empty Monday at 08:00 and computes remaining weekday minutes', () => {
    const result = calculateAgendaSlot({
        date: '2026-10-26',
        agendas: [],
        durationMinutes: 60,
    });

    assert.deepEqual(result, {
        ok: true,
        startTime: '08:00',
        endTime: '09:00',
        durationMinutes: 60,
        remainingMinutes: 420,
        message: '',
    });
});

test('starts an empty Friday at 07:30', () => {
    const result = calculateAgendaSlot({ date: '2026-10-30', agendas: [], durationMinutes: 30 });

    assert.equal(result.startTime, '07:30');
    assert.equal(result.endTime, '08:00');
});

test('defaults to the last agenda end time', () => {
    const result = calculateAgendaSlot({
        date: '2026-10-26',
        agendas: [{ startTime: '09:00', endTime: '10:30', durationMinutes: 90 }],
        durationMinutes: 30,
    });

    assert.equal(result.startTime, '10:30');
    assert.equal(result.endTime, '11:00');
});

test('defaults to the end of break when the last agenda ends during break', () => {
    const result = calculateAgendaSlot({
        date: '2026-10-26',
        agendas: [{ startTime: '11:30', endTime: '12:15', durationMinutes: 45 }],
        durationMinutes: 30,
    });

    assert.equal(result.startTime, '13:00');
    assert.equal(result.endTime, '13:30');
});

test('allows an explicitly selected break agenda without charging the target', () => {
    const result = calculateAgendaSlot({
        date: '2026-10-26',
        agendas: [],
        requestedStart: '12:00',
        durationMinutes: 60,
    });

    assert.deepEqual(result, {
        ok: true,
        startTime: '12:00',
        endTime: '13:00',
        durationMinutes: 60,
        remainingMinutes: 480,
        message: '',
    });
});

test('counts only effective minutes for Friday agendas crossing the break', () => {
    const startsAtBreak = calculateAgendaSlot({
        date: '2026-10-30',
        agendas: [],
        requestedStart: '11:30',
        durationMinutes: 120,
    });
    const startsBeforeBreak = calculateAgendaSlot({
        date: '2026-10-30',
        agendas: [],
        requestedStart: '11:00',
        durationMinutes: 150,
    });

    assert.equal(startsAtBreak.ok, true);
    assert.equal(startsAtBreak.remainingMinutes, 450);
    assert.equal(startsBeforeBreak.ok, true);
    assert.equal(startsBeforeBreak.remainingMinutes, 420);
});

test('rejects an agenda overlapping an existing agenda', () => {
    const result = calculateAgendaSlot({
        date: '2026-10-26',
        agendas: [{ startTime: '10:00', endTime: '11:00', durationMinutes: 60 }],
        requestedStart: '10:30',
        durationMinutes: 30,
    });

    assert.equal(result.ok, false);
    assert.match(result.message, /bertumpang tindih/i);
});

test('allows an agenda crossing lunch but rejects one past the weekday end', () => {
    const crossingLunch = calculateAgendaSlot({
        date: '2026-10-26',
        agendas: [],
        requestedStart: '11:00',
        durationMinutes: 90,
    });
    const crossingEnd = calculateAgendaSlot({
        date: '2026-10-26',
        agendas: [],
        requestedStart: '16:30',
        durationMinutes: 60,
    });

    assert.equal(crossingLunch.ok, true);
    assert.equal(crossingLunch.endTime, '12:30');
    assert.equal(crossingEnd.ok, false);
    assert.match(crossingEnd.message, /17:00|jam kerja/i);
});

test('requires a selected start on weekends and has no remaining target', () => {
    const schedule = scheduleForDate('2026-10-31');
    const missingStart = calculateAgendaSlot({ date: '2026-10-31', agendas: [], durationMinutes: 30 });
    const result = calculateAgendaSlot({ date: '2026-10-31', agendas: [], requestedStart: '14:00', durationMinutes: 30 });

    assert.equal(schedule.isWeekend, true);
    assert.equal(missingStart.ok, false);
    assert.match(missingStart.message, /jam mulai/i);
    assert.deepEqual(result, {
        ok: true,
        startTime: '14:00',
        endTime: '14:30',
        durationMinutes: 30,
        remainingMinutes: null,
        message: '',
    });
});

test('rejects custom duration that is not a positive multiple of five', () => {
    const result = calculateAgendaSlot({ date: '2026-10-26', agendas: [], durationMinutes: 17 });

    assert.equal(result.ok, false);
    assert.match(result.message, /kelipatan 5/i);
});

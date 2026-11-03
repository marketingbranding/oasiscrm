import test from 'node:test';
import assert from 'node:assert/strict';
import { calculateAgendaSlot, formatAgendaTime, resolveAgendaDuration, scheduleForDate } from '../../resources/js/sales-agenda-slot.js';
import registerSalesAgendaWorkspace from '../../resources/js/sales-agenda-workspace.js';

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

test('counts a full Friday schedule from 07:30 to 17:00 as 480 effective minutes', () => {
    const result = calculateAgendaSlot({
        date: '2026-10-30',
        agendas: [],
        requestedStart: '07:30',
        durationMinutes: 570,
    });

    assert.deepEqual(result, {
        ok: true,
        startTime: '07:30',
        endTime: '17:00',
        durationMinutes: 570,
        remainingMinutes: 0,
        message: '',
    });
});

test('counts a Friday schedule from 08:00 to 17:00 as 450 effective minutes', () => {
    const result = calculateAgendaSlot({
        date: '2026-10-30',
        agendas: [],
        requestedStart: '08:00',
        durationMinutes: 540,
    });

    assert.equal(result.endTime, '17:00');
    assert.equal(result.remainingMinutes, 30);
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

test('ignores outside, cancelled, and rescheduled agendas for automatic slots and overlaps', () => {
    const result = calculateAgendaSlot({
        date: '2026-10-26',
        agendas: [
            { startTime: '08:00', endTime: '09:00', status: 'cancelled' },
            { startTime: '09:00', endTime: '10:00', status: 'rescheduled' },
            { startTime: '17:00', endTime: '18:00', isOutsideSchedule: true },
        ],
        durationMinutes: 60,
    });

    assert.equal(result.ok, true);
    assert.equal(result.startTime, '08:00');
    assert.equal(result.endTime, '09:00');
});

test('allows submitted-day additions outside weekday hours without overlap rejection', () => {
    const result = calculateAgendaSlot({
        date: '2026-10-26',
        agendas: [{ startTime: '08:00', endTime: '09:00' }],
        requestedStart: '18:00',
        durationMinutes: 60,
        allowOutsideSchedule: true,
    });

    assert.deepEqual(result, {
        ok: true,
        startTime: '18:00',
        endTime: '19:00',
        durationMinutes: 60,
        remainingMinutes: 420,
        message: '',
    });
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

test('resolves custom duration without requiring a preset select option', () => {
    assert.equal(resolveAgendaDuration('', '180'), '180');
    assert.equal(resolveAgendaDuration('60', '180'), '60');
    assert.equal(resolveAgendaDuration('', ''), '');
});

test('keeps nested preview and create-sheet locks and focus restoration independent', () => {
    const previousDocument = globalThis.document;
    const previousWindow = globalThis.window;
    const focusEvents = [];
    const previewTrigger = { focus: () => focusEvents.push('preview') };
    const sheetTrigger = { focus: () => focusEvents.push('sheet') };
    const documentStub = {
        activeElement: previewTrigger,
        body: { style: { overflow: '' } },
    };
    const windowStub = {
        matchMedia: () => ({ matches: true }),
    };
    const registrations = {};

    globalThis.document = documentStub;
    globalThis.window = windowStub;

    try {
        registerSalesAgendaWorkspace({
            data(name, factory) {
                registrations[name] = factory;
            },
        });

        const workspace = registrations.salesAgendaWorkspace();
        workspace.$nextTick = (callback) => callback();
        workspace.$refs = {
            receiptClose: { focus: () => focusEvents.push('receipt-close') },
            sheetClose: { focus: () => focusEvents.push('sheet-close') },
        };

        workspace.openAgendaPreview({ currentTarget: previewTrigger });
        workspace.openAgendaSheet({ currentTarget: sheetTrigger });
        assert.equal(workspace.receiptOpen, true);
        assert.equal(workspace.agendaOpen, true);
        assert.equal(documentStub.body.style.overflow, 'hidden');

        workspace.closeSheet();
        assert.equal(workspace.receiptOpen, true);
        assert.equal(documentStub.body.style.overflow, 'hidden');
        assert.deepEqual(focusEvents, ['receipt-close', 'sheet-close', 'sheet']);

        workspace.closeReceipt();
        assert.equal(documentStub.body.style.overflow, '');
        assert.deepEqual(focusEvents, ['receipt-close', 'sheet-close', 'sheet', 'preview']);
    } finally {
        globalThis.document = previousDocument;
        globalThis.window = previousWindow;
    }
});

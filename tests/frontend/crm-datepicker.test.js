import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { isDateWithinRange } from '../../resources/js/crm-date-range.js';

test('allows only dates on or after the picker minimum', () => {
    assert.equal(isDateWithinRange('2026-11-03', '2026-11-03'), true);
    assert.equal(isDateWithinRange('2026-11-04', '2026-11-03'), true);
    assert.equal(isDateWithinRange('2026-11-02', '2026-11-03'), false);
});

test('allows existing unrestricted date fields to keep their full range', () => {
    assert.equal(isDateWithinRange('2020-01-01'), true);
    assert.equal(isDateWithinRange('2030-01-01', '', '2030-01-01'), true);
    assert.equal(isDateWithinRange('2030-01-02', '', '2030-01-01'), false);
});

test('date picker display opens from keyboard activation and exposes dialog state', () => {
    const source = fs.readFileSync(new URL('../../resources/js/crm-datepicker.js', import.meta.url), 'utf8');

    assert.match(source, /display\.addEventListener\(['"]keydown['"]/);
    assert.match(source, /e\.key === ['"]Enter['"] \|\| e\.key === ['"]Space['"] /);
    assert.match(source, /aria-expanded/);
    assert.match(source, /document\.createElement\(isCurrentMonthDay \? ['"]button['"]/);
    assert.match(source, /day\.setAttribute\(['"]aria-label['"]/);
});

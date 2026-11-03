import test from 'node:test';
import assert from 'node:assert/strict';
import registerSalesAgendaDetail, { syncAgendaCompletionState } from '../../resources/js/sales-agenda-detail.js';

function completionForm(resultValue = '') {
    const attributes = {};
    const result = { value: resultValue };
    const submit = {
        disabled: true,
        setAttribute(name, value) {
            attributes[name] = value;
        },
    };

    return {
        result,
        submit,
        attributes,
        querySelector(selector) {
            if (selector === '[data-agenda-result]') {
                return result;
            }

            if (selector === '[data-complete-agenda]') {
                return submit;
            }

            return null;
        },
    };
}

test('keeps agenda completion disabled when activity result is empty or whitespace', () => {
    const form = completionForm('   ');

    syncAgendaCompletionState(form);

    assert.equal(form.submit.disabled, true);
    assert.equal(form.attributes['aria-disabled'], 'true');
});

test('enables agenda completion when activity result contains text', () => {
    const form = completionForm('  Kunjungan selesai  ');

    syncAgendaCompletionState(form);

    assert.equal(form.submit.disabled, false);
    assert.equal(form.attributes['aria-disabled'], 'false');
});

test('updates completion state when the result field changes', () => {
    const previousDocument = globalThis.document;
    const form = completionForm();
    const listeners = {};

    form.result.addEventListener = (event, listener) => {
        listeners[event] = listener;
    };
    globalThis.document = {
        querySelectorAll(selector) {
            return selector === '[data-completion-form]' ? [form] : [];
        },
    };

    try {
        registerSalesAgendaDetail();
        form.result.value = 'Hasil aktivitas tercatat';
        listeners.input();

        assert.equal(form.submit.disabled, false);
        assert.equal(form.attributes['aria-disabled'], 'false');

        form.result.value = ' \n ';
        listeners.input();

        assert.equal(form.submit.disabled, true);
        assert.equal(form.attributes['aria-disabled'], 'true');
    } finally {
        globalThis.document = previousDocument;
    }
});

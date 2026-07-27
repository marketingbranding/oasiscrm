import { positionPickerPopup } from './crm-picker-position';

const pad = value => String(value).padStart(2, '0');
let pickerIndex = 0;

function currentTime() {
    const now = new Date();
    return `${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

function closePicker(wrapper, restoreFocus = false) {
    const popup = wrapper.querySelector('.time-picker-popup');
    if (popup) popup.style.display = 'none';
    const arrow = wrapper.querySelector('.time-arrow');
    if (arrow) arrow.textContent = '▼';
    const display = wrapper.querySelector('.time-display');
    if (display) {
        display.setAttribute('aria-expanded', 'false');
        if (restoreFocus) display.focus({ preventScroll: true });
    }
}

function syncDisplay(wrapper) {
    const input = wrapper.querySelector('input[type="time"]');
    const text = wrapper.querySelector('.time-text');
    if (input && text) text.textContent = input.value ? input.value.slice(0, 5) : 'Pilih Jam';
}

function markWheelSelection(wheel, value) {
    wheel.dataset.value = value;
    wheel.querySelectorAll('button').forEach(button => {
        const selected = button.dataset.value === value;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-selected', String(selected));
    });
    const selected = wheel.querySelector(`[data-value="${value}"]`);
    if (selected) wheel.setAttribute('aria-activedescendant', selected.id);
}

function selectWheel(wheel, value) {
    markWheelSelection(wheel, value);
    const selected = wheel.querySelector(`[data-value="${value}"]`);
    if (!selected) return;

    const wheelRect = wheel.getBoundingClientRect();
    const selectedRect = selected.getBoundingClientRect();
    const target = wheel.scrollTop + selectedRect.top + selectedRect.height / 2 - (wheelRect.top + wheel.clientHeight / 2);
    if (Math.abs(target - wheel.scrollTop) > 1) wheel.scrollTop = target;
}

function setDraft(wrapper, value) {
    const [hour = '00', minute = '00'] = value.split(':');
    selectWheel(wrapper.querySelector('.time-hours'), pad(hour));
    selectWheel(wrapper.querySelector('.time-minutes'), pad(minute));
}

function commit(wrapper) {
    const input = wrapper.querySelector('input[type="time"]');
    const hour = wrapper.querySelector('.time-hours')?.dataset.value;
    const minute = wrapper.querySelector('.time-minutes')?.dataset.value;
    if (!input || hour === undefined || minute === undefined) return;
    input.value = `${hour}:${minute}`;
    syncDisplay(wrapper);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    closePicker(wrapper, true);
}

function wheelMarkup(className, count, label, pickerId) {
    return `<div class="time-wheel ${className}" role="listbox" aria-label="${label}" tabindex="0">${Array.from({ length: count }, (_, value) => `<button id="${pickerId}-${className}-${pad(value)}" type="button" role="option" aria-selected="false" tabindex="-1" data-value="${pad(value)}">${pad(value)}</button>`).join('')}</div>`;
}

function initTimePickers() {
    document.querySelectorAll('.time-wrapper').forEach(wrapper => {
        if (wrapper.__timePicker) return;
        wrapper.__timePicker = true;
        const display = wrapper.querySelector('.time-display');
        const input = wrapper.querySelector('input[type="time"]');
        if (!display || !input) return;
        const pickerId = `crm-time-${++pickerIndex}`;

        const popup = document.createElement('div');
        popup.className = 'time-picker-popup';
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-label', 'Pilih jam');
        popup.style.display = 'none';
        popup.style.setProperty('--accent', wrapper.dataset.accent || '#fcc20f');
        popup.innerHTML = `<div class="time-picker-title">PILIH JAM</div><div class="time-wheels">${wheelMarkup('time-hours', 24, 'Jam', pickerId)}<strong>:</strong>${wheelMarkup('time-minutes', 60, 'Menit', pickerId)}</div><div class="time-picker-actions"><button type="button" class="time-now">Sekarang</button><button type="button" class="time-confirm">Pilih</button></div>`;
        wrapper.appendChild(popup);
        syncDisplay(wrapper);

        const open = () => {
            document.dispatchEvent(new CustomEvent('oasis:picker-open', { detail: { wrapper } }));
            popup.style.display = 'block';
            popup.style.visibility = 'hidden';
            setDraft(wrapper, input.value || '00:00');
            wrapper.querySelector('.time-arrow').textContent = '▲';
            display.setAttribute('aria-expanded', 'true');
            positionPickerPopup(wrapper, popup, '.time-display');
            popup.style.visibility = 'visible';
            popup.querySelector('.time-hours')?.focus({ preventScroll: true });
        };
        display.addEventListener('click', event => { event.stopPropagation(); popup.style.display === 'none' ? open() : closePicker(wrapper); });
        display.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
        });

        popup.querySelectorAll('.time-wheel').forEach(wheel => {
            wheel.addEventListener('click', event => {
                const button = event.target.closest('button');
                if (button) selectWheel(wheel, button.dataset.value);
            });
            wheel.addEventListener('keydown', event => {
                if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
                event.preventDefault();
                const max = wheel.classList.contains('time-hours') ? 23 : 59;
                const next = Math.min(max, Math.max(0, Number(wheel.dataset.value || 0) + (event.key === 'ArrowDown' ? 1 : -1)));
                selectWheel(wheel, pad(next));
            });
            let scrollTimer;
            wheel.addEventListener('scroll', () => {
                window.clearTimeout(scrollTimer);
                scrollTimer = window.setTimeout(() => {
                    const buttons = [...wheel.querySelectorAll('button')];
                    const center = wheel.getBoundingClientRect().top + wheel.clientHeight / 2;
                    const nearest = buttons.sort((a, b) => Math.abs(a.getBoundingClientRect().top + a.offsetHeight / 2 - center) - Math.abs(b.getBoundingClientRect().top + b.offsetHeight / 2 - center))[0];
                    if (nearest) markWheelSelection(wheel, nearest.dataset.value);
                }, 80);
            }, { passive: true });
        });

        popup.querySelector('.time-now').addEventListener('click', () => setDraft(wrapper, currentTime()));
        popup.querySelector('.time-confirm').addEventListener('click', () => commit(wrapper));
        input.addEventListener('input', () => syncDisplay(wrapper));
        input.addEventListener('change', () => syncDisplay(wrapper));
        document.addEventListener('oasis:picker-open', event => { if (event.detail?.wrapper !== wrapper) closePicker(wrapper); });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && popup.style.display !== 'none') closePicker(wrapper, true);
        });
        document.addEventListener('click', event => { if (!wrapper.contains(event.target)) closePicker(wrapper); });
        const reposition = () => { if (popup.style.display !== 'none') positionPickerPopup(wrapper, popup, '.time-display'); };
        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);
    });
}

document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', initTimePickers) : initTimePickers();
new MutationObserver(mutations => {
    if (mutations.some(mutation => [...mutation.addedNodes].some(node => node.nodeType === Node.ELEMENT_NODE && (node.matches?.('.time-wrapper') || node.querySelector?.('.time-wrapper'))))) initTimePickers();
}).observe(document.documentElement, { childList: true, subtree: true });

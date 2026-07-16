import { positionPickerPopup } from './crm-picker-position';

const monthNames = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

function currentMonthString() {
    const today = new Date();
    return today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0');
}

function closeMonthPicker(wrapper) {
    const popup = wrapper.querySelector('.month-picker-popup');
    const arrow = wrapper.querySelector('.month-arrow');
    if (popup) popup.style.display = 'none';
    if (arrow) arrow.textContent = '\u25BC';
}

function syncMonthDisplay(wrapper) {
    const input = wrapper.querySelector('input[type="month"]');
    const text = wrapper.querySelector('.month-text');
    if (!input || !text || !input.value) return;
    const parts = input.value.split('-');
    const month = parseInt(parts[1], 10) - 1;
    text.textContent = monthNames[month] + ' ' + parts[0];
}

function selectMonth(wrapper, value) {
    const input = wrapper.querySelector('input[type="month"]');
    if (!input) return;
    input.value = value;
    syncMonthDisplay(wrapper);
    closeMonthPicker(wrapper);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    wrapper.__monthYear = parseInt(value.split('-')[0], 10);
}

function renderMonths(wrapper) {
    const popup = wrapper.querySelector('.month-picker-popup');
    const grid = popup?.querySelector('.month-grid');
    const title = popup?.querySelector('.month-year');
    const input = wrapper.querySelector('input[type="month"]');
    if (!grid || !title || !input) return;

    const year = wrapper.__monthYear;
    const current = currentMonthString();
    grid.innerHTML = '';
    title.textContent = year;

    monthNames.forEach(function(name, index) {
        const value = year + '-' + String(index + 1).padStart(2, '0');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'month-option';
        button.textContent = name;
        if (input.value === value) button.classList.add('is-selected');
        if (current === value) button.classList.add('is-current');
        button.addEventListener('click', function(e) {
            e.stopPropagation();
            selectMonth(wrapper, value);
        });
        grid.appendChild(button);
    });
}

function initMonthPickers() {
    document.querySelectorAll('.month-wrapper').forEach(function(wrapper) {
        if (wrapper.__mw) return;
        wrapper.__mw = true;
        const display = wrapper.querySelector('.month-display');
        const input = wrapper.querySelector('input[type="month"]');
        if (!display || !input) return;

        const popup = document.createElement('div');
        popup.className = 'month-picker-popup';
        popup.style.cssText = 'display:none;position:fixed;top:0;left:0;z-index:9999;border:2px solid #000;background:#fff;width:280px';
        popup.style.setProperty('--accent', wrapper.dataset.accent || '#f1c40f');
        popup.innerHTML =
            '<div class="month-picker-header">' +
            '<button type="button" class="month-prev-year" aria-label="Tahun sebelumnya">\u25C0</button>' +
            '<span class="month-year"></span>' +
            '<button type="button" class="month-next-year" aria-label="Tahun berikutnya">\u25B6</button>' +
            '</div>' +
            '<div class="month-grid"></div>' +
            '<div class="month-picker-footer"><button type="button" class="month-current-button">Bulan Ini</button></div>';
        wrapper.appendChild(popup);
        syncMonthDisplay(wrapper);

        display.addEventListener('click', function(e) {
            e.stopPropagation();
            if (popup.style.display !== 'none') {
                closeMonthPicker(wrapper);
                return;
            }

            document.dispatchEvent(new CustomEvent('oasis:picker-open', { detail: { wrapper } }));
            const fallback = new Date();
            wrapper.__monthYear = input.value ? parseInt(input.value.split('-')[0], 10) : fallback.getFullYear();
            popup.style.display = 'block';
            popup.style.visibility = 'hidden';
            const arrow = wrapper.querySelector('.month-arrow');
            if (arrow) arrow.textContent = '\u25B2';
            renderMonths(wrapper);
            positionPickerPopup(wrapper, popup, '.month-display');
            popup.style.visibility = 'visible';
        });

        popup.querySelector('.month-prev-year')?.addEventListener('click', function(e) {
            e.stopPropagation();
            wrapper.__monthYear--;
            renderMonths(wrapper);
            positionPickerPopup(wrapper, popup, '.month-display');
        });
        popup.querySelector('.month-next-year')?.addEventListener('click', function(e) {
            e.stopPropagation();
            wrapper.__monthYear++;
            renderMonths(wrapper);
            positionPickerPopup(wrapper, popup, '.month-display');
        });
        popup.querySelector('.month-current-button')?.addEventListener('click', function(e) {
            e.stopPropagation();
            selectMonth(wrapper, currentMonthString());
        });

        const reposition = function() {
            if (popup.style.display !== 'none') positionPickerPopup(wrapper, popup, '.month-display');
        };
        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);
        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) closeMonthPicker(wrapper);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeMonthPicker(wrapper);
        });
        document.addEventListener('oasis:picker-open', function(e) {
            if (e.detail?.wrapper !== wrapper) closeMonthPicker(wrapper);
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMonthPickers);
} else {
    initMonthPickers();
}

const monthPickerObserver = new MutationObserver(function(mutations) {
    if (mutations.some(mutation => Array.from(mutation.addedNodes).some(node =>
        node.nodeType === Node.ELEMENT_NODE
        && (node.matches?.('.month-wrapper') || node.querySelector?.('.month-wrapper'))
    ))) {
        initMonthPickers();
    }
});

monthPickerObserver.observe(document.documentElement, { childList: true, subtree: true });

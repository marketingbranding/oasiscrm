const monthsId = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

function renderCalendar(wrapper, state) {
    const y = state.year, m = state.month;
    const first = new Date(y, m, 1).getDay();
    const days = new Date(y, m + 1, 0).getDate();
    const prevDays = new Date(y, m, 0).getDate();
    const grid = wrapper.querySelector('.cal-grid');
    const title = wrapper.querySelector('.cal-title');
    if (!grid || !title) return;
    grid.innerHTML = '';
    for (let i = 0; i < 42; i++) {
        const div = document.createElement('div');
        div.className = 'cal-day';
        if (i < first) {
            div.textContent = prevDays - first + i + 1;
            div.classList.add('cal-other');
        } else if (i >= first + days) {
            div.textContent = i - first - days + 1;
            div.classList.add('cal-other');
        } else {
            const dayNum = i - first + 1;
            const ds = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(dayNum).padStart(2, '0');
            div.textContent = dayNum;
            div.dataset.date = ds;
            const input = wrapper.querySelector('input[type="date"]');
            if (input && input.value === ds) div.classList.add('cal-selected');
            const today = new Date();
            const todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
            if (ds === todayStr) div.classList.add('cal-today');
            div.addEventListener('click', function() { selectDate(wrapper, ds); });
        }
        grid.appendChild(div);
    }
    title.textContent = monthsId[m] + ' ' + y;
}

function selectDate(wrapper, dateStr) {
    const input = wrapper.querySelector('input[type="date"]');
    if (!input) return;
    input.value = dateStr;
    const parts = dateStr.split('-');
    const d = parseInt(parts[2], 10);
    const m = parseInt(parts[1], 10) - 1;
    const y = parseInt(parts[0], 10);
    const textEl = wrapper.querySelector('.date-text');
    if (textEl) textEl.textContent = d + ' ' + monthsId[m] + ' ' + y;
    closeCalendar(wrapper);
    input.dispatchEvent(new Event('change', { bubbles: true }));
    wrapper.__calState = { year: y, month: m };
    renderCalendar(wrapper, wrapper.__calState);
}

function closeCalendar(wrapper) {
    const cal = wrapper.querySelector('.date-calendar');
    const arrow = wrapper.querySelector('.date-arrow');
    if (cal) cal.style.display = 'none';
    if (arrow) arrow.textContent = '\u25BC';
}

function syncDateDisplay(wrapper) {
    const input = wrapper.querySelector('input[type="date"]');
    const textEl = wrapper.querySelector('.date-text');
    if (input && textEl && input.value) {
        const parts = input.value.split('-');
        const d = parseInt(parts[2], 10);
        const m = parseInt(parts[1], 10) - 1;
        const y = parseInt(parts[0], 10);
        textEl.textContent = d + ' ' + monthsId[m] + ' ' + y;
    }
}

function initDatePickers() {
    document.querySelectorAll('.date-wrapper').forEach(function(wrapper) {
        if (wrapper.__dw) return;
        wrapper.__dw = true;
        const display = wrapper.querySelector('.date-display');
        let cal = wrapper.querySelector('.date-calendar');
        if (cal) {
            if (cal.__calInited) return;
            cal.__calInited = true;
        } else {
            cal = document.createElement('div');
            cal.className = 'date-calendar';
            cal.style.cssText = 'display:none;position:absolute;top:100%;left:0;z-index:9999;border:2px solid #000;background:#fff;width:280px';
            const accent = wrapper.dataset.accent || '#f1c40f';
            cal.innerHTML =
                '<div class="cal-header" style="background:#000;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:6px 10px;font-family:\'Times New Roman\';font-size:14px;font-weight:bold;user-select:none">' +
                '<button class="cal-prev" type="button" style="background:none;border:none;color:#fff;cursor:pointer;font-size:14px;padding:2px 8px;font-family:\'Times New Roman\';font-weight:bold;line-height:1">\u25C0</button>' +
                '<span class="cal-title"></span>' +
                '<button class="cal-next" type="button" style="background:none;border:none;color:#fff;cursor:pointer;font-size:14px;padding:2px 8px;font-family:\'Times New Roman\';font-weight:bold;line-height:1">\u25B6</button>' +
                '</div>' +
                '<div class="cal-weekdays" style="display:grid;grid-template-columns:repeat(7,1fr);border-bottom:2px solid #000;font-family:\'Times New Roman\';font-size:11px;font-weight:bold;text-align:center;background:#f5f5f5;color:#000">' +
                '<span style="padding:5px 0;border-right:1px solid #ddd">Min</span>' +
                '<span style="padding:5px 0;border-right:1px solid #ddd">Sen</span>' +
                '<span style="padding:5px 0;border-right:1px solid #ddd">Sel</span>' +
                '<span style="padding:5px 0;border-right:1px solid #ddd">Rab</span>' +
                '<span style="padding:5px 0;border-right:1px solid #ddd">Kam</span>' +
                '<span style="padding:5px 0;border-right:1px solid #ddd">Jum</span>' +
                '<span style="padding:5px 0">Sab</span>' +
                '</div>' +
                '<div class="cal-grid" style="display:grid;grid-template-columns:repeat(7,1fr)"></div>';
            cal.style.setProperty('--accent', accent);
            wrapper.appendChild(cal);
        }

        if (!display || !cal) return;
        syncDateDisplay(wrapper);

        display.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = cal.style.display !== 'none';
            if (isOpen) {
                closeCalendar(wrapper);
            } else {
                cal.style.display = 'block';
                const arrow = wrapper.querySelector('.date-arrow');
                if (arrow) arrow.textContent = '\u25B2';
                if (!wrapper.__calState) {
                    const input = wrapper.querySelector('input[type="date"]');
                    const fallback = new Date();
                    if (input && input.value) {
                        const p = input.value.split('-');
                        wrapper.__calState = { year: parseInt(p[0], 10), month: parseInt(p[1], 10) - 1 };
                    } else {
                        wrapper.__calState = { year: fallback.getFullYear(), month: fallback.getMonth() };
                    }
                }
                renderCalendar(wrapper, wrapper.__calState);
            }
        });

        const prev = cal.querySelector('.cal-prev');
        const next = cal.querySelector('.cal-next');
        if (prev) prev.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!wrapper.__calState) wrapper.__calState = { year: new Date().getFullYear(), month: new Date().getMonth() };
            wrapper.__calState.month--;
            if (wrapper.__calState.month < 0) { wrapper.__calState.month = 11; wrapper.__calState.year--; }
            renderCalendar(wrapper, wrapper.__calState);
        });
        if (next) next.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!wrapper.__calState) wrapper.__calState = { year: new Date().getFullYear(), month: new Date().getMonth() };
            wrapper.__calState.month++;
            if (wrapper.__calState.month > 11) { wrapper.__calState.month = 0; wrapper.__calState.year++; }
            renderCalendar(wrapper, wrapper.__calState);
        });

        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) {
                closeCalendar(wrapper);
            }
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDatePickers);
} else {
    initDatePickers();
}

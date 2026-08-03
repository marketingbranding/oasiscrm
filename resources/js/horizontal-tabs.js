const horizontalTabsSelector = '[data-horizontal-tabs]';
const activeTabSelector = [
    '[aria-selected="true"]',
    '[aria-current="page"]',
    '[aria-pressed="true"]',
    '[data-horizontal-tab-active="true"]',
].join(', ');

function normalizedDelta(value, mode, strip) {
    if (mode === WheelEvent.DOM_DELTA_LINE) return value * 16;
    if (mode === WheelEvent.DOM_DELTA_PAGE) return value * strip.clientWidth;

    return value;
}

function keepActiveTabVisible(strip) {
    const activeTab = strip.querySelector(activeTabSelector);
    if (!activeTab) return;

    const stripRect = strip.getBoundingClientRect();
    const activeRect = activeTab.getBoundingClientRect();
    if (activeRect.left < stripRect.left) {
        strip.scrollLeft -= stripRect.left - activeRect.left;
    } else if (activeRect.right > stripRect.right) {
        strip.scrollLeft += activeRect.right - stripRect.right;
    }
}

function handleWheel(strip, event) {
    const maxScrollLeft = strip.scrollWidth - strip.clientWidth;
    if (maxScrollLeft <= 1) return;

    const deltaX = normalizedDelta(event.deltaX, event.deltaMode, strip);
    const deltaY = normalizedDelta(event.deltaY, event.deltaMode, strip);

    // Let browsers retain native trackpad horizontal scrolling.
    if (Math.abs(deltaX) >= Math.abs(deltaY) || deltaY === 0) return;

    const movement = deltaY + deltaX;
    const canMove = movement > 0
        ? strip.scrollLeft < maxScrollLeft - 1
        : strip.scrollLeft > 1;

    // At either boundary, leave vertical wheel movement to the page.
    if (!canMove) return;

    event.preventDefault();
    strip.scrollLeft = Math.min(maxScrollLeft, Math.max(0, strip.scrollLeft + movement));
}

function initializeHorizontalTabs(strip) {
    if (strip.dataset.horizontalTabsReady === 'true') return;
    strip.dataset.horizontalTabsReady = 'true';

    strip.addEventListener('wheel', event => handleWheel(strip, event), { passive: false });

    let frame = null;
    const scheduleActiveTabVisibility = () => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => keepActiveTabVisible(strip));
    };

    const observer = new MutationObserver(scheduleActiveTabVisibility);
    observer.observe(strip, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-current', 'aria-pressed', 'aria-selected', 'data-horizontal-tab-active'],
    });

    if ('ResizeObserver' in window) {
        new ResizeObserver(scheduleActiveTabVisibility).observe(strip);
    }

    scheduleActiveTabVisibility();
}

export default function registerHorizontalTabs() {
    const initialize = () => document.querySelectorAll(horizontalTabsSelector).forEach(initializeHorizontalTabs);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}

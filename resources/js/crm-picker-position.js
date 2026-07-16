export function positionPickerPopup(wrapper, popup, displaySelector) {
    const display = wrapper.querySelector(displaySelector);
    if (!display || popup.style.display === 'none') return;

    const padding = 8;
    const gap = 4;
    const trigger = display.getBoundingClientRect();
    const width = Math.min(280, window.innerWidth - (padding * 2));
    popup.style.position = 'fixed';
    popup.style.width = width + 'px';
    popup.style.maxHeight = Math.max(220, window.innerHeight - (padding * 2)) + 'px';
    popup.style.overflowY = 'auto';
    popup.style.left = Math.min(Math.max(trigger.left, padding), window.innerWidth - width - padding) + 'px';

    const height = popup.offsetHeight;
    const spaceBelow = window.innerHeight - trigger.bottom - padding;
    const spaceAbove = trigger.top - padding;
    const openAbove = spaceBelow < height + gap && spaceAbove > spaceBelow;
    let top = openAbove ? trigger.top - height - gap : trigger.bottom + gap;
    top = Math.min(Math.max(top, padding), window.innerHeight - height - padding);
    popup.style.top = Math.max(padding, top) + 'px';
    popup.style.bottom = 'auto';
    popup.dataset.placement = openAbove ? 'top' : 'bottom';
}

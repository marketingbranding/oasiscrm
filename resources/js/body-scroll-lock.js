const locks = new Set();
let previousOverflow = '';

export function lockBodyScroll(owner) {
    if (locks.has(owner)) {
        return;
    }

    if (locks.size === 0) {
        previousOverflow = document.body.style.overflow;
    }

    locks.add(owner);
    document.body.style.overflow = 'hidden';
}

export function unlockBodyScroll(owner) {
    if (!locks.delete(owner) || locks.size > 0) {
        return;
    }

    document.body.style.overflow = previousOverflow;
    previousOverflow = '';
}

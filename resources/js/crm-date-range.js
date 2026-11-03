export function isDateWithinRange(dateString, minimumDate = '', maximumDate = '') {
    if (minimumDate && dateString < minimumDate) {
        return false;
    }

    if (maximumDate && dateString > maximumDate) {
        return false;
    }

    return true;
}

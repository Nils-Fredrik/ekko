// Categories that fall before the first day with measurements.

export function emptyBeforeSpan(categories, firstDate) {
    if (!firstDate || !Array.isArray(categories) || categories.length === 0 || categories[0] >= firstDate) {
        return null;
    }
    let end = categories[0];
    for (const day of categories) {
        if (day >= firstDate) {
            break;
        }
        end = day;
    }

    return end < firstDate ? [categories[0], end] : null;
}

// Prompt heatmap rows: one market, or a group per market when every market is in view
// (docs/ux-arbeidsflate.md 7).

export function heatmapRows(prompts, { marketSelected = false, unnamed = '' } = {}) {
    return [...prompts]
        .sort(
            (left, right) =>
                String(left.market ?? '').localeCompare(String(right.market ?? '')) ||
                String(left.text).localeCompare(String(right.text)),
        )
        .map((prompt) => ({
            key: String(prompt.id),
            label: prompt.text,
            group: marketSelected ? undefined : prompt.market || unnamed,
        }));
}

export function wrapLabel(text, width = 32) {
    const clean = String(text ?? '');
    if (clean.length <= width) {
        return clean;
    }
    const first = clean.slice(0, width);
    const rest = clean.slice(width);
    if (rest.length <= width) {
        return `${first}\n${rest}`;
    }

    return `${first}\n${rest.slice(0, width - 1)}…`;
}

export function rowHasCells(rowKey, cells) {
    return cells.some((cell) => String(cell.row) === String(rowKey));
}

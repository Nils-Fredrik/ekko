// Categories whose whole bucket ends before the first day with measurements.
// A weekly or monthly bucket that contains that day is left open.

function dayBefore(iso) {
    const date = new Date(`${iso}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() - 1);

    return date.toISOString().slice(0, 10);
}

function bucketEnd(categories, index) {
    if (categories[index + 1]) {
        return dayBefore(categories[index + 1]);
    }
    if (index > 0) {
        const start = Date.parse(`${categories[index]}T00:00:00Z`);
        const previous = Date.parse(`${categories[index - 1]}T00:00:00Z`);

        return new Date(start + (start - previous) - 86_400_000).toISOString().slice(0, 10);
    }

    return categories[index];
}

export function emptyBeforeSpan(categories, firstDate) {
    if (!firstDate || !Array.isArray(categories) || categories.length === 0) {
        return null;
    }
    let end = null;
    for (let index = 0; index < categories.length; index += 1) {
        if (bucketEnd(categories, index) >= firstDate) {
            break;
        }
        end = categories[index];
    }

    return end === null ? null : [categories[0], end];
}

/** Diagonal hatch for the SVG renderer. ECharts has no separate decal export. */
export function hatch(color) {
    const canvas = document.createElement('canvas');
    canvas.width = 8;
    canvas.height = 8;
    const context = canvas.getContext('2d');
    context.strokeStyle = color;
    context.beginPath();
    context.moveTo(0, 8);
    context.lineTo(8, 0);
    context.stroke();

    return { image: canvas, repeat: 'repeat' };
}

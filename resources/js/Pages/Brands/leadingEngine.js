import { isLowSample } from '../../Components/Charts/support/data.js';

// The engine named in the first sentence. A low sample (n < 10) is muted on the page
// and is not an answer (docs/spec.md 9).
export function pickLeadingEngine(engines) {
    const reliable = (engines ?? []).filter((item) => item?.visibility?.value != null && !isLowSample(item.visibility));

    reliable.sort((left, right) => right.visibility.value - left.visibility.value);

    return reliable[0] ?? null;
}

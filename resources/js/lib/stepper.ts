/**
 * Arrow keys in price and quantity fields: Up/Down change the value by one whole step.
 * Quantity of units that allow fractions (kg, litre ...) moves by 1/8 (0.125) instead.
 */
export const FRACTION_STEP = 0.125;

/** Step for a quantity box, from the pack unit's "allows fraction" flag. */
export const qtyStep = (allowsFraction?: boolean | null): number => (allowsFraction ? FRACTION_STEP : 1);

const round3 = (n: number) => Math.round(n * 1000) / 1000;

/**
 * Returns the new value for an ArrowUp/ArrowDown key (and stops the browser's own stepping),
 * or null for any other key. Never goes below `min`.
 */
export function stepOnArrow(e: KeyboardEvent, current: string | number | null | undefined, step: number, min = 0): string | null {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return null;
    e.preventDefault();
    const base = Number(current);
    const from = Number.isFinite(base) ? base : 0;
    return String(Math.max(min, round3(from + (e.key === 'ArrowUp' ? step : -step))));
}

export const roundQty = round3;

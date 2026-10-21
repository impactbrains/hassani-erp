/**
 * Code 39 barcode (every scanner reads it) so a receipt or order number printed on paper can be
 * scanned at the POS. Letters A-Z, digits, "-", "." and space only; anything else returns null.
 * Each character is 9 elements (bar, space, bar ...), 3 of them wide; "n" = narrow, "w" = wide.
 */
const PATTERNS: Record<string, string> = {
    '0': 'nnnwwnwnn', '1': 'wnnwnnnnw', '2': 'nnwwnnnnw', '3': 'wnwwnnnnn', '4': 'nnnwwnnnw', '5': 'wnnwwnnnn', '6': 'nnwwwnnnn', '7': 'nnnwnnwnw', '8': 'wnnwnnwnn', '9': 'nnwwnnwnn',
    A: 'wnnnnwnnw', B: 'nnwnnwnnw', C: 'wnwnnwnnn', D: 'nnnnwwnnw', E: 'wnnnwwnnn', F: 'nnwnwwnnn', G: 'nnnnnwwnw', H: 'wnnnnwwnn', I: 'nnwnnwwnn', J: 'nnnnwwwnn',
    K: 'wnnnnnnww', L: 'nnwnnnnww', M: 'wnwnnnnwn', N: 'nnnnwnnww', O: 'wnnnwnnwn', P: 'nnwnwnnwn', Q: 'nnnnnnwww', R: 'wnnnnnwwn', S: 'nnwnnnwwn', T: 'nnnnwnwwn',
    U: 'wwnnnnnnw', V: 'nwwnnnnnw', W: 'wwwnnnnnn', X: 'nwnnwnnnw', Y: 'wwnnwnnnn', Z: 'nwwnwnnnn',
    '-': 'nwnnnnwnw', '.': 'wwnnnnwnn', ' ': 'nwwnnnwnn', '*': 'nwnnwnwnn',
};
const WIDE = 3;

export interface Code39 { width: number; bars: { x: number; w: number }[] }

export function code39(text: string): Code39 | null {
    const value = text.toUpperCase();
    if (!value || [...value].some((c) => !PATTERNS[c] || c === '*')) return null;
    const bars: { x: number; w: number }[] = [];
    let x = 0;
    for (const ch of `*${value}*`) {
        [...PATTERNS[ch]].forEach((el, i) => {
            const w = el === 'w' ? WIDE : 1;
            if (i % 2 === 0) bars.push({ x, w });
            x += w;
        });
        x += 1; // narrow gap between characters
    }
    return { width: x - 1, bars };
}

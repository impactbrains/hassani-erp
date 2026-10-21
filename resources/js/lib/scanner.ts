/**
 * Barcode scanner detection for USB and Bluetooth scanners (they act as a keyboard).
 *
 * A scanner "types" a whole code in a few hundredths of a second and then presses Enter. A person
 * never does. So: a run of printable keys where every key follows the last within MAX_GAP_MS and
 * which ends in Enter, with at least MIN_LENGTH characters, is a scan. Normal typing, even fast
 * typing, is left completely alone (it never reaches that speed), so search and note boxes work as usual.
 *
 * Works wherever the cursor is. The keys of a scan that already landed in a text box are taken out
 * again, and the Enter is swallowed so nothing else (a button, a dialog) reacts to it.
 */
export interface ScannerOptions {
    /** Called with the scanned code (whatever has the focus). */
    onScan: (code: string) => void;
    minLength?: number;
    maxGapMs?: number;
}

export interface Scanner {
    stop: () => void;
    /** True while a fast run of keys is arriving, so other key shortcuts can ignore those keys. */
    isBursting: () => boolean;
}

export function installScanner(options: ScannerOptions): Scanner {
    const minLength = options.minLength ?? 4;
    const maxGap = options.maxGapMs ?? 60;
    let buffer = '';
    let last = 0;
    let startEl: Element | null = null;
    let startValue: string | null = null;

    const reset = () => { buffer = ''; startEl = null; startValue = null; };
    const textual = (el: Element | null): el is HTMLInputElement | HTMLTextAreaElement => el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement;

    function undoTypedKeys() {
        const active = document.activeElement;
        if (textual(active) && active === startEl && startValue !== null && active.value !== startValue) {
            active.value = startValue;
            active.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function onKeyDown(e: KeyboardEvent) {
        if (e.ctrlKey || e.altKey || e.metaKey || e.isComposing) return;
        const now = performance.now();
        if (e.key === 'Enter') {
            const isScan = buffer.length >= minLength && now - last <= maxGap;
            if (isScan) {
                e.preventDefault();
                e.stopImmediatePropagation();
                undoTypedKeys();
                const code = buffer;
                reset();
                options.onScan(code);
                return;
            }
            reset();
            return;
        }
        if (e.key.length !== 1) return; // Shift, arrows, F-keys ... neither start nor break a scan
        if (buffer && now - last > maxGap) reset();
        if (!buffer) {
            startEl = document.activeElement;
            startValue = textual(startEl) ? startEl.value : null;
        }
        buffer += e.key;
        last = now;
    }

    window.addEventListener('keydown', onKeyDown, true);
    return {
        stop: () => window.removeEventListener('keydown', onKeyDown, true),
        isBursting: () => buffer.length >= 2 && performance.now() - last <= maxGap,
    };
}

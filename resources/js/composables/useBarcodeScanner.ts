import { onMounted, onUnmounted } from 'vue';

/**
 * Detects input from a hardware keyboard-wedge barcode scanner by
 * distinguishing its fast, uniform keystroke bursts from normal human typing.
 *
 * Scanners "type" a barcode followed by Enter, all within milliseconds.
 * Humans typing the same characters take much longer between keystrokes.
 *
 * Usage:
 *   useBarcodeScanner((code) => {
 *     console.log('Scanned:', code);
 *   });
 */
export function useBarcodeScanner(onScan: (code: string) => void, options?: { minLength?: number; maxKeystrokeGapMs?: number }) {
    const minLength = options?.minLength ?? 3;
    const maxGap = options?.maxKeystrokeGapMs ?? 30;

    let buffer = '';
    let lastKeyTime = Date.now();

    function handleKeydown(e: KeyboardEvent) {
        const now = Date.now();
        const gap = now - lastKeyTime;
        lastKeyTime = now;

        if (e.key === 'Enter') {
            if (buffer.length >= minLength) {
                onScan(buffer);
            }
            buffer = '';
            return;
        }

        // Ignore modifier/navigation keys (Shift, Control, Arrow keys, etc.)
        if (e.key.length > 1) {
            return;
        }

        // A pause longer than a scanner's burst rate means a human is typing —
        // reset the buffer so we don't misfire on manual entry.
        if (gap > maxGap) {
            buffer = '';
        }

        buffer += e.key;
    }

    onMounted(() => {
        document.body.addEventListener('keydown', handleKeydown);
    });

    onUnmounted(() => {
        document.body.removeEventListener('keydown', handleKeydown);
    });
}
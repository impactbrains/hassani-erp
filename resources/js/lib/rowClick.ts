import { router } from '@inertiajs/vue3';

const INTERACTIVE = 'a,button,input,select,textarea,label,summary,[role="button"],[data-no-row-click]';

/**
 * Opens `href` when a table row is clicked anywhere. Clicks on links, buttons,
 * checkboxes and other controls inside the row keep their own behaviour, and
 * selecting text with the mouse does not navigate. Ctrl/Cmd-click opens a new tab.
 */
export function openRow(event: MouseEvent, href: string | null | undefined): void {
    if (!href || event.defaultPrevented) return;
    if ((event.target as HTMLElement | null)?.closest(INTERACTIVE)) return;
    if (window.getSelection()?.toString()) return;
    if (event.ctrlKey || event.metaKey) {
        window.open(href, '_blank');
        return;
    }
    router.visit(href);
}

/**
 * Push a message to the global ARIA live announcer. Use for toasts,
 * flash messages, and any status update that's only conveyed visually
 * by default.
 *
 *   import { announce } from '@/composables/useAriaAnnounce';
 *
 *   announce('Saved.');                                  // polite (default)
 *   announce('Two-factor required.', 'assertive');       // interrupts
 *
 * Polite messages queue and read when the SR is idle; assertive
 * interrupts the user immediately — reserve it for errors and
 * security-sensitive prompts.
 */
export function announce(text, priority = 'polite') {
    if (typeof window === 'undefined') return;
    window.dispatchEvent(new CustomEvent('cihrms:announce', {
        detail: { text: String(text ?? ''), priority },
    }));
}

export function useAriaAnnounce() {
    return { announce };
}

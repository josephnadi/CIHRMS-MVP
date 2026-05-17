import { onMounted, onBeforeUnmount, watch, isRef } from 'vue';

/**
 * Focus-trap composable for modals, slide-panels, and any overlay where
 * keyboard focus must stay inside while the overlay is open. WCAG 2.1.2
 * (No Keyboard Trap — but the *right* kind: the trap is enforced ONLY
 * while the dialog is visible, and Escape releases focus back to the
 * previously-focused element).
 *
 * Usage in a modal/SlidePanel:
 *
 *   <div ref="panel">
 *       <slot />
 *   </div>
 *
 *   const panel = ref(null);
 *   const open  = ref(true);
 *   useFocusTrap(panel, open, { onEscape: () => open.value = false });
 *
 * Behaviour:
 *   - On open: focuses the first focusable element inside the container,
 *     remembers the previously-focused element to restore later.
 *   - Tab / Shift-Tab cycle within the container.
 *   - Escape calls the optional `onEscape` callback (typically: close).
 *   - On close: focus returns to the previously-focused element.
 */
export function useFocusTrap(containerRef, openRef, options = {}) {
    const { onEscape } = options;
    let previouslyFocused = null;

    const focusableSelector = [
        'a[href]:not([disabled])',
        'button:not([disabled])',
        'textarea:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',');

    const focusables = () => {
        const el = containerRef?.value;
        if (!el) return [];
        return Array.from(el.querySelectorAll(focusableSelector)).filter(
            (n) => n.offsetParent !== null || n === document.activeElement,
        );
    };

    const onKeyDown = (e) => {
        if (!openRef.value) return;
        const list = focusables();

        if (e.key === 'Escape' && onEscape) {
            e.preventDefault();
            onEscape();
            return;
        }

        if (e.key !== 'Tab' || list.length === 0) return;

        const first = list[0];
        const last  = list[list.length - 1];

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    };

    const activate = () => {
        previouslyFocused = document.activeElement;
        // Defer one tick so v-if'd content is mounted before we focus.
        requestAnimationFrame(() => {
            const list = focusables();
            (list[0] ?? containerRef?.value)?.focus?.();
        });
        document.addEventListener('keydown', onKeyDown);
    };

    const deactivate = () => {
        document.removeEventListener('keydown', onKeyDown);
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            try { previouslyFocused.focus(); } catch (e) { /* node detached */ }
        }
        previouslyFocused = null;
    };

    onMounted(() => {
        if (isRef(openRef) ? openRef.value : openRef) activate();
    });
    onBeforeUnmount(deactivate);

    // React to open/close
    if (isRef(openRef)) {
        watch(openRef, (open) => (open ? activate() : deactivate()));
    }

    return { activate, deactivate };
}

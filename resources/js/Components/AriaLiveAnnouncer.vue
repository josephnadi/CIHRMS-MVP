<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';

/**
 * Global ARIA live region — WCAG 4.1.3 (Status Messages).
 *
 * Listens for the `cihrms:announce` window event and pushes the message
 * into a polite (or assertive) live region so screen-reader users hear
 * the same toast / flash / status update sighted users see.
 *
 *   import { announce } from '@/composables/useAriaAnnounce';
 *   announce('Leave request submitted', 'polite');     // success/info
 *   announce('Payroll approval failed', 'assertive');  // errors
 *
 * Two separate <div>s — one polite, one assertive — keeps Apple/JAWS
 * VoiceOver consistent with the WAI-ARIA Authoring Practices guidance.
 */

const politeMessage    = ref('');
const assertiveMessage = ref('');

const onAnnounce = (event) => {
    const detail = event.detail ?? {};
    const text   = String(detail.text ?? '').trim();
    if (text === '') return;

    // Clear first so the same text re-announces (live regions ignore
    // identical re-sets unless cleared between).
    if (detail.priority === 'assertive') {
        assertiveMessage.value = '';
        requestAnimationFrame(() => { assertiveMessage.value = text; });
    } else {
        politeMessage.value = '';
        requestAnimationFrame(() => { politeMessage.value = text; });
    }
};

onMounted(() => window.addEventListener('cihrms:announce', onAnnounce));
onBeforeUnmount(() => window.removeEventListener('cihrms:announce', onAnnounce));
</script>

<template>
    <div aria-live="polite"    aria-atomic="true" class="sr-only">{{ politeMessage }}</div>
    <div aria-live="assertive" aria-atomic="true" class="sr-only">{{ assertiveMessage }}</div>
</template>

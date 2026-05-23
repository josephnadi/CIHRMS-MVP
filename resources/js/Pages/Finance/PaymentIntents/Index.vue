<script setup>
// Stub — Task 11 replaces with the real Payment Intents page.
defineProps({
    intents:      { type: Object, default: () => ({ data: [] }) },
    filters:      { type: Object, default: () => ({}) },
    customers:    { type: Array,  default: () => [] },
    openInvoices: { type: Array,  default: () => [] },
    focusIntent:  { type: [Object, null], default: null },
});
</script>

<template>
    <div>Payment Intents (stub)</div>
</template>

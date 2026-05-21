<script setup>
// Stub — Task 12 replaces with the real bank accounts page.
defineProps({
    banks:         { type: Object, default: () => ({ data: [] }) },
    filters:       { type: Object, default: () => ({}) },
    assetAccounts: { type: Array,  default: () => [] },
});
</script>

<template>
    <div>Bank Accounts (stub)</div>
</template>

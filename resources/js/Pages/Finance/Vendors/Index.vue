<script setup>
// Stub — Task 14 replaces with the real Vendors index page.
defineProps({
    vendors:         { type: Object, default: () => ({ data: [] }) },
    filters:         { type: Object, default: () => ({}) },
    expenseAccounts: { type: Array,  default: () => [] },
    apAccounts:      { type: Array,  default: () => [] },
    bankAccounts:    { type: Array,  default: () => [] },
});
</script>

<template>
    <div>Vendors (stub)</div>
</template>

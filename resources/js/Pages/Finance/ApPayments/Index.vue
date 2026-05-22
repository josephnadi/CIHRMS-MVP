<script setup>
defineProps({
    payments: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    vendors: { type: Array, default: () => [] },
    openInvoices: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
});
</script>
<template><div>AP Payments (stub)</div></template>

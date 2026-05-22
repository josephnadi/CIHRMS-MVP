<script setup>
defineProps({
    invoices: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    vendors: { type: Array, default: () => [] },
    expenseAccounts: { type: Array, default: () => [] },
});
</script>
<template><div>AP Invoices (stub)</div></template>

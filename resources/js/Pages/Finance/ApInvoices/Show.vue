<script setup>
defineProps({ invoice: { type: Object, required: true } });
</script>
<template><div>Invoice {{ invoice.reference }} (stub)</div></template>

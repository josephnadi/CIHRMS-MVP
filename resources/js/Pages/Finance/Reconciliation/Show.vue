<script setup>
defineProps({
    statement:      { type: Object, required: true },
    lines:          { type: Array, default: () => [] },
    unreconciledAp: { type: Array, default: () => [] },
    unreconciledAr: { type: Array, default: () => [] },
});
</script>

<template>
    <div>Reconciliation detail (stub)</div>
</template>

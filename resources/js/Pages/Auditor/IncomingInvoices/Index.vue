<script setup>
// Placeholder — full implementation lands in Task 9 (Auditors module Vue pages).
// This stub exists only so `vite build` emits a manifest chunk for
// `resources/js/Pages/Auditor/IncomingInvoices/Index.vue`, which
// `resources/views/app.blade.php` requires via @vite() on every full-page
// (non-XHR) Inertia render — without it, feature tests that GET this route
// 500 with a ViteException even though assertInertia() only checks the
// component name string.
defineProps({
    activeModule: { type: String, default: 'auditor-incoming-invoices' },
    invoices:     { type: Object, default: () => ({}) },
    filters:      { type: Object, default: () => ({}) },
    statuses:     { type: Array, default: () => [] },
});
</script>

<template>
    <div />
</template>

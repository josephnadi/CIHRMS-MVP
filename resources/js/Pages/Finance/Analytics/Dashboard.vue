<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

defineProps({
    year:   { type: Number },
    from:   { type: String },
    to:     { type: String },
    kpis:   { type: Object },
    trends: { type: Object },
});
</script>

<template>
    <Head title="Finance Analytics" />

    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6">
            <h1 class="text-2xl font-black text-primary">Finance Analytics</h1>
        </header>

        <p class="text-on-surface-variant text-sm">Dashboard coming together…</p>
    </div>
</template>

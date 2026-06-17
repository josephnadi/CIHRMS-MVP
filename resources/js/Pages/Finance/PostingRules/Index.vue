<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

defineProps({
    rules:      { type: [Object, Array], default: () => [] },
    glAccounts: { type: [Object, Array], default: () => [] },
});
</script>

<template>
    <Head title="Posting Rules" />
</template>

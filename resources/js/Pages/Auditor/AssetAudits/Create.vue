<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    categories: { type: Array, default: () => [] },
});

const form = useForm({ scope_type: 'all', scope_value: '', notes: '' });

function submit() {
    form.post(route('auditor.asset-audits.store'));
}
</script>

<template>
    <Head title="New Asset Audit" />
    <AuthenticatedLayout>
        <form @submit.prevent="submit" class="p-6 max-w-xl space-y-4">
            <h1 class="text-2xl font-semibold text-primary">New Asset Audit</h1>

            <div>
                <label class="block text-sm text-on-surface-variant">Scope</label>
                <select v-model="form.scope_type" aria-label="Audit scope" class="w-full rounded-lg border-outline-variant">
                    <option value="all">All assets</option>
                    <option value="category">By category</option>
                    <option value="location">By location</option>
                </select>
            </div>

            <div v-if="form.scope_type === 'category'">
                <label class="block text-sm text-on-surface-variant">Category</label>
                <select v-model="form.scope_value" aria-label="Category" class="w-full rounded-lg border-outline-variant">
                    <option value="">Select…</option>
                    <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
                <div v-if="form.errors.scope_value" class="text-error text-xs">{{ form.errors.scope_value }}</div>
            </div>

            <div v-if="form.scope_type === 'location'">
                <label class="block text-sm text-on-surface-variant">Location</label>
                <input v-model="form.scope_value" aria-label="Location" class="w-full rounded-lg border-outline-variant" />
                <div v-if="form.errors.scope_value" class="text-error text-xs">{{ form.errors.scope_value }}</div>
            </div>

            <div>
                <label class="block text-sm text-on-surface-variant">Notes</label>
                <textarea v-model="form.notes" aria-label="Notes" class="w-full rounded-lg border-outline-variant"></textarea>
            </div>

            <button :disabled="form.processing" class="rounded-lg bg-primary text-on-primary px-4 py-2">Open audit</button>
        </form>
    </AuthenticatedLayout>
</template>

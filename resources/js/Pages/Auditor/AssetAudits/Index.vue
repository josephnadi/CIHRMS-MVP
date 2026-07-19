<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    audits: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
});

const status = ref(props.filters.status ?? '');

function applyFilters() {
    router.get(route('auditor.asset-audits.index'), { status: status.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Asset Audits" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-primary">Asset Audits</h1>
                <Link :href="route('auditor.asset-audits.create')" class="rounded-lg bg-primary text-on-primary px-4 py-2 text-sm">New audit</Link>
            </div>

            <select v-model="status" @change="applyFilters" aria-label="Filter by status" class="rounded-lg border-outline-variant text-sm">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>

            <table class="w-full text-sm">
                <thead class="text-left text-on-surface-variant border-b border-outline-variant/60">
                    <tr><th class="py-2">Reference</th><th>Scope</th><th>Coverage</th><th>Discrepancies</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr v-for="a in audits.data" :key="a.id" class="border-b border-outline-variant/40 hover:bg-surface-container-lowest">
                        <td class="py-2"><Link :href="route('auditor.asset-audits.show', a.id)" class="text-primary">{{ a.reference }}</Link></td>
                        <td>{{ a.scope_type }}<span v-if="a.scope_value"> — {{ a.scope_value }}</span></td>
                        <td>{{ a.counted_lines }} / {{ a.total_lines }}</td>
                        <td>{{ a.discrepancy_lines }}</td>
                        <td>{{ a.status.label }}</td>
                    </tr>
                    <tr v-if="!audits.data.length"><td colspan="5" class="py-6 text-center text-on-surface-variant">No audits yet.</td></tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>

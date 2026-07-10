<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    invoices: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
});

const status = ref(props.filters.status ?? '');
const search = ref(props.filters.search ?? '');

function applyFilters() {
    router.get(route('auditor.incoming-invoices.index'), { status: status.value, search: search.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Incoming Invoices" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-primary">Incoming Invoices</h1>
                <Link :href="route('auditor.incoming-invoices.create')" class="rounded-lg bg-secondary text-white px-4 py-2 text-sm">New invoice</Link>
            </div>

            <div class="flex gap-3">
                <select v-model="status" @change="applyFilters" aria-label="Filter by status" class="rounded-lg border-outline-variant bg-surface-container-low text-on-surface text-sm">
                    <option value="">All statuses</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <input v-model="search" @keyup.enter="applyFilters" placeholder="Search vendor…" aria-label="Search vendor" class="rounded-lg border-outline-variant bg-surface-container-low text-on-surface text-sm" />
            </div>

            <table class="w-full text-sm">
                <thead class="text-left text-on-surface-variant border-b border-outline-variant/60">
                    <tr><th class="py-2">Reference</th><th>Vendor</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <tr v-for="inv in invoices.data" :key="inv.id" class="border-b border-outline-variant/40 hover:bg-surface-container-low/60">
                        <td class="py-2">
                            <Link :href="route('auditor.incoming-invoices.show', inv.id)" class="text-secondary">{{ inv.reference }}</Link>
                        </td>
                        <td class="text-on-surface">{{ inv.vendor_name }}</td>
                        <td class="text-on-surface">{{ inv.currency }} {{ inv.amount.toFixed(2) }}</td>
                        <td class="text-on-surface">{{ inv.status.label }}</td>
                        <td class="text-on-surface">{{ inv.invoice_date }}</td>
                    </tr>
                    <tr v-if="!invoices.data.length"><td colspan="5" class="py-6 text-center text-on-surface-variant/60">No invoices.</td></tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>

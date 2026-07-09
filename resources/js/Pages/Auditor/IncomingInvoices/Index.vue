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
                <h1 class="text-2xl font-semibold">Incoming Invoices</h1>
                <Link :href="route('auditor.incoming-invoices.create')" class="rounded-lg bg-blue-600 text-white px-4 py-2 text-sm">New invoice</Link>
            </div>

            <div class="flex gap-3">
                <select v-model="status" @change="applyFilters" class="rounded-lg border-gray-300 text-sm">
                    <option value="">All statuses</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <input v-model="search" @keyup.enter="applyFilters" placeholder="Search vendor…" class="rounded-lg border-gray-300 text-sm" />
            </div>

            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 border-b">
                    <tr><th class="py-2">Reference</th><th>Vendor</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <tr v-for="inv in invoices.data" :key="inv.id" class="border-b hover:bg-gray-50">
                        <td class="py-2">
                            <Link :href="route('auditor.incoming-invoices.show', inv.id)" class="text-blue-600">{{ inv.reference }}</Link>
                        </td>
                        <td>{{ inv.vendor_name }}</td>
                        <td>{{ inv.currency }} {{ inv.amount.toFixed(2) }}</td>
                        <td>{{ inv.status.label }}</td>
                        <td>{{ inv.invoice_date }}</td>
                    </tr>
                    <tr v-if="!invoices.data.length"><td colspan="5" class="py-6 text-center text-gray-400">No invoices.</td></tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>

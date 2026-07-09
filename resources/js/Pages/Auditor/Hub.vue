<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    stats: { type: Object, required: true },
    links: { type: Object, required: true },
});
</script>

<template>
    <Head title="Auditor Hub" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-6">
            <h1 class="text-2xl font-semibold">Auditor Hub</h1>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <Link :href="route('auditor.incoming-invoices.index', { status: 'submitted' })" class="rounded-xl border p-4 hover:shadow">
                    <div class="text-3xl font-bold">{{ stats.pending_vetting }}</div>
                    <div class="text-sm text-gray-500">Pending vetting</div>
                </Link>
                <Link :href="route('auditor.incoming-invoices.index', { status: 'vetted' })" class="rounded-xl border p-4 hover:shadow">
                    <div class="text-3xl font-bold">{{ stats.pending_ceo }}</div>
                    <div class="text-sm text-gray-500">Awaiting CEO</div>
                </Link>
                <Link :href="route('auditor.incoming-invoices.index', { status: 'approved' })" class="rounded-xl border p-4 hover:shadow">
                    <div class="text-3xl font-bold">{{ stats.approved }}</div>
                    <div class="text-sm text-gray-500">Awaiting posting</div>
                </Link>
                <Link :href="route('auditor.incoming-invoices.index', { status: 'returned' })" class="rounded-xl border p-4 hover:shadow">
                    <div class="text-3xl font-bold">{{ stats.returned }}</div>
                    <div class="text-sm text-gray-500">Returned</div>
                </Link>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Link v-if="links.assets" :href="route('assets.index')" class="rounded-xl border p-4 hover:shadow">
                    <div class="font-medium">Assets Oversight</div>
                    <div class="text-sm text-gray-500">Review the asset registry</div>
                </Link>
                <!-- Route name confirmed via `php artisan route:list` on 2026-07-09: the
                     auditor-general report pack group is registered as `ag-reports.*`,
                     not `reports.auditor-general` (which does not exist). -->
                <Link v-if="links.reports" :href="route('ag-reports.index')" class="rounded-xl border p-4 hover:shadow">
                    <div class="font-medium">Audit Report Packs</div>
                    <div class="text-sm text-gray-500">Downloadable auditor reports</div>
                </Link>
                <!-- Route name confirmed via `php artisan route:list` on 2026-07-09: there is
                     no `audit.index` route. The direct, permission-gated (audit.view) page is
                     `audit-logs.index` (routes/web.php); `modules.audit-logs` is only a
                     redirect wrapper to the same page, so we link straight to it. -->
                <Link v-if="links.audit" :href="route('audit-logs.index')" class="rounded-xl border p-4 hover:shadow">
                    <div class="font-medium">Audit Log</div>
                    <div class="text-sm text-gray-500">System activity trail</div>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    contracts:    Object,
    cycles:       Array,
    filters:      Object,
    activeModule: String,
});

const localFilters = reactive({
    cycle_id: props.filters?.cycle_id ?? '',
    status:   props.filters?.status   ?? '',
});

const applyFilters = () => router.get(route('performance.contracts.index'), {
    cycle_id: localFilters.cycle_id || undefined,
    status:   localFilters.status   || undefined,
}, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Performance Contracts" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div>
                <p class="text-xs text-on-surface-variant/60">PSC Performance Management Policy Framework · 2015</p>
                <h1 class="text-2xl font-semibold tracking-tight">Performance Contracts</h1>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex flex-wrap gap-3 items-center">
                    <select v-model="localFilters.cycle_id" @change="applyFilters" class="rounded-lg border-outline-variant text-sm">
                        <option value="">All cycles</option>
                        <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }} ({{ c.status }})</option>
                    </select>
                    <select v-model="localFilters.status" @change="applyFilters" class="rounded-lg border-outline-variant text-sm">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="pending_signature">Pending Signature</option>
                        <option value="active">Active</option>
                        <option value="achieved">Achieved</option>
                        <option value="missed">Missed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div v-if="contracts?.data?.length === 0">
                    <EmptyState title="No contracts yet"
                                description="Performance contracts will appear once HR drafts them for a cycle." />
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Employee</th>
                            <th class="px-5 py-3 text-left">Cycle</th>
                            <th class="px-5 py-3 text-left">Supervisor</th>
                            <th class="px-5 py-3 text-left">KPIs</th>
                            <th class="px-5 py-3 text-right">Achievement</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="c in contracts.data" :key="c.id" class="hover:bg-surface-container-low/60">
                            <td class="px-5 py-3">
                                <div class="font-medium">{{ c.employee?.name }}</div>
                                <div class="text-xs text-on-surface-variant/60">{{ c.employee?.employee_no }} · {{ c.employee?.department }}</div>
                            </td>
                            <td class="px-5 py-3">{{ c.cycle?.name }}</td>
                            <td class="px-5 py-3 text-sm">{{ c.supervisor?.name ?? '—' }}</td>
                            <td class="px-5 py-3 text-xs">{{ c.kpis?.length ?? 0 }} KPIs</td>
                            <td class="px-5 py-3 text-right">
                                <span v-if="c.weighted_achievement !== null"
                                      class="font-semibold"
                                      :class="c.weighted_achievement >= 60 ? 'text-emerald-700' : 'text-rose-700'">
                                    {{ c.weighted_achievement.toFixed(1) }}%
                                </span>
                                <span v-else class="text-on-surface-variant/40">—</span>
                            </td>
                            <td class="px-5 py-3"><StatusBadge :status="c.status" :label="c.status_label" /></td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('performance.contracts.show', c.id)" class="text-secondary hover:underline">Open</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-outline-variant/40">
                    <Pagination :links="contracts?.meta?.links ?? []" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    pips:         Object,
    stats:        Object,
    filters:      Object,
    activeModule: String,
});

const localFilters = reactive({ status: props.filters?.status ?? '' });
const applyFilters = () => router.get(route('performance.pips.index'), {
    status: localFilters.status || undefined,
}, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Performance Improvement Plans" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div>
                <p class="text-xs text-on-surface-variant/60">Required step before non-disciplinary termination · Labour Act §63</p>
                <h1 class="text-2xl font-semibold tracking-tight">Performance Improvement Plans</h1>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-3 gap-4">
                <StatCard label="Open" :value="stats.open_total" />
                <StatCard label="Succeeded this year" :value="stats.succeeded_ytd" tone="success" />
                <StatCard label="Failed → Termination" :value="stats.terminated_ytd" tone="danger" />
            </div>

            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex items-center gap-3">
                    <select v-model="localFilters.status" @change="applyFilters" class="rounded-lg border-outline-variant text-sm">
                        <option value="">All statuses</option>
                        <option value="open">Open</option>
                        <option value="in_progress">In Progress</option>
                        <option value="extended">Extended</option>
                        <option value="succeeded">Succeeded</option>
                        <option value="failed_demoted">Failed — Demoted</option>
                        <option value="failed_terminated">Failed — Terminated</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <table class="w-full text-sm">
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Employee</th>
                            <th class="px-5 py-3 text-left">Opened</th>
                            <th class="px-5 py-3 text-left">Target end</th>
                            <th class="px-5 py-3 text-left">Mentor</th>
                            <th class="px-5 py-3 text-right">Metrics</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="p in pips.data" :key="p.id" class="hover:bg-surface-container-low/60">
                            <td class="px-5 py-3">
                                <div class="font-medium">{{ p.employee?.name }}</div>
                                <div class="text-xs text-on-surface-variant/60">{{ p.employee?.employee_no }} · {{ p.employee?.department }}</div>
                            </td>
                            <td class="px-5 py-3 text-xs">{{ p.opened_on }}</td>
                            <td class="px-5 py-3 text-xs">{{ p.target_end_date }}</td>
                            <td class="px-5 py-3 text-xs">{{ p.mentor?.name ?? '—' }}</td>
                            <td class="px-5 py-3 text-right">{{ p.target_metrics?.length ?? 0 }}</td>
                            <td class="px-5 py-3"><StatusBadge :status="p.status" :label="p.status_label" /></td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('performance.pips.show', p.id)" class="text-secondary hover:underline">Open</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-outline-variant/40">
                    <Pagination :links="pips?.meta?.links ?? []" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    reports:      Object,
    stats:        Object,
    filters:      Object,
    activeModule: String,
});

const localFilters = reactive({
    status:   props.filters?.status   ?? '',
    severity: props.filters?.severity ?? '',
    category: props.filters?.category ?? '',
});

const applyFilters = () => router.get(route('whistleblower.admin.index'), {
    status:   localFilters.status   || undefined,
    severity: localFilters.severity || undefined,
    category: localFilters.category || undefined,
}, { preserveState: true, replace: true });

const severityClass = (s) => ({
    'critical': 'bg-rose-100 text-rose-800',
    'high':     'bg-amber-100 text-amber-800',
    'medium':   'bg-blue-100 text-blue-800',
    'low':      'bg-slate-100 text-slate-600',
}[s] ?? 'bg-slate-100 text-slate-500');
</script>

<template>
    <Head title="Whistleblower — Investigator Dashboard" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div>
                <p class="text-xs text-on-surface-variant/60">Segregated investigation channel · Act 720</p>
                <h1 class="text-2xl font-semibold tracking-tight">Whistleblower Cases</h1>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="Open cases"        :value="stats.open_total" />
                <StatCard label="Awaiting triage"   :value="stats.awaiting_triage" tone="warn" />
                <StatCard label="Critical open"     :value="stats.critical_open" tone="danger" />
                <StatCard label="Closed this year"  :value="stats.closed_ytd" />
            </div>

            <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex flex-wrap gap-3 items-center">
                    <select v-model="localFilters.status" @change="applyFilters" class="rounded-lg border-outline-variant text-sm">
                        <option value="">All statuses</option>
                        <option value="submitted">Submitted</option>
                        <option value="triaged">Triaged</option>
                        <option value="investigating">Investigating</option>
                        <option value="evidence_gathering">Evidence Gathering</option>
                        <option value="closed_substantiated">Closed — Substantiated</option>
                        <option value="closed_unsubstantiated">Closed — Unsubstantiated</option>
                        <option value="closed_referred">Closed — Referred</option>
                        <option value="withdrawn">Withdrawn</option>
                    </select>
                    <select v-model="localFilters.severity" @change="applyFilters" class="rounded-lg border-outline-variant text-sm">
                        <option value="">All severities</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>

                <div v-if="reports?.data?.length === 0">
                    <EmptyState title="No cases in your queue"
                                description="When a new disclosure is filed, it will appear here." />
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Case #</th>
                            <th class="px-5 py-3 text-left">Subject</th>
                            <th class="px-5 py-3 text-left">Category</th>
                            <th class="px-5 py-3 text-left">Severity</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-left">Received</th>
                            <th class="px-5 py-3 text-left">Investigator</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="r in reports.data" :key="r.id" class="hover:bg-surface-container-low/60">
                            <td class="px-5 py-3 font-mono text-xs">{{ r.case_number }}</td>
                            <td class="px-5 py-3">
                                <div class="font-medium">{{ r.subject_summary }}</div>
                                <div class="text-xs text-on-surface-variant/60" v-if="r.is_anonymous">Anonymous submission</div>
                            </td>
                            <td class="px-5 py-3">{{ r.category_label }}</td>
                            <td class="px-5 py-3">
                                <span v-if="r.severity"
                                      :class="severityClass(r.severity)"
                                      class="text-xs px-2 py-0.5 rounded-full font-semibold uppercase">
                                    {{ r.severity }}
                                </span>
                                <span v-else class="text-xs text-on-surface-variant/40">—</span>
                            </td>
                            <td class="px-5 py-3"><StatusBadge :status="r.status" :label="r.status_label" /></td>
                            <td class="px-5 py-3 text-xs">{{ r.received_at ? new Date(r.received_at).toLocaleDateString('en-GH') : '—' }}</td>
                            <td class="px-5 py-3 text-xs">{{ r.investigator?.name ?? '—' }}</td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('whistleblower.admin.show', r.id)" class="text-secondary hover:underline">Open</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-outline-variant/40">
                    <Pagination :links="reports?.meta?.links ?? []" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    position:    { type: Object, required: true },
    assignments: { type: Array,  default: () => [] },
});

const P = computed(() => props.position?.data ?? props.position);

const activeAssignments = computed(() =>
    props.assignments.filter(a => !a.end_date)
);
const pastAssignments = computed(() =>
    props.assignments.filter(a => a.end_date),
);
</script>

<template>
    <Head :title="`Position — ${P.code ?? P.title ?? P.name}`" />

    <div class="space-y-6 animate-reveal-up">
        <div>
            <Link :href="route('positions.index')" class="text-[11px] font-bold text-secondary hover:underline">← Back to positions</Link>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">ESTABLISHMENT · POSITION</p>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">
                        {{ P.title ?? P.name ?? '—' }}
                    </h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        <span class="font-mono">{{ P.code }}</span>
                        <span v-if="P.department?.name"> · {{ P.department.name }}</span>
                        <span v-if="P.grade?.name"> · Grade {{ P.grade.name }}</span>
                    </p>
                </div>
                <StatusBadge v-if="P.status" :status="P.status" :label="P.status_label ?? P.status" />
            </div>
        </div>

        <!-- Position attributes -->
        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70 mb-3">Position attributes</p>
            <dl class="grid grid-cols-2 lg:grid-cols-4 gap-4 text-[13px]">
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">Approved headcount</dt>
                    <dd class="text-[18px] font-black text-on-surface mt-1">{{ P.approved_headcount ?? 1 }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">Current fill</dt>
                    <dd class="text-[18px] font-black text-on-surface mt-1">{{ activeAssignments.length }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">Reports to</dt>
                    <dd class="text-on-surface-variant mt-1">{{ P.reports_to?.title ?? P.reports_to?.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">Grade steps</dt>
                    <dd class="text-on-surface-variant mt-1">
                        {{ Array.isArray(P.grade?.steps) ? P.grade.steps.length : 0 }} step(s)
                    </dd>
                </div>
            </dl>
            <div v-if="P.description" class="mt-4 pt-4 border-t border-outline-variant/40">
                <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-2">Description</p>
                <p class="text-[13px] whitespace-pre-wrap text-on-surface">{{ P.description }}</p>
            </div>
        </section>

        <!-- Active assignments -->
        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <div class="px-5 py-3 border-b border-outline-variant/40 flex items-center justify-between">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70">
                    Active assignments ({{ activeAssignments.length }})
                </p>
            </div>
            <div v-if="activeAssignments.length === 0" class="p-8">
                <EmptyState title="Position is vacant" description="No employee is currently assigned to this position." icon="badge" />
            </div>
            <table v-else class="w-full text-[13px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Employee</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Employee #</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Start date</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Type</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in activeAssignments" :key="a.id" class="border-t border-outline-variant/30">
                        <td class="px-4 py-2.5 font-semibold text-on-surface">{{ a.employee ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-mono text-on-surface-variant">{{ a.employee_no ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-on-surface-variant">{{ a.start_date ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <span v-if="a.is_acting" class="inline-flex items-center rounded-full bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 text-[10px] font-black uppercase">Acting</span>
                            <span v-else class="text-on-surface-variant text-[11px]">Substantive</span>
                        </td>
                        <td class="px-4 py-2.5 text-on-surface-variant text-[11px]">{{ a.reason ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Past assignments -->
        <section v-if="pastAssignments.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <div class="px-5 py-3 border-b border-outline-variant/40">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70">
                    Past assignments ({{ pastAssignments.length }})
                </p>
            </div>
            <table class="w-full text-[13px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Employee</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Start</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">End</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in pastAssignments" :key="a.id" class="border-t border-outline-variant/30">
                        <td class="px-4 py-2.5 text-on-surface">{{ a.employee ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-on-surface-variant text-[11px]">{{ a.start_date ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-on-surface-variant text-[11px]">{{ a.end_date }}</td>
                        <td class="px-4 py-2.5">
                            <span v-if="a.is_acting" class="text-[11px] text-amber-700">Acting</span>
                            <span v-else class="text-[11px] text-on-surface-variant">Substantive</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>

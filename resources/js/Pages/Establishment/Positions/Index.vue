<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { useToast } from '@/composables/useToast';

const toast = useToast();


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    positions:    Object,
    stats:        Object,
    filters:      Object,
    activeModule: String,
});

const localFilters = reactive({
    status:        props.filters?.status        ?? '',
    department_id: props.filters?.department_id ?? '',
    grade_id:      props.filters?.grade_id      ?? '',
});

const applyFilters = () => router.get(route('positions.index'), {
    status:        localFilters.status        || undefined,
    department_id: localFilters.department_id || undefined,
    grade_id:      localFilters.grade_id      || undefined,
}, { preserveState: true, replace: true });

// ── Inline actions ──────────────────────────────────────────────────
// Freeze a position when it's vacant but the headcount ceiling needs
// to be enforced. Vacate when an employee leaves the post (HR records
// the reason — feeds the audit trail). Both routes are POST.
// `busyPositionId` tracks the row currently in flight so its buttons can be
// disabled (prevents double-submit) and drives the shared onError toast.
const busyPositionId = ref(null);

const freezePosition = (p) => {
    const reason = window.prompt(`Freeze position ${p.code} — ${p.title}?\n\nReason (required, audit-logged):`, '');
    if (! reason || ! reason.trim()) return;
    busyPositionId.value = p.id;
    router.post(route('positions.freeze', p.id), { reason }, {
        preserveScroll: true,
        onError: (errors) => {
            toast.error(errors?.reason || Object.values(errors || {})[0] || 'Failed to freeze this position.');
        },
        onFinish: () => { busyPositionId.value = null; },
    });
};

const vacatePosition = (p) => {
    if (p.status !== 'filled' && p.status !== 'acting') return;
    const reason = window.prompt(`Vacate position ${p.code} — ${p.title}?\n\nReason (e.g. transfer, termination, resignation):`, '');
    if (! reason || ! reason.trim()) return;
    busyPositionId.value = p.id;
    router.post(route('positions.vacate', p.id), { reason }, {
        preserveScroll: true,
        onError: (errors) => {
            toast.error(errors?.reason || Object.values(errors || {})[0] || 'Failed to vacate this position.');
        },
        onFinish: () => { busyPositionId.value = null; },
    });
};
</script>

<template>
    <Head title="Positions / Establishment" />
    <div data-page-root="true" class="space-y-6 animate-reveal-up">
            <Teleport to="#page-header-mount" defer>
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">account_tree</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Establishment · Headcount ceiling</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Positions</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Approved positions, grade bands, and active assignments — the establishment register.
                    </p>
                </div>
            </Teleport>

            <div class="py-6 space-y-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <StatCard label="Total positions" :value="stats.total" />
                    <StatCard label="Vacant" :value="stats.vacant" tone="warn" />
                    <StatCard label="Filled" :value="stats.filled" tone="success" />
                    <StatCard label="Frozen" :value="stats.frozen" tone="neutral" />
                </div>

                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                    <div class="px-5 py-4 border-b border-outline-variant/40 flex flex-wrap gap-3 items-center">
                        <select aria-label="Status" v-model="localFilters.status" @change="applyFilters"
                                class="rounded-lg border-outline-variant text-sm">
                            <option value="">All statuses</option>
                            <option value="vacant">Vacant</option>
                            <option value="filled">Filled</option>
                            <option value="frozen">Frozen</option>
                            <option value="acting">Acting</option>
                        </select>
                    </div>

                    <div v-if="positions?.data?.length === 0">
                        <EmptyState title="No positions defined yet"
                                    description="Approved establishment posts will appear here." />
                    </div>

                    <table v-else class="w-full text-sm">
                        <thead class="bg-surface-container text-on-surface-variant text-xs uppercase">
                            <tr>
                                <th class="px-5 py-3 text-left">Code</th>
                                <th class="px-5 py-3 text-left">Title</th>
                                <th class="px-5 py-3 text-left">Grade</th>
                                <th class="px-5 py-3 text-left">Department</th>
                                <th class="px-5 py-3 text-left">Cost center</th>
                                <th class="px-5 py-3 text-left">Funding</th>
                                <th class="px-5 py-3 text-left">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="p in positions.data" :key="p.id" class="hover:bg-surface-container/40">
                                <td class="px-5 py-3 font-mono text-xs">{{ p.code }}</td>
                                <td class="px-5 py-3 font-medium">{{ p.title }}</td>
                                <td class="px-5 py-3">{{ p.grade?.code }}</td>
                                <td class="px-5 py-3">{{ p.department?.name ?? '—' }}</td>
                                <td class="px-5 py-3">{{ p.cost_center ?? '—' }}</td>
                                <td class="px-5 py-3">{{ p.funding_source_label }}</td>
                                <td class="px-5 py-3"><StatusBadge :status="p.status" :label="p.status_label" /></td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        <button v-if="(p.status === 'filled' || p.status === 'acting')"
                                                type="button"
                                                @click="vacatePosition(p)"
                                                :disabled="busyPositionId === p.id"
                                                class="inline-flex h-7 items-center gap-1 rounded-lg border border-amber-200 bg-amber-50 px-2 text-[11px] font-bold text-amber-700 hover:bg-amber-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                                title="Vacate this position">
                                            <span class="material-symbols-outlined text-[14px]">person_remove</span>
                                            Vacate
                                        </button>
                                        <button v-if="p.status !== 'frozen'"
                                                type="button"
                                                @click="freezePosition(p)"
                                                :disabled="busyPositionId === p.id"
                                                class="inline-flex h-7 items-center gap-1 rounded-lg border border-outline-variant bg-surface-container px-2 text-[11px] font-bold text-on-surface-variant hover:bg-surface-container/70 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                                title="Freeze (suspend hiring against this slot)">
                                            <span class="material-symbols-outlined text-[14px]">ac_unit</span>
                                            Freeze
                                        </button>
                                        <Link :href="route('positions.show', p.id)"
                                              class="inline-flex h-7 items-center gap-1 rounded-lg border border-secondary/30 bg-secondary/5 px-2 text-[11px] font-bold text-secondary hover:bg-secondary/10 transition-colors">
                                            Open
                                            <span class="material-symbols-outlined text-[13px]">arrow_forward</span>
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="px-5 py-3 border-t border-outline-variant/40">
                        <Pagination :links="positions?.meta?.links ?? []" />
                    </div>
                </div>
            </div>
    </div>
</template>

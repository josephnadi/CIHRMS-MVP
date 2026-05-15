<script setup>
import { ref, reactive } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    cases:        Object,
    stats:        Object,
    filters:      Object,
    activeModule: String,
});

const localFilters = reactive({
    status:    props.filters?.status    ?? '',
    exit_type: props.filters?.exit_type ?? '',
    q:         props.filters?.q         ?? '',
});

const applyFilters = () => router.get(route('offboarding.index'), {
    status:    localFilters.status    || undefined,
    exit_type: localFilters.exit_type || undefined,
    q:         localFilters.q         || undefined,
}, { preserveState: true, replace: true });

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pct  = (v) => Math.round((Number(v) || 0) * 100) + '%';

// ── Initiate slide-panel ─────────────────────────────────────────────────────
const showPanel = ref(false);
const form = useForm({
    employee_id:        '',
    exit_type:          'resignation',
    notice_received_on: new Date().toISOString().slice(0, 10),
    last_working_day:   '',
    reason:             '',
});

const submit = () => form.post(route('offboarding.store'), {
    preserveScroll: true,
    onSuccess: () => { showPanel.value = false; form.reset(); },
});
</script>

<template>
    <Head title="Off-boarding" />

    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-on-surface-variant/60">Phase 2 · Lifecycle close-out</p>
                    <h1 class="text-2xl font-semibold tracking-tight">Off-boarding &amp; Settlement</h1>
                </div>
                <PrimaryButton @click="showPanel = true">+ Initiate case</PrimaryButton>
            </div>
        </template>

        <div class="space-y-6 py-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="In progress"          :value="stats.in_progress" />
                <StatCard label="Awaiting settlement"  :value="stats.awaiting_settle" />
                <StatCard label="Completed this year"  :value="stats.completed_ytd" />
                <StatCard label="Settlements paid YTD" :value="cedi(stats.settlement_total)" />
            </div>

            <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex flex-wrap gap-3 items-center">
                    <input v-model="localFilters.q" @keyup.enter="applyFilters" placeholder="Reference or name…"
                           class="rounded-lg border-outline-variant text-sm flex-1 min-w-[200px]">
                    <select v-model="localFilters.status" @change="applyFilters"
                            class="rounded-lg border-outline-variant text-sm">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="in_progress">In Progress</option>
                        <option value="awaiting_settlement">Awaiting Settlement</option>
                        <option value="settled">Settled</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <select v-model="localFilters.exit_type" @change="applyFilters"
                            class="rounded-lg border-outline-variant text-sm">
                        <option value="">All exit types</option>
                        <option value="resignation">Resignation</option>
                        <option value="retirement">Retirement</option>
                        <option value="end_of_contract">End of Contract</option>
                        <option value="dismissal">Dismissal</option>
                        <option value="redundancy">Redundancy</option>
                        <option value="mutual_separation">Mutual Separation</option>
                        <option value="death">Death</option>
                        <option value="abscondment">Abscondment</option>
                    </select>
                </div>

                <div v-if="cases?.data?.length === 0">
                    <EmptyState title="No off-boarding cases yet"
                                description="Initiated cases will appear here." />
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Reference</th>
                            <th class="px-5 py-3 text-left">Employee</th>
                            <th class="px-5 py-3 text-left">Exit type</th>
                            <th class="px-5 py-3 text-left">Last working day</th>
                            <th class="px-5 py-3 text-right">Clearance</th>
                            <th class="px-5 py-3 text-right">Net settlement</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="c in cases.data" :key="c.id" class="hover:bg-surface-container-low/60">
                            <td class="px-5 py-3 font-mono text-xs">{{ c.reference }}</td>
                            <td class="px-5 py-3">
                                <div class="font-medium">{{ c.employee?.name ?? '—' }}</div>
                                <div class="text-xs text-on-surface-variant/60">{{ c.employee?.employee_no }} · {{ c.employee?.department }}</div>
                            </td>
                            <td class="px-5 py-3">{{ c.exit_type_label }}</td>
                            <td class="px-5 py-3">{{ c.last_working_day }}</td>
                            <td class="px-5 py-3 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <div class="h-1.5 w-16 bg-outline-variant/40 rounded-full overflow-hidden">
                                        <div class="h-full bg-secondary" :style="{ width: pct(c.clearance_progress) }"></div>
                                    </div>
                                    <span class="text-xs">{{ pct(c.clearance_progress) }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <span v-if="c.settlement">{{ cedi(c.settlement.net_payable) }}</span>
                                <span v-else class="text-on-surface-variant/40">—</span>
                            </td>
                            <td class="px-5 py-3"><StatusBadge :status="c.status" :label="c.status_label" /></td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('offboarding.show', c.id)" class="text-secondary hover:underline">Open</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-outline-variant/40">
                    <Pagination :links="cases?.meta?.links ?? []" />
                </div>
            </div>
        </div>

        <SlidePanel v-model="showPanel" title="Initiate off-boarding case">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Employee ID</label>
                    <input v-model="form.employee_id" type="number" class="w-full rounded-lg border-outline-variant" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Exit type</label>
                    <select v-model="form.exit_type" class="w-full rounded-lg border-outline-variant" required>
                        <option value="resignation">Resignation</option>
                        <option value="retirement">Retirement</option>
                        <option value="end_of_contract">End of Contract</option>
                        <option value="dismissal">Dismissal (with cause)</option>
                        <option value="redundancy">Redundancy (Act 651 §31)</option>
                        <option value="mutual_separation">Mutual Separation</option>
                        <option value="death">Death</option>
                        <option value="abscondment">Abscondment</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Notice received on</label>
                        <input v-model="form.notice_received_on" type="date" class="w-full rounded-lg border-outline-variant" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Last working day</label>
                        <input v-model="form.last_working_day" type="date" class="w-full rounded-lg border-outline-variant" required>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Reason / context</label>
                    <textarea v-model="form.reason" rows="3" class="w-full rounded-lg border-outline-variant"></textarea>
                </div>
                <PrimaryButton type="submit" :disabled="form.processing">Initiate case</PrimaryButton>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>

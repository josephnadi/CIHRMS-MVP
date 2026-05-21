<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { usePage } from '@inertiajs/vue3';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    ticket:       Object,
    staff:        Array,
    activeModule: String,
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('tickets.manage');
});

const newStatus    = ref(props.ticket?.data?.status ?? props.ticket?.status);
const newAssignee  = ref(props.ticket?.data?.assigned_to?.id ?? props.ticket?.assigned_to?.id ?? '');
const showDelete   = ref(false);

const t = computed(() => props.ticket?.data ?? props.ticket);

const submitUpdate = () => {
    router.patch(route('tickets.update', t.value.id), {
        status:      newStatus.value,
        assigned_to: newAssignee.value || null,
    }, { preserveScroll: true });
};

const doDelete = () => {
    router.delete(route('tickets.destroy', t.value.id), {
        onSuccess: () => router.get(route('tickets.index')),
    });
};

const priorityClasses = {
    critical: 'bg-red-500/15 text-red-600',
    high:     'bg-orange-500/15 text-orange-600',
    medium:   'bg-amber-500/15 text-amber-700',
    low:      'bg-slate-400/15 text-slate-600',
};

const priorityIcon = {
    critical: 'priority_high',
    high:     'keyboard_double_arrow_up',
    medium:   'horizontal_rule',
    low:      'keyboard_double_arrow_down',
};

const formatDateTime = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const daysSince = (d) => {
    if (!d) return '';
    const diff = Math.floor((Date.now() - new Date(d).getTime()) / 86400000);
    if (diff === 0) return 'opened today';
    if (diff === 1) return 'opened yesterday';
    return `opened ${diff} days ago`;
};
</script>

<template>
    <Head :title="`Ticket #${t.id}`" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70">
                            <Link :href="route('tickets.index')" class="hover:text-secondary">Tickets</Link>
                            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                            <span>#{{ t.id }}</span>
                        </div>
                        <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">{{ t.title }}</h2>
                        <p class="mt-1 text-[12px] text-on-surface-variant">{{ daysSince(t.created_at) }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            :href="route('tickets.index')"
                            class="flex items-center gap-2 rounded-xl border border-outline-variant/80 px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-secondary/10 hover:text-secondary hover:border-secondary/30 transition-all"
                        >
                            <span class="material-symbols-outlined text-[17px]">arrow_back</span>
                            Back
                        </Link>
                        <button
                            v-if="canManage"
                            @click="showDelete = true"
                            class="flex items-center gap-2 rounded-xl border border-red-200/60 dark:border-red-900/40 px-4 py-2 text-[13px] font-semibold text-red-600 hover:bg-red-500/10 hover:border-red-400/60 transition-all"
                        >
                            <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">delete</span>
                            Delete
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="grid gap-6 lg:grid-cols-3">

                <!-- Main column -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Description — single 5% gold hairline accent on top edge -->
                    <div class="relative rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 overflow-hidden">
                        <div class="pointer-events-none absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.45),transparent)"></div>
                        <h3 class="flex items-center gap-2 text-[12px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70 mb-3">
                            <span class="flex h-6 w-6 items-center justify-center rounded-md bg-secondary/10">
                                <span class="material-symbols-outlined text-[15px] text-secondary" style="font-variation-settings:'FILL' 1">description</span>
                            </span>
                            Description
                        </h3>
                        <p class="text-[14px] text-on-surface whitespace-pre-line leading-relaxed">{{ t.description }}</p>
                    </div>

                    <!-- Status update (manager only) -->
                    <div v-if="canManage" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <h3 class="flex items-center gap-2 text-[12px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70 mb-4">
                            <span class="flex h-6 w-6 items-center justify-center rounded-md bg-secondary/10">
                                <span class="material-symbols-outlined text-[15px] text-secondary" style="font-variation-settings:'FILL' 1">tune</span>
                            </span>
                            Update Ticket
                        </h3>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Status</label>
                                <select
                                    v-model="newStatus"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                >
                                    <option value="open">Open</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="resolved">Resolved</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Assigned To</label>
                                <select
                                    v-model="newAssignee"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                >
                                    <option value="">Unassigned</option>
                                    <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-5 flex justify-end">
                            <button
                                @click="submitUpdate"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                            >
                                <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">save</span>
                                Save Changes
                            </button>
                        </div>
                    </div>

                    <!-- Timeline — vertical rail with connecting line -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <h3 class="flex items-center gap-2 text-[12px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70 mb-4">
                            <span class="flex h-6 w-6 items-center justify-center rounded-md bg-secondary/10">
                                <span class="material-symbols-outlined text-[15px] text-secondary" style="font-variation-settings:'FILL' 1">timeline</span>
                            </span>
                            Activity
                        </h3>
                        <ol class="relative space-y-4 border-l-2 border-outline-variant/40 pl-5 ml-3">
                            <li class="relative">
                                <span class="absolute -left-[27px] flex h-5 w-5 items-center justify-center rounded-full text-white shadow-glow-sm"
                                      style="background:#1a237e">
                                    <span class="material-symbols-outlined text-[12px]" style="font-variation-settings:'FILL' 1">add_circle</span>
                                </span>
                                <p class="text-[13px] font-bold text-on-surface leading-tight">Ticket opened</p>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant tabular-nums">{{ formatDateTime(t.created_at) }}</p>
                            </li>
                            <li v-if="t.assigned_to" class="relative">
                                <span class="absolute -left-[27px] flex h-5 w-5 items-center justify-center rounded-full text-white shadow-glow-sm"
                                      style="background:#1a237e">
                                    <span class="material-symbols-outlined text-[12px]" style="font-variation-settings:'FILL' 1">person_add</span>
                                </span>
                                <p class="text-[13px] font-bold text-on-surface leading-tight">Assigned to {{ t.assigned_to.name }}</p>
                            </li>
                            <li v-if="t.resolved_at" class="relative">
                                <span class="absolute -left-[27px] flex h-5 w-5 items-center justify-center rounded-full text-white"
                                      style="background:#10b981;box-shadow:0 0 12px rgba(16,185,129,0.4)">
                                    <span class="material-symbols-outlined text-[12px]" style="font-variation-settings:'FILL' 1">check_circle</span>
                                </span>
                                <p class="text-[13px] font-bold text-on-surface leading-tight">Resolved</p>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant tabular-nums">{{ formatDateTime(t.resolved_at) }}</p>
                            </li>
                        </ol>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 divide-y divide-outline-variant/30">
                        <div class="pb-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1.5">Status</p>
                            <StatusBadge :status="t.status" type="ticket" />
                        </div>

                        <div class="py-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1.5">Priority</p>
                            <span
                                :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-[0.08em]', priorityClasses[t.priority]]"
                            >
                                <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">{{ priorityIcon[t.priority] }}</span>
                                {{ t.priority_label }}
                            </span>
                        </div>

                        <div class="py-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1.5">Requester</p>
                            <p class="text-[13px] font-bold text-on-surface">{{ t.employee?.name ?? '—' }}</p>
                            <p class="mt-0.5 text-[11px] text-on-surface-variant font-mono">{{ t.employee?.employee_no ?? '' }}</p>
                        </div>

                        <div class="py-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1.5">Assigned To</p>
                            <p class="text-[13px] font-bold text-on-surface flex items-center gap-1.5">
                                <span v-if="t.assigned_to" class="flex h-5 w-5 items-center justify-center rounded-full" style="background:rgba(26, 35, 126,0.10)">
                                    <span class="material-symbols-outlined text-[12px]" style="color:#1a237e">person</span>
                                </span>
                                {{ t.assigned_to?.name ?? 'Unassigned' }}
                            </p>
                        </div>

                        <div class="py-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1.5">Due Date</p>
                            <p :class="['text-[13px] font-bold flex items-center gap-1 tabular-nums', t.is_overdue ? 'text-red-600' : 'text-on-surface']">
                                <span v-if="t.is_overdue" class="material-symbols-outlined text-[16px] text-red-500" style="font-variation-settings:'FILL' 1">schedule</span>
                                {{ formatDateTime(t.due_at) }}
                                <span v-if="t.is_overdue" class="ml-auto rounded-full bg-red-500/12 px-1.5 py-0 text-[9px] font-black uppercase tracking-[0.12em] text-red-600">Overdue</span>
                            </p>
                        </div>

                        <div class="pt-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1.5">Created</p>
                            <p class="text-[13px] text-on-surface tabular-nums">{{ formatDateTime(t.created_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                :open="showDelete"
                title="Delete Ticket"
                message="Are you sure you want to delete this ticket? This action cannot be undone."
                :danger="true"
                @confirm="doDelete"
                @cancel="showDelete = false"
            />

    </div>
</template>

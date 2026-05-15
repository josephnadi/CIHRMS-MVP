<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { usePage } from '@inertiajs/vue3';

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
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
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
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                        Back
                    </Link>
                    <button
                        v-if="canManage"
                        @click="showDelete = true"
                        class="rounded-xl border border-red-200 dark:border-red-900/40 px-4 py-2 text-[13px] font-semibold text-red-600 hover:bg-red-500/10 transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[18px]">delete</span>
                        Delete
                    </button>
                </div>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">

            <!-- Main column -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Description -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                    <h3 class="text-[12px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-3">Description</h3>
                    <p class="text-[14px] text-on-surface whitespace-pre-line leading-relaxed">{{ t.description }}</p>
                </div>

                <!-- Status update (manager only) -->
                <div v-if="canManage" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                    <h3 class="text-[12px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-4">Update Ticket</h3>
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
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                        >
                            <span class="material-symbols-outlined text-[16px]">save</span>
                            Save Changes
                        </button>
                    </div>
                </div>

                <!-- Timeline -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                    <h3 class="text-[12px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-4">Timeline</h3>
                    <div class="space-y-4">
                        <div class="flex gap-3">
                            <div class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-blue-500/15 text-blue-600">
                                <span class="material-symbols-outlined text-[16px]">add_circle</span>
                            </div>
                            <div>
                                <p class="text-[13px] font-semibold text-on-surface">Ticket opened</p>
                                <p class="text-[11px] text-on-surface-variant">{{ formatDateTime(t.created_at) }}</p>
                            </div>
                        </div>
                        <div v-if="t.assigned_to" class="flex gap-3">
                            <div class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-violet-500/15 text-violet-600">
                                <span class="material-symbols-outlined text-[16px]">person_add</span>
                            </div>
                            <div>
                                <p class="text-[13px] font-semibold text-on-surface">Assigned to {{ t.assigned_to.name }}</p>
                            </div>
                        </div>
                        <div v-if="t.resolved_at" class="flex gap-3">
                            <div class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-green-500/15 text-green-600">
                                <span class="material-symbols-outlined text-[16px]">check_circle</span>
                            </div>
                            <div>
                                <p class="text-[13px] font-semibold text-on-surface">Resolved</p>
                                <p class="text-[11px] text-on-surface-variant">{{ formatDateTime(t.resolved_at) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 space-y-4">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Status</p>
                        <StatusBadge :status="t.status" type="ticket" />
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Priority</p>
                        <span
                            :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider', priorityClasses[t.priority]]"
                        >
                            <span class="material-symbols-outlined text-[14px]">{{ priorityIcon[t.priority] }}</span>
                            {{ t.priority_label }}
                        </span>
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Requester</p>
                        <p class="text-[13px] font-semibold text-on-surface">{{ t.employee?.name ?? '—' }}</p>
                        <p class="text-[11px] text-on-surface-variant font-mono">{{ t.employee?.employee_no ?? '' }}</p>
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Assigned To</p>
                        <p class="text-[13px] font-semibold text-on-surface">{{ t.assigned_to?.name ?? 'Unassigned' }}</p>
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Due Date</p>
                        <p :class="['text-[13px] font-semibold flex items-center gap-1', t.is_overdue ? 'text-red-600' : 'text-on-surface']">
                            <span v-if="t.is_overdue" class="material-symbols-outlined text-[16px] text-red-500">schedule</span>
                            {{ formatDateTime(t.due_at) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Created</p>
                        <p class="text-[13px] text-on-surface">{{ formatDateTime(t.created_at) }}</p>
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

    </AuthenticatedLayout>
</template>

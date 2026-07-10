<script setup>
import { ref, computed, reactive } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import SlidePanel from '@/Components/SlidePanel.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    complaints:    Object,
    investigators: { type: Array, default: () => [] },
    filters:       Object,
    activeModule:  String,
});
const investigators = props.investigators;

const page = usePage();
const canManage = computed(() => {
    const perms = page.props.auth?.permissions ?? [];
    return perms.includes('*') || perms.includes('complaints.manage');
});

// ── Tabs ─────────────────────────────────────────────────────────────────────
const tab = ref(canManage.value ? 'queue' : 'submit');

// ── Submit form ──────────────────────────────────────────────────────────────
const form = useForm({
    submitted_by: '',
    details:      '',
});

const submittedRef = ref(null);

const submit = () => {
    form.post(route('complaints.store'), {
        onSuccess: (page) => {
            const flash = page.props.flash?.success;
            if (flash) {
                const match = flash.match(/CMP-[A-Z0-9]+/);
                submittedRef.value = match ? match[0] : true;
            } else {
                submittedRef.value = true;
            }
            form.reset();
        },
    });
};

// ── Track form ───────────────────────────────────────────────────────────────
const trackRef = ref('');
const doTrack = () => {
    if (!trackRef.value.trim()) return;
    router.get(route('complaints.track'), { reference: trackRef.value.trim() });
};

// ── Status filter ────────────────────────────────────────────────────────────
const statusFilter = ref(props.filters?.status ?? '');

const applyFilter = () => {
    router.get(route('complaints.index'), {
        status: statusFilter.value || undefined,
    }, { preserveState: true, replace: true });
};

// ── Inline status update ─────────────────────────────────────────────────────
const updateStatus = (complaint, newStatus) => {
    router.patch(route('complaints.updateStatus', complaint.id), { status: newStatus }, {
        preserveScroll: true,
    });
};

// Reassign / un-assign the investigator. PATCH only the `assigned_to` field.
const reassign = (complaint, userId) => {
    router.patch(route('complaints.updateStatus', complaint.id),
        { assigned_to: userId },
        { preserveScroll: true },
    );
};

// ── Detail panel ─────────────────────────────────────────────────────────────
const selected = ref(null);

const openDetail = (complaint) => {
    selected.value = complaint;
};

// ── Stats ────────────────────────────────────────────────────────────────────
const stats = computed(() => {
    const data = props.complaints?.data ?? [];
    return {
        total:       props.complaints?.meta?.total ?? data.length,
        open:        data.filter(c => c.status === 'open').length,
        underReview: data.filter(c => c.status === 'under_review').length,
        resolved:    data.filter(c => c.status === 'resolved').length,
        withdrawn:   data.filter(c => c.status === 'withdrawn' || c.status === 'closed').length,
    };
});

// ── Editorial-Sovereign masthead label ───────────────────────────
const editionLabel = computed(() => {
    const d   = new Date();
    const day = Math.floor((d - new Date(d.getFullYear(), 0, 0)) / 86_400_000);
    const vol = d.getFullYear() - 2023;
    const roman = (n) => {
        const map = [['M',1000],['CM',900],['D',500],['CD',400],['C',100],['XC',90],['L',50],['XL',40],['X',10],['IX',9],['V',5],['IV',4],['I',1]];
        let s = '';
        for (const [r, v] of map) while (n >= v) { s += r; n -= v; }
        return s;
    };
    return {
        date:    d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
    };
});

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};
</script>

<template>
    <Head title="Governance" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">gavel</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">COMPLAINTS GAZETTE · ACT 715</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Governance — Complaints</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Grievances filed under Whistleblower Act 715 · referenced, confidentially routed, on the resolution register.
                        </p>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-6">

                <!-- Tabs -->
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="canManage"
                        @click="tab = 'queue'"
                        :class="[
                            'rounded-xl px-4 py-2 text-[12px] font-semibold transition-all flex items-center gap-1.5',
                            tab === 'queue'
                                ? 'bg-secondary text-white shadow-glow-sm'
                                : 'border border-outline-variant text-on-surface-variant hover:bg-surface-container',
                        ]"
                    >
                        <span class="material-symbols-outlined text-[16px]">inbox</span>
                        Management Queue
                    </button>
                    <button
                        @click="tab = 'submit'"
                        :class="[
                            'rounded-xl px-4 py-2 text-[12px] font-semibold transition-all flex items-center gap-1.5',
                            tab === 'submit'
                                ? 'bg-secondary text-white shadow-glow-sm'
                                : 'border border-outline-variant text-on-surface-variant hover:bg-surface-container',
                        ]"
                    >
                        <span class="material-symbols-outlined text-[16px]">edit_note</span>
                        Submit Complaint
                    </button>
                    <button
                        @click="tab = 'track'"
                        :class="[
                            'rounded-xl px-4 py-2 text-[12px] font-semibold transition-all flex items-center gap-1.5',
                            tab === 'track'
                                ? 'bg-secondary text-white shadow-glow-sm'
                                : 'border border-outline-variant text-on-surface-variant hover:bg-surface-container',
                        ]"
                    >
                        <span class="material-symbols-outlined text-[16px]">search</span>
                        Track Status
                    </button>
                </div>

                <!-- ── Submit tab ────────────────────────────────────────────────── -->
                <div v-if="tab === 'submit'" class="max-w-2xl">
                    <div v-if="submittedRef" class="rounded-2xl bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-900/40 p-6">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-[28px] text-green-600">check_circle</span>
                            <div>
                                <h3 class="text-[15px] font-bold text-green-800 dark:text-green-200">Complaint Submitted Successfully</h3>
                                <p v-if="typeof submittedRef === 'string'" class="mt-2 text-[12px] text-green-700 dark:text-green-300">
                                    Your reference number is
                                    <span class="ml-1 font-mono font-bold text-[14px] tracking-wider">{{ submittedRef }}</span>
                                </p>
                                <p class="mt-3 text-[12px] text-green-700 dark:text-green-300">
                                    Save this reference to track the status of your complaint. Your submission will remain confidential.
                                </p>
                                <button
                                    @click="submittedRef = null"
                                    class="mt-4 rounded-xl border border-green-300 dark:border-green-800 px-3 py-1.5 text-[12px] font-semibold text-green-700 dark:text-green-300 hover:bg-green-100 dark:hover:bg-green-900/40"
                                >
                                    Submit Another
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-else class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <h3 class="text-[14px] font-bold text-on-surface mb-1">File a Complaint</h3>
                        <p class="text-[12px] text-on-surface-variant/70 mb-5">
                            All complaints are reviewed confidentially. You may submit anonymously or include your name for follow-up.
                        </p>

                        <form @submit.prevent="submit" class="space-y-4">
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                                    Your Name <span class="text-on-surface-variant/60 font-normal">(optional)</span>
                                </label>
                                <input aria-label="Your Name (optional)"
                                    v-model="form.submitted_by"
                                    type="text"
                                    placeholder="Leave blank to remain anonymous"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                />
                            </div>

                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                                    Complaint Details <span class="text-red-500">*</span>
                                </label>
                                <textarea aria-label="Complaint Details"
                                    v-model="form.details"
                                    rows="8"
                                    placeholder="Provide a clear and detailed account of your complaint. Include dates, locations, persons involved, and any relevant context…"
                                    required
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                                    :class="{ 'border-red-400': form.errors.details }"
                                ></textarea>
                                <p v-if="form.errors.details" class="mt-1 text-[11px] text-red-500">{{ form.errors.details }}</p>
                                <p class="mt-1 text-[10px] text-on-surface-variant/50">Maximum 5,000 characters.</p>
                            </div>

                            <div class="flex justify-end pt-2">
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white disabled:opacity-60"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                                >
                                    <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                                    <span>Submit Complaint</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ── Track tab ─────────────────────────────────────────────────── -->
                <div v-if="tab === 'track'" class="max-w-xl">
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                        <h3 class="text-[14px] font-bold text-on-surface mb-1">Track Your Complaint</h3>
                        <p class="text-[12px] text-on-surface-variant/70 mb-5">
                            Enter the reference number you received when submitting your complaint.
                        </p>

                        <div class="flex gap-2">
                            <input aria-label="Complaint reference"
                                v-model="trackRef"
                                type="text"
                                placeholder="CMP-XXXXXXXX"
                                class="flex-1 rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] font-mono text-on-surface uppercase placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                @keyup.enter="doTrack"
                            />
                            <button
                                @click="doTrack"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                            >
                                <span class="material-symbols-outlined text-[16px]">search</span>
                                Track
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Management queue ──────────────────────────────────────────── -->
                <div v-if="tab === 'queue' && canManage" class="space-y-6">

                    <!-- Stats — Total Complaints gets gold (institutional governance oversight) -->
                    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatCard :value="stats.total" label="Total Complaints" icon="report" color="gold" />
                        <StatCard :value="stats.open" label="Open" icon="inbox" color="red" />
                        <StatCard :value="stats.underReview" label="Under Review" icon="search" color="amber" />
                        <StatCard :value="stats.resolved" label="Resolved" icon="check_circle" color="green" />
                    </div>

                    <!-- Status filter -->
                    <div class="flex items-center gap-3">
                        <select aria-label="Filter by status"
                            v-model="statusFilter"
                            @change="applyFilter"
                            class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option value="">All Statuses</option>
                            <option value="open">Open</option>
                            <option value="under_review">Under Review</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>

                    <!-- Table -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                        <div v-if="complaints?.data?.length === 0" class="p-12">
                            <EmptyState
                                title="No complaints found"
                                description="There are no complaints matching your filter."
                                icon="inbox"
                            />
                        </div>

                        <div v-else class="max-h-[calc(100vh-460px)] min-h-[280px] overflow-auto">
                            <table class="w-full text-left">
                                <thead class="sticky top-0 z-10">
                                    <tr>
                                        <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Reference</th>
                                        <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Submitted By</th>
                                        <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Details</th>
                                        <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Submitted</th>
                                        <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Status</th>
                                        <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Assigned to</th>
                                        <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Update</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-outline-variant/30">
                                    <tr
                                        v-for="c in complaints.data"
                                        :key="c.id"
                                        class="group cursor-pointer transition-colors hover:bg-secondary/[0.04]"
                                        @click="openDetail(c)"
                                    >
                                        <td class="px-4 py-3.5">
                                            <span class="font-mono text-[12px] font-bold text-secondary">{{ c.reference }}</span>
                                        </td>
                                        <td class="px-4 py-3.5 text-[13px] text-on-surface-variant">
                                            <span v-if="c.submitted_by && c.submitted_by !== 'anonymous'">{{ c.submitted_by }}</span>
                                            <span v-else class="italic text-on-surface-variant/60">Anonymous</span>
                                        </td>
                                        <td class="px-4 py-3.5 text-[13px] text-on-surface max-w-md">
                                            <p class="line-clamp-2">{{ c.details }}</p>
                                        </td>
                                        <td class="px-4 py-3.5 text-[12px] text-on-surface-variant">
                                            {{ formatDate(c.created_at) }}
                                        </td>
                                        <td class="px-4 py-3.5">
                                            <StatusBadge :status="c.status" type="complaint" />
                                        </td>
                                        <td class="px-4 py-3.5" @click.stop>
                                            <select :value="c.assigned_to ?? ''"
                                                @change="ev => reassign(c, ev.target.value ? Number(ev.target.value) : null)"
                                                aria-label="Assign investigator"
                                                class="rounded-lg border border-outline-variant/60 bg-surface-container-low px-2 py-1 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50 max-w-[160px]"
                                            >
                                                <option value="">Unassigned</option>
                                                <option v-for="inv in investigators ?? []" :key="inv.id" :value="inv.id">{{ inv.name }}</option>
                                            </select>
                                        </td>
                                        <td class="px-4 py-3.5" @click.stop>
                                            <select :value="c.status"
                                                @change="ev => updateStatus(c, ev.target.value)"
                                                aria-label="Update status"
                                                class="rounded-lg border border-outline-variant/60 bg-surface-container-low px-2 py-1 text-[12px] text-on-surface focus:outline-none focus:border-secondary/50"
                                            >
                                                <option value="open">Open</option>
                                                <option value="under_review">Under Review</option>
                                                <option value="resolved">Resolved</option>
                                                <option value="closed">Closed</option>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="complaints?.links?.length > 3" class="border-t border-outline-variant/50 bg-surface-container-low/40 px-4 py-3">
                            <div class="flex items-center justify-between">
                                <p class="flex items-center gap-1.5 text-[12px] text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[15px]" style="color:#1a237e;opacity:0.7">format_list_numbered</span>
                                    Showing
                                    <span class="font-bold text-on-surface tabular-nums">{{ complaints.meta?.from }}</span>
                                    –
                                    <span class="font-bold text-on-surface tabular-nums">{{ complaints.meta?.to }}</span>
                                    of
                                    <span class="font-bold text-on-surface tabular-nums">{{ complaints.meta?.total }}</span>
                                </p>
                                <Pagination :links="complaints.links" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detail panel -->
            <SlidePanel :open="!!selected" :title="selected?.reference ?? 'Complaint Detail'" size="md" @close="selected = null">
                <div v-if="selected" class="p-6 space-y-5">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Reference</p>
                        <p class="font-mono text-[14px] font-bold text-secondary">{{ selected.reference }}</p>
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Submitted By</p>
                        <p v-if="selected.submitted_by && selected.submitted_by !== 'anonymous'" class="text-[13px] text-on-surface">{{ selected.submitted_by }}</p>
                        <p v-else class="text-[13px] italic text-on-surface-variant">Anonymous</p>
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Status</p>
                        <StatusBadge :status="selected.status" type="complaint" />
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Submitted</p>
                        <p class="text-[13px] text-on-surface">{{ formatDate(selected.created_at) }}</p>
                    </div>

                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Details</p>
                        <p class="text-[13px] text-on-surface whitespace-pre-line leading-relaxed">{{ selected.details }}</p>
                    </div>
                </div>
            </SlidePanel>

    </div>
</template>

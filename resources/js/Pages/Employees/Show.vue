<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabBar from '@/Components/TabBar.vue';
import FileUpload from '@/Components/FileUpload.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';

const props = defineProps({
    employee: Object,
    activeModule: String,
});

// â”€â”€ Tabs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const activeTab = ref('overview');
const tabs = [
    { label: 'Overview',      value: 'overview'   },
    { label: 'Documents',     value: 'documents'  },
    { label: 'Leave History', value: 'leave'      },
    { label: 'Tickets',       value: 'tickets'    },
    { label: 'Payroll',       value: 'payroll'    },
];

// â”€â”€ Edit panel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showEditPanel = ref(false);

const editForm = useForm({
    department_id: props.employee?.department?.id  ?? '',
    employee_no:   props.employee?.employee_no     ?? '',
    position:      props.employee?.position        ?? '',
    hire_date:     props.employee?.hire_date       ?? '',
    phone:         props.employee?.phone           ?? '',
    status:        props.employee?.status?.value   ?? props.employee?.status ?? 'active',
});

const submitEdit = () => {
    editForm.patch(route('employees.update', props.employee.id), {
        onSuccess: () => { showEditPanel.value = false; },
    });
};

// â”€â”€ Document upload â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const docFile    = ref(null);
const docForm    = useForm({ title: '', file: null });
const submitDoc  = () => {
    docForm.file = docFile.value;
    docForm.post(route('employees.documents.store', props.employee.id), {
        onSuccess: () => { docForm.reset(); docFile.value = null; },
    });
};

// â”€â”€ Delete document dialog â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showDeleteDocDialog = ref(false);
const selectedDocId       = ref(null);
const confirmDeleteDoc = (id) => { selectedDocId.value = id; showDeleteDocDialog.value = true; };
const doDeleteDoc = () => {
    router.delete(route('employees.documents.destroy', [props.employee.id, selectedDocId.value]), {
        onFinish: () => { showDeleteDocDialog.value = false; selectedDocId.value = null; },
    });
};

// â”€â”€ Computed helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Avatar gradients — disciplined cool family matching Index.vue. People module
// = navy/cobalt dominant + a single magenta variant. No warm reds/ambers.
const gradients = [
    'linear-gradient(135deg,#0a2647,#205295)',          // navy → cobalt
    'linear-gradient(135deg,#205295,#7cb6e8)',          // cobalt → soft sky
    'linear-gradient(135deg,#06192f,#0a2647)',          // deep navy → navy
    'linear-gradient(135deg,#0a2647,#205295,#d912e3)',  // navy → cobalt → magenta (people spark)
];

const avatarGradient = computed(() => gradients[props.employee.id % gradients.length]);

const initials = (name) => {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    return parts.length >= 2
        ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
        : name.slice(0, 2).toUpperCase();
};

const yearsOfService = computed(() => {
    if (!props.employee?.hire_date) return 'â€”';
    const hire = new Date(props.employee.hire_date);
    const now  = new Date();
    const yrs  = now.getFullYear() - hire.getFullYear();
    const mos  = now.getMonth() - hire.getMonth();
    const adj  = mos < 0 ? yrs - 1 : yrs;
    const remM = ((mos + 12) % 12);
    if (adj === 0) return `${remM}mo`;
    if (remM === 0) return `${adj}yr`;
    return `${adj}yr ${remM}mo`;
});

const probationEnd = computed(() => {
    if (!props.employee?.hire_date) return 'â€”';
    const d = new Date(props.employee.hire_date);
    d.setDate(d.getDate() + 90);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
});

const formatDate = (d) => {
    if (!d) return 'â€”';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const formatCurrency = (n) => {
    if (n == null) return 'â€”';
    return 'GHS ' + Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2 });
};

const copyToClipboard = (text) => {
    if (!text) return;
    navigator.clipboard.writeText(text).catch(() => {});
};

// Leave balance mock â€” replace with real prop when available
const leaveBalances = computed(() => [
    { type: 'Annual',     used: props.employee?.leave_used_annual  ?? 0,  total: 21 },
    { type: 'Sick',       used: props.employee?.leave_used_sick    ?? 0,  total: 14 },
    { type: 'Maternity',  used: props.employee?.leave_used_mat     ?? 0,  total: 84 },
    { type: 'Compassion', used: props.employee?.leave_used_comp    ?? 0,  total: 3  },
]);

const docIcon = (title) => {
    const t = (title ?? '').toLowerCase();
    if (t.endsWith('.pdf'))               return 'picture_as_pdf';
    if (t.endsWith('.doc') || t.endsWith('.docx')) return 'article';
    if (t.endsWith('.xls') || t.endsWith('.xlsx')) return 'table_chart';
    if (/\.(png|jpg|jpeg|gif|webp)$/.test(t)) return 'image';
    return 'attach_file';
};

const priorityColors = {
    low:      'bg-slate-100 dark:bg-slate-800/60 text-slate-600 dark:text-slate-400',
    medium:   'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',
    high:     'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400',
    critical: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
};
</script>

<template>
    <Head :title="`${employee.user?.name ?? 'Employee'} â€” Profile`" />
    <AuthenticatedLayout :activeModule="activeModule">

        <!-- â”€â”€ Header slot â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('employees.index')"
                        class="flex h-9 w-9 items-center justify-center rounded-xl border border-outline-variant/80 text-on-surface-variant hover:bg-secondary/10 hover:text-secondary hover:border-secondary/30 transition-all"
                        title="Back to employees"
                    >
                        <span class="material-symbols-outlined text-[20px]">arrow_back</span>
                    </Link>
                    <div>
                        <h2 class="text-[1.5rem] font-black tracking-tight text-on-surface leading-tight">Employee Profile</h2>
                        <p class="mt-0.5 flex items-center gap-1.5 text-[13px] text-on-surface-variant">
                            <span class="font-semibold">{{ employee.user?.name }}</span>
                            <span class="text-on-surface-variant/40">·</span>
                            <span class="font-mono text-[12px] text-on-surface-variant/80">{{ employee.employee_no }}</span>
                        </p>
                    </div>
                </div>
                <button
                    @click="showEditPanel = true"
                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                    style="background:linear-gradient(135deg,#0a2647,#205295)"
                >
                    <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1">edit</span>
                    Edit Profile
                </button>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Profile hero card — single subtle 5% gold accent on the top edge -->
            <div class="relative rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card overflow-hidden">
                <!-- Top edge gold hairline — the page's single 5% institutional accent -->
                <div class="pointer-events-none absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.55),transparent)"></div>
                <!-- Cobalt radial behind the avatar for atmosphere -->
                <div class="pointer-events-none absolute -top-12 -left-8 h-40 w-40 rounded-full" style="background:radial-gradient(circle,rgba(32,82,149,0.12),transparent 70%)"></div>

                <div class="relative flex flex-wrap items-start gap-6">

                    <!-- Avatar with ring -->
                    <div class="relative flex-shrink-0">
                        <div
                            class="h-16 w-16 rounded-2xl flex items-center justify-center text-[22px] font-black text-white shadow-lifted ring-4 ring-white dark:ring-surface-container-lowest overflow-hidden"
                            :style="employee.avatar_url ? '' : `background:${avatarGradient}`"
                        >
                            <img v-if="employee.avatar_url" :src="employee.avatar_url" class="h-full w-full object-cover" />
                            <span v-else>{{ initials(employee.user?.name) }}</span>
                        </div>
                        <!-- Status dot -->
                        <span class="absolute -bottom-1 -right-1 h-4 w-4 rounded-full ring-2 ring-white dark:ring-surface-container-lowest"
                              :style="(employee.status?.value ?? employee.status) === 'active' ? 'background:#10b981' : 'background:#9ca3af'"></span>
                    </div>

                    <!-- Identity -->
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-3 mb-1">
                            <h3 class="text-[22px] font-black text-on-surface leading-tight">{{ employee.user?.name ?? '—' }}</h3>
                            <StatusBadge :status="employee.status?.value ?? employee.status" type="employee" />
                        </div>
                        <p class="text-[14px] text-on-surface-variant mb-3">{{ employee.position ?? 'No position set' }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-secondary/10 px-3 py-1 text-[12px] font-semibold text-secondary">
                                <span class="material-symbols-outlined text-[14px]">corporate_fare</span>
                                {{ employee.department?.name ?? 'No department' }}
                            </span>
                            <span v-if="employee.user?.role" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] font-semibold border" style="background:rgba(217,18,227,0.06);border-color:rgba(217,18,227,0.18);color:#a30db0">
                                <span class="material-symbols-outlined text-[14px]">manage_accounts</span>
                                {{ employee.user.role }}
                            </span>
                        </div>
                    </div>

                    <!-- Quick stats -->
                    <div class="flex items-center gap-6 flex-shrink-0">
                        <div class="text-center">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1">Staff ID</p>
                            <p class="font-mono text-[15px] font-bold text-on-surface tabular-nums">{{ employee.employee_no }}</p>
                        </div>
                        <div class="h-10 w-px bg-outline-variant/50"></div>
                        <div class="text-center">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1">Hire Date</p>
                            <p class="text-[14px] font-bold text-on-surface tabular-nums">{{ formatDate(employee.hire_date) }}</p>
                        </div>
                        <div class="h-10 w-px bg-outline-variant/50"></div>
                        <div class="text-center">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60 mb-1">Service</p>
                            <p class="text-[15px] font-bold text-on-surface tabular-nums">{{ yearsOfService }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact info cards — palette-disciplined icon wells.
                 Phone=cobalt (action), Email=cyan (comms), Role=magenta (people). -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="group rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-4 shadow-card flex items-center gap-3 card-lift">
                    <div class="h-10 w-10 rounded-xl flex items-center justify-center flex-shrink-0 transition-transform group-hover:scale-105" style="background:rgba(32,82,149,0.10);color:#205295">
                        <span class="material-symbols-outlined text-[20px]" style="font-variation-settings:'FILL' 1">phone</span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60">Phone</p>
                        <p class="text-[13px] font-bold text-on-surface tabular-nums">{{ employee.phone ?? 'Not set' }}</p>
                    </div>
                </div>
                <div class="group rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-4 shadow-card flex items-center gap-3 card-lift">
                    <div class="h-10 w-10 rounded-xl flex items-center justify-center flex-shrink-0 transition-transform group-hover:scale-105" style="background:rgba(18,217,227,0.12);color:#0e8a93">
                        <span class="material-symbols-outlined text-[20px]" style="font-variation-settings:'FILL' 1">mail</span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60">Email</p>
                        <p class="text-[13px] font-bold text-on-surface truncate">{{ employee.user?.email ?? '—' }}</p>
                    </div>
                </div>
                <div class="group rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-4 shadow-card flex items-center gap-3 card-lift">
                    <div class="h-10 w-10 rounded-xl flex items-center justify-center flex-shrink-0 transition-transform group-hover:scale-105" style="background:rgba(217,18,227,0.10);color:#d912e3">
                        <span class="material-symbols-outlined text-[20px]" style="font-variation-settings:'FILL' 1">manage_accounts</span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant/60">Role</p>
                        <p class="text-[13px] font-bold text-on-surface capitalize">{{ employee.user?.role?.replace('_', ' ') ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <!-- â”€â”€ Tab bar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <TabBar :tabs="tabs" v-model="activeTab" />

            <!-- â”€â”€ OVERVIEW TAB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-show="activeTab === 'overview'" class="grid grid-cols-1 gap-6 lg:grid-cols-2">

                <!-- Employment details -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card">
                    <h4 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-md bg-secondary/10">
                            <span class="material-symbols-outlined text-[15px] text-secondary" style="font-variation-settings:'FILL' 1">work</span>
                        </span>
                        Employment Details
                    </h4>
                    <dl class="space-y-3">
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Position</dt>
                            <dd class="font-semibold text-on-surface">{{ employee.position ?? 'â€”' }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Department</dt>
                            <dd class="font-semibold text-on-surface">{{ employee.department?.name ?? 'â€”' }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Employment Type</dt>
                            <dd class="font-semibold text-on-surface">Permanent</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Hire Date</dt>
                            <dd class="font-semibold text-on-surface">{{ formatDate(employee.hire_date) }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Probation End</dt>
                            <dd class="font-semibold text-on-surface">{{ probationEnd }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Employee No.</dt>
                            <dd class="font-mono font-bold text-on-surface">{{ employee.employee_no }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Personal details + Ghana compliance stacked -->
                <div class="space-y-6">
                    <!-- Personal details -->
                    <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card">
                        <h4 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-md bg-secondary/10">
                                <span class="material-symbols-outlined text-[15px] text-secondary" style="font-variation-settings:'FILL' 1">person</span>
                            </span>
                            Personal Details
                        </h4>
                        <dl class="space-y-3">
                            <div class="flex justify-between text-[13px]">
                                <dt class="text-on-surface-variant">Full Name</dt>
                                <dd class="font-semibold text-on-surface">{{ employee.user?.name ?? 'â€”' }}</dd>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <dt class="text-on-surface-variant">Email</dt>
                                <dd class="font-semibold text-on-surface">{{ employee.user?.email ?? 'â€”' }}</dd>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <dt class="text-on-surface-variant">Phone</dt>
                                <dd class="font-semibold text-on-surface">{{ employee.phone ?? 'â€”' }}</dd>
                            </div>
                            <div class="flex justify-between text-[13px]">
                                <dt class="text-on-surface-variant">Status</dt>
                                <dd><StatusBadge :status="employee.status?.value ?? employee.status" type="employee" /></dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Ghana compliance — the page's institutional 5% gold accent moment -->
                    <div class="relative rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card overflow-hidden">
                        <div class="pointer-events-none absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.5),transparent)"></div>
                        <h4 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-md" style="background:rgba(255,215,0,0.14)">
                                <span class="material-symbols-outlined text-[15px]" style="color:#b88a08;font-variation-settings:'FILL' 1">shield</span>
                            </span>
                            Ghana Compliance
                        </h4>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between rounded-xl bg-surface-container-low px-4 py-2.5">
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60">Ghana Card No.</p>
                                    <p class="text-[13px] font-mono font-semibold text-on-surface">
                                        {{ employee.ghana_card ? '****' + employee.ghana_card.slice(-4) : 'â€”' }}
                                    </p>
                                </div>
                                <button @click="copyToClipboard(employee.ghana_card)" class="text-on-surface-variant hover:text-secondary transition-colors">
                                    <span class="material-symbols-outlined text-[18px]">content_copy</span>
                                </button>
                            </div>
                            <div class="flex items-center justify-between rounded-xl bg-surface-container-low px-4 py-2.5">
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60">SSNIT Number</p>
                                    <p class="text-[13px] font-mono font-semibold text-on-surface">
                                        {{ employee.ssnit_number ? '****' + employee.ssnit_number.slice(-4) : 'â€”' }}
                                    </p>
                                </div>
                                <button @click="copyToClipboard(employee.ssnit_number)" class="text-on-surface-variant hover:text-secondary transition-colors">
                                    <span class="material-symbols-outlined text-[18px]">content_copy</span>
                                </button>
                            </div>
                            <div class="flex items-center justify-between rounded-xl bg-surface-container-low px-4 py-2.5">
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60">TIN</p>
                                    <p class="text-[13px] font-mono font-semibold text-on-surface">
                                        {{ employee.tin ? '****' + employee.tin.slice(-4) : 'â€”' }}
                                    </p>
                                </div>
                                <button @click="copyToClipboard(employee.tin)" class="text-on-surface-variant hover:text-secondary transition-colors">
                                    <span class="material-symbols-outlined text-[18px]">content_copy</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Profile -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card">
                    <h4 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-md" style="background:rgba(217,18,227,0.10)">
                            <span class="material-symbols-outlined text-[15px]" style="color:#d912e3;font-variation-settings:'FILL' 1">badge</span>
                        </span>
                        Personal Profile
                    </h4>
                    <dl class="space-y-3">
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Gender</dt>
                            <dd class="font-semibold text-on-surface capitalize">{{ employee.gender ? employee.gender.replace('_', ' ') : 'â€”' }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Date of Birth</dt>
                            <dd class="font-semibold text-on-surface">{{ formatDate(employee.date_of_birth) }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">National ID</dt>
                            <dd class="font-mono text-[12.5px] font-semibold text-on-surface">{{ employee.national_id ?? 'â€”' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 text-[13px]">
                            <dt class="text-on-surface-variant flex-shrink-0">Address</dt>
                            <dd class="font-semibold text-on-surface text-right">{{ employee.address ?? 'â€”' }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Reporting / Hierarchy -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card">
                    <h4 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-md bg-secondary/10">
                            <span class="material-symbols-outlined text-[15px] text-secondary" style="font-variation-settings:'FILL' 1">account_tree</span>
                        </span>
                        Reporting
                    </h4>
                    <div v-if="employee.manager" class="rounded-xl border border-outline-variant/40 bg-surface-container-low p-3 mb-3">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Manager</p>
                        <p class="text-[14px] font-bold text-on-surface">{{ employee.manager.name ?? 'â€”' }}</p>
                        <p class="text-[12px] text-on-surface-variant">{{ employee.manager.position }} Â· {{ employee.manager.employee_no }}</p>
                    </div>
                    <p v-else class="text-[12.5px] italic text-on-surface-variant/55 mb-3">No manager assigned.</p>

                    <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-2">
                        Direct Reports ({{ employee.reports?.length ?? 0 }})
                    </p>
                    <ul v-if="employee.reports?.length" class="space-y-1.5">
                        <li v-for="r in employee.reports" :key="r.id"
                            class="group flex items-center gap-2.5 rounded-xl bg-surface-container-low px-3 py-2 hover:bg-secondary/[0.06] transition-colors">
                            <div class="h-8 w-8 flex-shrink-0 rounded-full ring-2 ring-white dark:ring-surface-container-lowest flex items-center justify-center text-[10px] font-black text-white transition-transform group-hover:scale-105"
                                 :style="`background:${gradients[r.id % gradients.length]}`">
                                {{ initials(r.name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[13px] font-bold text-on-surface truncate">{{ r.name ?? '—' }}</p>
                                <p class="text-[11px] text-on-surface-variant/65 truncate">{{ r.position }}</p>
                            </div>
                            <Link :href="route('employees.show', r.id)"
                                  class="flex items-center gap-1 rounded-lg border border-transparent px-2 py-1 text-[11px] font-bold text-secondary hover:bg-secondary/10 hover:border-secondary/20 transition-all">
                                View
                                <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="text-[12.5px] italic text-on-surface-variant/55">None.</p>
                </div>

                <!-- Emergency Contact — red retained as the universal alarm signal -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card">
                    <h4 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-md bg-red-500/10">
                            <span class="material-symbols-outlined text-[15px] text-red-500" style="font-variation-settings:'FILL' 1">emergency</span>
                        </span>
                        Emergency Contact
                    </h4>
                    <div v-if="employee.emergency_contact_name" class="space-y-3">
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Name</dt>
                            <dd class="font-semibold text-on-surface">{{ employee.emergency_contact_name }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Phone</dt>
                            <dd class="font-mono text-[12.5px] font-semibold text-on-surface">{{ employee.emergency_contact_phone ?? 'â€”' }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Relationship</dt>
                            <dd class="font-semibold text-on-surface capitalize">{{ employee.emergency_contact_relationship ?? 'â€”' }}</dd>
                        </div>
                    </div>
                    <p v-else class="text-[12.5px] italic text-on-surface-variant/55">No emergency contact on file.</p>
                </div>

                <!-- Compensation — payroll module uses the blue family (#2c74b3) -->
                <div v-if="employee.bank_name || employee.bank_account || employee.salary !== undefined"
                     class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card">
                    <h4 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-md" style="background:rgba(44,116,179,0.12)">
                            <span class="material-symbols-outlined text-[15px]" style="color:#2c74b3;font-variation-settings:'FILL' 1">account_balance_wallet</span>
                        </span>
                        Compensation
                    </h4>
                    <dl class="space-y-3">
                        <div v-if="employee.salary !== undefined" class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Salary</dt>
                            <dd class="font-black text-on-surface tabular-nums">GHS {{ Number(employee.salary ?? 0).toLocaleString('en-GH', { minimumFractionDigits: 2 }) }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Bank</dt>
                            <dd class="font-semibold text-on-surface">{{ employee.bank_name ?? 'â€”' }}</dd>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <dt class="text-on-surface-variant">Account</dt>
                            <dd class="font-mono text-[12.5px] font-semibold text-on-surface">
                                {{ employee.bank_account ? '****' + employee.bank_account.slice(-4) : 'â€”' }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Skills & Certifications — learning/tech = cyan -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card lg:col-span-2">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-[14px] font-bold text-on-surface flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-md" style="background:rgba(18,217,227,0.12)">
                                <span class="material-symbols-outlined text-[15px]" style="color:#0e8a93;font-variation-settings:'FILL' 1">workspace_premium</span>
                            </span>
                            Skills &amp; Certifications
                        </h4>
                        <span class="rounded-full bg-surface-container-low px-2.5 py-0.5 text-[10.5px] font-black uppercase tracking-[0.12em] text-on-surface-variant/60">{{ employee.skills?.length ?? 0 }} on file</span>
                    </div>
                    <div v-if="employee.skills?.length" class="flex flex-wrap gap-2">
                        <span v-for="s in employee.skills" :key="s.id"
                              class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 border" style="background:rgba(18,217,227,0.08);border-color:rgba(18,217,227,0.22)">
                            <span class="text-[12.5px] font-bold" style="color:#076670">{{ s.name }}</span>
                            <span v-if="s.level" class="text-[10px] font-black uppercase tracking-[0.12em]" style="color:rgba(7,102,112,0.7)">{{ s.level }}</span>
                            <span v-if="s.expires_at" class="text-[10px]" style="color:rgba(7,102,112,0.6)">exp {{ formatDate(s.expires_at) }}</span>
                        </span>
                    </div>
                    <p v-else class="text-[12.5px] italic text-on-surface-variant/55">No skills recorded yet.</p>
                </div>
            </div>

            <!-- â”€â”€ DOCUMENTS TAB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-show="activeTab === 'documents'" class="space-y-6">

                <!-- Upload form -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-6 shadow-card">
                    <h4 class="mb-4 text-[14px] font-bold text-on-surface">Upload Document</h4>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Document Title</label>
                            <input
                                v-model="docForm.title"
                                type="text"
                                placeholder="e.g. Employment Contract"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            />
                        </div>
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">File</label>
                            <FileUpload
                                v-model="docFile"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg"
                                :maxSizeMb="10"
                            />
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <button
                            @click="submitDoc"
                            :disabled="!docFile || !docForm.title || docForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow disabled:opacity-50"
                            style="background:linear-gradient(135deg,#0a2647,#205295)"
                        >
                            <span class="material-symbols-outlined text-[17px]">upload</span>
                            Upload
                        </button>
                    </div>
                </div>

                <!-- Document list -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                    <div v-if="!employee.documents?.length" class="p-10 text-center">
                        <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">folder_open</span>
                        <p class="mt-2 text-[13px] text-on-surface-variant">No documents uploaded yet.</p>
                    </div>
                    <table v-else class="w-full text-left">
                        <thead>
                            <tr>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Document</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Uploaded</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="doc in employee.documents" :key="doc.id"
                                class="group transition-colors hover:bg-secondary/[0.04]">
                                <td class="px-4 py-3 flex items-center gap-3">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-surface-container-low text-on-surface-variant/70">
                                        <span class="material-symbols-outlined text-[18px]" style="color:#205295">{{ docIcon(doc.file_name ?? doc.title) }}</span>
                                    </span>
                                    <span class="text-[13px] font-bold text-on-surface">{{ doc.title }}</span>
                                </td>
                                <td class="px-4 py-3 text-[12px] text-on-surface-variant tabular-nums">{{ formatDate(doc.created_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <a :href="doc.download_url ?? doc.url" download
                                           class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-secondary/10 hover:text-secondary hover:border-secondary/15 transition-all"
                                           title="Download document"
                                           aria-label="Download document">
                                            <span class="material-symbols-outlined text-[17px]">download</span>
                                        </a>
                                        <button @click="confirmDeleteDoc(doc.id)"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-on-surface-variant/70 hover:bg-red-500/10 hover:text-red-600 hover:border-red-500/15 transition-all"
                                                title="Delete document"
                                                aria-label="Delete document">
                                            <span class="material-symbols-outlined text-[17px]">delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- â”€â”€ LEAVE HISTORY TAB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-show="activeTab === 'leave'" class="space-y-6">

                <!-- Balance summary -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="bal in leaveBalances" :key="bal.type"
                         class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-4 shadow-card">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-2">{{ bal.type }}</p>
                        <p class="text-[22px] font-black text-on-surface leading-none">{{ bal.total - bal.used }}</p>
                        <p class="mt-1 text-[11px] text-on-surface-variant">
                            <span class="text-on-surface font-semibold">{{ bal.used }}</span> used of {{ bal.total }} days
                        </p>
                        <div class="mt-2 h-1.5 w-full rounded-full bg-surface-container-low overflow-hidden">
                            <div class="h-full rounded-full bg-secondary transition-all"
                                 :style="`width:${(bal.used / bal.total) * 100}%`"></div>
                        </div>
                    </div>
                </div>

                <!-- Leave table -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                    <div v-if="!employee.leaveRequests?.length" class="p-10 text-center">
                        <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">calendar_today</span>
                        <p class="mt-2 text-[13px] text-on-surface-variant">No leave requests on record.</p>
                    </div>
                    <table v-else class="w-full text-left">
                        <thead>
                            <tr>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Type</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Start</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">End</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Days</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Status</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Applied</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="req in employee.leaveRequests" :key="req.id"
                                class="transition-colors hover:bg-secondary/[0.04]">
                                <td class="px-4 py-3 text-[13px] font-semibold text-on-surface capitalize">{{ req.type }}</td>
                                <td class="px-4 py-3 text-[12px] text-on-surface-variant">{{ formatDate(req.start_date) }}</td>
                                <td class="px-4 py-3 text-[12px] text-on-surface-variant">{{ formatDate(req.end_date) }}</td>
                                <td class="px-4 py-3 text-[13px] font-bold text-on-surface">{{ req.days ?? 'â€”' }}</td>
                                <td class="px-4 py-3">
                                    <StatusBadge :status="req.status" type="leave" />
                                </td>
                                <td class="px-4 py-3 text-[12px] text-on-surface-variant">{{ formatDate(req.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- â”€â”€ TICKETS TAB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-show="activeTab === 'tickets'">
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                    <div v-if="!employee.tickets?.length" class="p-10 text-center">
                        <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">support_agent</span>
                        <p class="mt-2 text-[13px] text-on-surface-variant">No service tickets on record.</p>
                    </div>
                    <table v-else class="w-full text-left">
                        <thead>
                            <tr>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">ID</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Title</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Priority</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Status</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="ticket in employee.tickets" :key="ticket.id"
                                class="group cursor-pointer transition-colors hover:bg-secondary/[0.04]"
                                @click="router.get(route('tickets.show', ticket.id))">
                                <td class="px-4 py-3 font-mono text-[12px] text-on-surface-variant">#{{ ticket.id }}</td>
                                <td class="px-4 py-3 text-[13px] font-semibold text-on-surface">{{ ticket.title }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-bold capitalize"
                                          :class="priorityColors[ticket.priority?.toLowerCase()] ?? priorityColors.medium">
                                        {{ ticket.priority }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <StatusBadge :status="ticket.status" type="ticket" />
                                </td>
                                <td class="px-4 py-3 text-[12px] text-on-surface-variant">{{ formatDate(ticket.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- â”€â”€ PAYROLL TAB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-show="activeTab === 'payroll'">
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                    <div v-if="!employee.payments?.length" class="p-10 text-center">
                        <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">payments</span>
                        <p class="mt-2 text-[13px] text-on-surface-variant">No payroll records found.</p>
                    </div>
                    <table v-else class="w-full text-left">
                        <thead>
                            <tr>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Period</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Description</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Amount</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Status</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Paid Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="pay in employee.payments" :key="pay.id"
                                class="transition-colors hover:bg-secondary/[0.04]">
                                <td class="px-4 py-3 text-[13px] font-semibold text-on-surface">{{ pay.period }}</td>
                                <td class="px-4 py-3 text-[13px] text-on-surface-variant">{{ pay.description }}</td>
                                <td class="px-4 py-3 text-[13px] font-bold text-on-surface">{{ formatCurrency(pay.amount) }}</td>
                                <td class="px-4 py-3">
                                    <StatusBadge :status="pay.status" type="payment" />
                                </td>
                                <td class="px-4 py-3 text-[12px] text-on-surface-variant">{{ formatDate(pay.paid_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- â”€â”€ Edit Employee SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel
            :open="showEditPanel"
            title="Edit Employee"
            size="lg"
            @close="showEditPanel = false"
        >
            <form @submit.prevent="submitEdit" class="space-y-5 p-6">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Department</label>
                        <select
                            v-model="editForm.department_id"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option value="" disabled>Select department</option>
                            <option v-for="dept in (employee.allDepartments ?? [])" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Employee No.</label>
                        <input v-model="editForm.employee_no" type="text"
                               class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Position</label>
                        <input v-model="editForm.position" type="text" placeholder="e.g. Senior Analyst"
                               class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Phone</label>
                        <input v-model="editForm.phone" type="tel" placeholder="+233 24 000 0000"
                               class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Hire Date</label>
                        <input v-model="editForm.hire_date" type="date"
                               class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all" />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Status</label>
                        <select v-model="editForm.status"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all">
                            <option value="active">Active</option>
                            <option value="on_leave">On Leave</option>
                            <option value="inactive">Inactive</option>
                            <option value="terminated">Terminated</option>
                        </select>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showEditPanel = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="submitEdit" :disabled="editForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0a2647,#205295)">
                        <span v-if="editForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        Save Changes
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- â”€â”€ Delete document dialog â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <ConfirmDialog
            :open="showDeleteDocDialog"
            title="Delete Document"
            message="Are you sure you want to permanently delete this document?"
            :danger="true"
            @confirm="doDeleteDoc"
            @cancel="showDeleteDocDialog = false"
        />

    </AuthenticatedLayout>
</template>

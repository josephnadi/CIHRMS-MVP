<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge        from '@/Components/StatusBadge.vue';
import EmptyState         from '@/Components/EmptyState.vue';
import TabBar             from '@/Components/TabBar.vue';
import ProgressRing       from '@/Components/ProgressRing.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    mustVerifyEmail: Boolean,
    status:          String,
    employee:        Object,
    leaveBalances:   Array,
    recentLeave:     Array,
    recentTickets:   Array,
    recentPayments:  Array,
    documents:       Array,
    skillLevels:     Array,
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
const emp  = computed(() => props.employee?.data ?? props.employee);

const tabs = [
    { value: 'profile',   label: 'Profile',   icon: 'person'         },
    { value: 'leave',     label: 'My Leave',  icon: 'calendar_month' },
    { value: 'pay',       label: 'My Pay',    icon: 'payments'       },
    { value: 'benefits',  label: 'Benefits',  icon: 'verified_user'  },
    { value: 'documents', label: 'Documents', icon: 'folder'         },
    { value: 'tickets',   label: 'Tickets',   icon: 'support_agent'  },
    { value: 'security',  label: 'Security',  icon: 'lock'           },
];

const myBenefits = computed(() => emp.value?.benefit_enrolments ?? []);
const activeTab = ref('profile');

// ── Helpers ──────────────────────────────────────────────────────────────
function fmt(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GH', { day: '2-digit', month: 'short', year: 'numeric' });
}
function fmtMoney(amt, ccy = 'GHS') {
    if (amt == null) return '—';
    return ccy + ' ' + Number(amt).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function initials(name) {
    if (!name) return '?';
    return name.trim().split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
}
// Avatar gradient pool — disciplined cool family
const AVATAR_GRADIENTS = [
    'linear-gradient(135deg,#0d1452,#1a237e)',
    'linear-gradient(135deg,#1a237e,#7986cb)',
    'linear-gradient(135deg,#070b3a,#0d1452)',
    'linear-gradient(135deg,#1a237e,#3949ab)',
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',
    'linear-gradient(135deg,#1a237e,#12d9e3)',
];
function avatarColor(name) {
    let h = 0;
    for (let i = 0; i < (name?.length ?? 0); i++) h = name.charCodeAt(i) + ((h << 5) - h);
    return AVATAR_GRADIENTS[Math.abs(h) % AVATAR_GRADIENTS.length];
}

// ── Avatar upload ────────────────────────────────────────────────────────
const avatarInput = ref(null);
function pickAvatar() { avatarInput.value?.click(); }
function uploadAvatar(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    const data = new FormData();
    data.append('avatar', file);
    router.post(route('profile.avatar'), data, {
        forceFormData: true,
        preserveScroll: true,
    });
}

// ── Personal info form ───────────────────────────────────────────────────
const personalForm = useForm({
    phone:         emp.value?.phone         ?? '',
    gender:        emp.value?.gender        ?? '',
    date_of_birth: emp.value?.date_of_birth ?? '',
    national_id:   emp.value?.national_id   ?? '',
    address:       emp.value?.address       ?? '',
});
function savePersonal() {
    personalForm.patch(route('profile.personal'), { preserveScroll: true });
}

// ── Account (name + email) ───────────────────────────────────────────────
const accountForm = useForm({
    name:  user.value?.name  ?? '',
    email: user.value?.email ?? '',
});
function saveAccount() {
    accountForm.patch(route('profile.update'), { preserveScroll: true });
}

// ── Emergency contact form ───────────────────────────────────────────────
const emergencyForm = useForm({
    emergency_contact_name:         emp.value?.emergency_contact_name         ?? '',
    emergency_contact_phone:        emp.value?.emergency_contact_phone        ?? '',
    emergency_contact_relationship: emp.value?.emergency_contact_relationship ?? '',
});
function saveEmergency() {
    emergencyForm.patch(route('profile.emergency'), { preserveScroll: true });
}

// ── Bank form ────────────────────────────────────────────────────────────
const bankForm = useForm({
    bank_name:    emp.value?.bank_name    ?? '',
    bank_account: emp.value?.bank_account ?? '',
});
function saveBank() {
    bankForm.patch(route('profile.bank'), { preserveScroll: true });
}

// ── Password form ────────────────────────────────────────────────────────
const passwordForm = useForm({
    current_password:      '',
    password:              '',
    password_confirmation: '',
});
function savePassword() {
    passwordForm.patch(route('profile.password'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

// ── My Documents (self-service CRUD) ──────────────────────────────────────
const showUpload = ref(false);
const uploadForm = useForm({ title: '', document: null });
function pickUpload(e) { uploadForm.document = e.target.files?.[0] ?? null; }
function submitUpload() {
    uploadForm.post(route('profile.documents.store'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { uploadForm.reset(); showUpload.value = false; },
    });
}

const editingDocId = ref(null);
const editDocForm = useForm({ title: '', document: null });
function startEditDoc(d) {
    editingDocId.value = d.id;
    editDocForm.reset();
    editDocForm.clearErrors();
    editDocForm.title = d.title;
}
function cancelEditDoc() { editingDocId.value = null; editDocForm.reset(); }
function pickEditFile(e) { editDocForm.document = e.target.files?.[0] ?? null; }
function submitEditDoc(d) {
    editDocForm.post(route('profile.documents.update', d.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { editingDocId.value = null; editDocForm.reset(); },
    });
}
function deleteDoc(d) {
    if (!confirm(`Delete "${d.title}"? This cannot be undone.`)) return;
    router.delete(route('profile.documents.destroy', d.id), { preserveScroll: true });
}

// ── Skill add ────────────────────────────────────────────────────────────
const skillForm = useForm({ name: '', level: 'intermediate', expires_at: '' });
function addSkill() {
    if (!emp.value?.id) return;
    skillForm.post(route('employees.skills.store', emp.value.id), {
        preserveScroll: true,
        onSuccess: () => skillForm.reset(),
    });
}
function removeSkill(skill) {
    if (!emp.value?.id) return;
    if (!confirm(`Remove "${skill.name}" from your skills?`)) return;
    router.delete(route('employees.skills.destroy', [emp.value.id, skill.id]), { preserveScroll: true });
}

// ── Leave balance helpers ────────────────────────────────────────────────
const TYPE_META = {
    annual:    { color: '#1a237e', icon: 'beach_access' },
    sick:      { color: '#d97706', icon: 'medical_services' },
    maternity: { color: '#1a237e', icon: 'child_care' },
    paternity: { color: '#0891b2', icon: 'family_restroom' },
    emergency: { color: '#dc2626', icon: 'emergency' },
    study:     { color: '#059669', icon: 'school' },
    unpaid:    { color: '#64748b', icon: 'money_off' },
};

const balances = computed(() => props.leaveBalances?.data ?? props.leaveBalances ?? []);

const inputCls = 'w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all';
const labelCls = 'block text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1.5';

// ── No employee record yet (legacy users) ────────────────────────────────
const hasEmployee = computed(() => !!emp.value);
</script>

<template>
    <Head title="My Portal — CIHRMS" />
    <div data-page-root="true">

            <!-- ─── Hero card ──────────────────────────────────────────────────── -->
            <div class="mb-6 overflow-hidden rounded-3xl border border-outline-variant/50 bg-surface-container-lowest shadow-card">
                <div class="relative h-24 w-full overflow-hidden" style="background:linear-gradient(135deg,#070b3a,#0d1452,#1c1f3a);">
                    <!-- 5% gold hairline (single accent moment on the page) -->
                    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-px" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.55),transparent)"></div>
                </div>
                <div class="relative px-6 pb-6 pt-0">
                    <!-- Avatar -->
                    <div class="absolute -top-12 left-6">
                        <div class="relative">
                            <div class="h-24 w-24 rounded-3xl border-4 border-surface-container-lowest shadow-glow-sm overflow-hidden flex items-center justify-center text-[26px] font-black text-white"
                                 :style="emp?.avatar_url ? '' : `background:${avatarColor(user?.name)}`">
                                <img v-if="emp?.avatar_url" :src="emp.avatar_url" :alt="user?.name" class="h-full w-full object-cover" />
                                <span v-else>{{ initials(user?.name) }}</span>
                            </div>
                            <button v-if="hasEmployee"
                                    @click="pickAvatar"
                                    class="absolute -right-1 -bottom-1 flex h-8 w-8 items-center justify-center rounded-full border-2 border-surface-container-lowest bg-secondary text-white shadow-glow-sm hover:scale-110 transition-transform"
                                    title="Change photo">
                                <span class="material-symbols-outlined text-[15px]">photo_camera</span>
                            </button>
                            <input ref="avatarInput" aria-label="Upload profile photo" type="file" accept="image/*" @change="uploadAvatar" class="hidden" />
                        </div>
                    </div>

                    <!-- Identity strip -->
                    <div class="ml-32 flex flex-wrap items-end justify-between gap-4 pt-4">
                        <div>
                            <h1 class="text-[24px] font-black tracking-tight text-on-surface leading-tight">{{ user?.name }}</h1>
                            <p class="mt-0.5 text-[13.5px] text-on-surface-variant">
                                {{ emp?.position ?? 'Account holder' }}
                                <span v-if="emp?.department?.name" class="mx-1.5 text-on-surface-variant/40">·</span>
                                <span v-if="emp?.department?.name">{{ emp.department.name }}</span>
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1 rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                                    <span class="material-symbols-outlined text-[13px]">manage_accounts</span>
                                    {{ user?.role?.replace('_', ' ') ?? 'Account' }}
                                </span>
                                <span v-if="emp" class="inline-flex items-center gap-1 rounded-full bg-surface-container-low px-2.5 py-0.5 text-[11px] font-bold text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[13px]">badge</span>
                                    {{ emp.employee_no }}
                                </span>
                                <StatusBadge v-if="emp?.status" :status="emp.status" type="employee" />
                            </div>
                        </div>

                        <div v-if="emp" class="flex items-center gap-6">
                            <div class="text-center">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/55 mb-0.5">Hire Date</p>
                                <p class="text-[13px] font-bold text-on-surface">{{ fmt(emp.hire_date) }}</p>
                            </div>
                            <div class="h-10 w-px bg-outline-variant/40"></div>
                            <div class="text-center">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/55 mb-0.5">Tenure</p>
                                <p class="text-[13px] font-bold text-on-surface">{{ emp.tenure_years ? emp.tenure_years + 'y' : '—' }}</p>
                            </div>
                            <div class="h-10 w-px bg-outline-variant/40"></div>
                            <div class="text-center">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/55 mb-0.5">Leave Bal.</p>
                                <p class="text-[13px] font-bold text-on-surface">{{ balances.reduce((s, b) => s + (b.remaining_days ?? 0), 0) }}d</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Flash success ──────────────────────────────────────────────── -->
            <div v-if="$page.props.flash?.success" class="mb-4 rounded-xl border border-emerald-200 dark:border-emerald-800/40 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3">
                <p class="text-[12.5px] font-bold text-emerald-700 dark:text-emerald-400">
                    <span class="material-symbols-outlined text-[15px] align-middle mr-1" style="font-variation-settings:'FILL' 1">check_circle</span>
                    {{ $page.props.flash.success }}
                </p>
            </div>

            <!-- ─── Tabs ───────────────────────────────────────────────────────── -->
            <div class="mb-5">
                <TabBar :tabs="tabs" v-model="activeTab" />
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 PROFILE TAB
            â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeTab === 'profile'" class="grid grid-cols-1 gap-6 lg:grid-cols-2">

                <!-- Account (name + email) -->
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-[14px] font-bold text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px] text-secondary">account_circle</span>
                            Account
                        </h3>
                        <span v-if="mustVerifyEmail && !user?.email_verified_at" class="rounded-full bg-amber-100 dark:bg-amber-900/30 px-2.5 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-400">
                            UNVERIFIED
                        </span>
                    </div>
                    <form @submit.prevent="saveAccount" class="space-y-4">
                        <div>
                            <label :class="labelCls">Full Name</label>
                            <input v-model="accountForm.name" aria-label="Full name" :class="inputCls" required />
                            <p v-if="accountForm.errors.name" class="mt-1 text-[11px] text-red-500">{{ accountForm.errors.name }}</p>
                        </div>
                        <div>
                            <label :class="labelCls">Email Address</label>
                            <input v-model="accountForm.email" aria-label="Email address" type="email" :class="inputCls" required />
                            <p v-if="accountForm.errors.email" class="mt-1 text-[11px] text-red-500">{{ accountForm.errors.email }}</p>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" :disabled="accountForm.processing"
                                    class="btn-shimmer rounded-xl px-5 py-2 text-[12px] font-bold text-white disabled:opacity-60"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                Save changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Personal -->
                <div v-if="hasEmployee" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                    <h3 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px] text-secondary">person</span>
                        Personal Details
                    </h3>
                    <form @submit.prevent="savePersonal" class="space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="labelCls">Phone</label>
                                <input v-model="personalForm.phone" aria-label="Phone number" type="tel" :class="inputCls" placeholder="+233 …" />
                            </div>
                            <div>
                                <label :class="labelCls">Gender</label>
                                <select v-model="personalForm.gender" aria-label="Gender" :class="inputCls">
                                    <option value="">Prefer not to say</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="labelCls">Date of Birth</label>
                                <input v-model="personalForm.date_of_birth" aria-label="Date of birth" type="date" :class="inputCls" />
                            </div>
                            <div>
                                <label :class="labelCls">National ID</label>
                                <input v-model="personalForm.national_id" aria-label="National ID (Ghana Card)" :class="inputCls" placeholder="GHA-…" />
                            </div>
                        </div>
                        <div>
                            <label :class="labelCls">Address</label>
                            <input v-model="personalForm.address" aria-label="Address" :class="inputCls" placeholder="Street, City" />
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" :disabled="personalForm.processing"
                                    class="btn-shimmer rounded-xl px-5 py-2 text-[12px] font-bold text-white disabled:opacity-60"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                Save personal info
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Emergency contact -->
                <div v-if="hasEmployee" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                    <h3 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px] text-red-500">emergency</span>
                        Emergency Contact
                    </h3>
                    <form @submit.prevent="saveEmergency" class="space-y-4">
                        <div>
                            <label :class="labelCls">Contact Name</label>
                            <input v-model="emergencyForm.emergency_contact_name" aria-label="Emergency contact name" :class="inputCls" placeholder="Full name" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="labelCls">Phone</label>
                                <input v-model="emergencyForm.emergency_contact_phone" aria-label="Emergency contact phone" type="tel" :class="inputCls" placeholder="+233 …" />
                            </div>
                            <div>
                                <label :class="labelCls">Relationship</label>
                                <input v-model="emergencyForm.emergency_contact_relationship" aria-label="Emergency contact relationship" :class="inputCls" placeholder="e.g. Spouse" />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" :disabled="emergencyForm.processing"
                                    class="btn-shimmer rounded-xl px-5 py-2 text-[12px] font-bold text-white disabled:opacity-60"
                                    style="background:linear-gradient(135deg,#dc2626,#ef4444)">
                                Save emergency contact
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Bank -->
                <div v-if="hasEmployee" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                    <h3 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px] text-emerald-600">account_balance</span>
                        Bank Details
                    </h3>
                    <form @submit.prevent="saveBank" class="space-y-4">
                        <div>
                            <label :class="labelCls">Bank Name</label>
                            <input v-model="bankForm.bank_name" aria-label="Bank name" :class="inputCls" placeholder="e.g. Ecobank Ghana" />
                        </div>
                        <div>
                            <label :class="labelCls">Account Number</label>
                            <input v-model="bankForm.bank_account" aria-label="Bank account number" :class="inputCls" placeholder="0000000000" />
                        </div>
                        <p class="text-[11px] text-on-surface-variant/55 italic">Used for direct deposits. Visible only to you and Finance.</p>
                        <div class="flex justify-end">
                            <button type="submit" :disabled="bankForm.processing"
                                    class="btn-shimmer rounded-xl px-5 py-2 text-[12px] font-bold text-white disabled:opacity-60"
                                    style="background:linear-gradient(135deg,#059669,#10b981)">
                                Save bank details
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Skills -->
                <div v-if="hasEmployee" class="lg:col-span-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-[14px] font-bold text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px] text-blue-600">workspace_premium</span>
                            Skills &amp; Certifications
                        </h3>
                        <span class="text-[11px] font-bold text-on-surface-variant/45">{{ emp?.skills?.length ?? 0 }} on file</span>
                    </div>

                    <!-- Existing chips -->
                    <div v-if="emp?.skills?.length" class="mb-5 flex flex-wrap gap-2">
                        <span v-for="s in emp.skills" :key="s.id"
                              class="group inline-flex items-center gap-2 rounded-full border border-blue-300/40 bg-blue-50 dark:bg-blue-950/30 px-3 py-1.5">
                            <span class="text-[12.5px] font-bold text-blue-800 dark:text-blue-200">{{ s.name }}</span>
                            <span v-if="s.level" class="text-[10px] font-bold uppercase tracking-wider text-blue-600/70 dark:text-blue-300/70">{{ s.level }}</span>
                            <span v-if="s.expires_at" class="text-[10px] text-blue-500/70">exp {{ fmt(s.expires_at) }}</span>
                            <button @click="removeSkill(s)" class="opacity-40 hover:opacity-100 hover:text-red-500 transition-all">
                                <span class="material-symbols-outlined text-[13px]">close</span>
                            </button>
                        </span>
                    </div>

                    <!-- Add form -->
                    <form @submit.prevent="addSkill" class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_140px_140px_auto]">
                        <input v-model="skillForm.name" aria-label="Skill or certification name" :class="inputCls" placeholder="Skill or certification" required />
                        <select v-model="skillForm.level" aria-label="Skill proficiency level" :class="inputCls">
                            <option v-for="l in skillLevels" :key="l" :value="l">{{ l.charAt(0).toUpperCase() + l.slice(1) }}</option>
                        </select>
                        <input v-model="skillForm.expires_at" aria-label="Skill expiry date" type="date" :class="inputCls" />
                        <button type="submit" :disabled="skillForm.processing"
                                class="rounded-xl px-4 py-2 text-[12px] font-bold text-white disabled:opacity-60"
                                style="background:linear-gradient(135deg,#1a237e,#7986cb)">
                            + Add
                        </button>
                    </form>
                    <p v-if="skillForm.errors.name" class="mt-1 text-[11px] text-red-500">{{ skillForm.errors.name }}</p>
                </div>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 LEAVE TAB
            â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeTab === 'leave'" class="space-y-6">
                <!-- Balance grid -->
                <div v-if="balances.length" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="b in balances" :key="b.type"
                         class="card-lift rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card text-center">
                        <div class="relative mx-auto mb-3 h-16 w-16">
                            <ProgressRing :used="b.used_days" :total="b.total_days"
                                          :color="TYPE_META[b.type]?.color ?? '#64748b'"
                                          :size="64" :stroke="6" />
                            <span class="material-symbols-outlined absolute inset-0 m-auto text-[22px] flex items-center justify-center"
                                  :style="`color:${TYPE_META[b.type]?.color ?? '#64748b'};font-variation-settings:'FILL' 1`">
                                {{ TYPE_META[b.type]?.icon ?? 'event' }}
                            </span>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">{{ b.type }}</p>
                        <p class="text-[24px] font-black tracking-tight text-on-surface tabular-nums leading-none mt-1"
                           :style="`color:${TYPE_META[b.type]?.color ?? '#64748b'}`">
                            {{ b.remaining_days }}
                        </p>
                        <p class="mt-0.5 text-[10.5px] text-on-surface-variant/60">days left</p>
                        <p class="mt-1 text-[10px] text-on-surface-variant/45">{{ b.used_days }} used / {{ b.total_days }} total</p>
                    </div>
                </div>

                <!-- Recent requests -->
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
                    <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                        <h3 class="text-[14px] font-bold text-on-surface">Recent Requests</h3>
                        <Link :href="route('leave.index')" class="text-[12px] font-bold text-secondary hover:underline">All requests â†’</Link>
                    </div>
                    <ul v-if="recentLeave?.length" class="divide-y divide-outline-variant/30">
                        <li v-for="lr in recentLeave" :key="lr.id"
                            class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-surface-container/40 transition-colors">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl"
                                     :style="`background:rgba(${(TYPE_META[lr.type]?.color ?? '#64748b').replace('#','')[0]?'255,255,255':''},0.04);background:${TYPE_META[lr.type]?.color}15;border:1px solid ${TYPE_META[lr.type]?.color}30`">
                                    <span class="material-symbols-outlined text-[18px]"
                                          :style="`color:${TYPE_META[lr.type]?.color}`">{{ TYPE_META[lr.type]?.icon ?? 'event' }}</span>
                                </div>
                                <div>
                                    <p class="text-[13.5px] font-bold text-on-surface">{{ lr.type_label ?? lr.type }}</p>
                                    <p class="text-[11.5px] text-on-surface-variant/65">{{ fmt(lr.start_date) }} â†’ {{ fmt(lr.end_date) }}</p>
                                </div>
                            </div>
                            <StatusBadge :status="lr.status" type="leave" />
                        </li>
                    </ul>
                    <EmptyState v-else title="No leave history" description="Your leave requests will appear here once submitted." icon="calendar_today" />
                </div>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 PAY TAB
            â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeTab === 'pay'" class="space-y-6">
                <div v-if="emp?.salary !== undefined && emp?.salary !== null" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/55 mb-1">Gross Monthly Salary</p>
                            <p class="text-[34px] font-black tracking-tight text-on-surface tabular-nums">{{ fmtMoney(emp.salary) }}</p>
                        </div>
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-100 dark:bg-emerald-900/30">
                            <span class="material-symbols-outlined text-[26px] text-emerald-600 dark:text-emerald-400" style="font-variation-settings:'FILL' 1">savings</span>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
                    <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                        <h3 class="text-[14px] font-bold text-on-surface">Recent Payslips</h3>
                    </div>
                    <ul v-if="recentPayments?.length" class="divide-y divide-outline-variant/30">
                        <li v-for="p in recentPayments" :key="p.id"
                            class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-surface-container/40 transition-colors">
                            <div>
                                <p class="text-[13.5px] font-bold text-on-surface">{{ p.description ?? 'Payslip' }}</p>
                                <p class="text-[11.5px] text-on-surface-variant/65">
                                    {{ p.paid_at ? 'Paid ' + fmt(p.paid_at) : 'Created ' + fmt(p.created_at) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-[15px] font-black tabular-nums text-on-surface">{{ fmtMoney(p.amount, p.currency) }}</p>
                                <StatusBadge :status="p.status" type="payment" />
                            </div>
                        </li>
                    </ul>
                    <EmptyState v-else title="No payslips yet" description="Payslips will appear here after payroll runs." icon="payments" />
                </div>
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 BENEFITS TAB
            -->
            <div v-if="activeTab === 'benefits'" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                    <div>
                        <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.18em] text-brand-gold-deep">
                            <span class="material-symbols-outlined text-[14px]" style="font-variation-settings:'FILL' 1">verified_user</span>
                            My Benefits
                        </div>
                        <h3 class="mt-0.5 text-[14px] font-bold text-on-surface">Plans you're enrolled in</h3>
                    </div>
                    <Link v-if="myBenefits.length" :href="route('benefits.index')"
                          class="text-[11.5px] font-bold text-secondary hover:text-secondary/80 transition-colors">
                        Open Benefits module →
                    </Link>
                </div>

                <div v-if="!myBenefits.length" class="p-10 text-center">
                    <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">verified_user</span>
                    <p class="mt-2 text-[13px] text-on-surface-variant">You're not enrolled in any benefit plans yet.</p>
                    <p class="mt-1 text-[11px] text-on-surface-variant/60">HR can enrol you from the Benefits module.</p>
                </div>
                <ul v-else class="divide-y divide-outline-variant/30">
                    <li v-for="enr in myBenefits" :key="enr.id" class="flex items-start gap-3 p-4">
                        <span class="material-symbols-outlined mt-0.5 flex-shrink-0 text-[20px] text-brand-gold-deep" style="font-variation-settings:'FILL' 1">verified_user</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <p class="text-[13px] font-bold text-on-surface truncate">{{ enr.plan?.name ?? '—' }}</p>
                                <span v-if="enr.plan?.code" class="text-[10.5px] font-mono text-on-surface-variant/60">{{ enr.plan.code }}</span>
                            </div>
                            <p class="mt-0.5 text-[11.5px] font-medium text-on-surface-variant/70">
                                <span v-if="enr.plan?.type">{{ enr.plan.type }}</span>
                                <span v-if="enr.plan?.provider"> · {{ enr.plan.provider }}</span>
                                <span> · Effective from {{ fmt(enr.effective_from) }}</span>
                            </p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-[12.5px] font-black tabular-nums text-on-surface">{{ fmtMoney(enr.monthly_premium) }}</p>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60">{{ enr.status }}</p>
                        </div>
                    </li>
                </ul>
            </div>

            <!--
                 DOCUMENTS TAB
            â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeTab === 'documents'" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                    <h3 class="text-[14px] font-bold text-on-surface">My Documents</h3>
                    <div class="flex items-center gap-3">
                        <span class="text-[11px] font-bold text-on-surface-variant/45">{{ documents?.length ?? 0 }} files</span>
                        <button type="button" @click="showUpload = !showUpload"
                                class="rounded-lg bg-primary px-3 py-1.5 text-[12px] font-bold text-on-primary hover:bg-primary/90">
                            {{ showUpload ? 'Cancel' : 'Upload' }}
                        </button>
                    </div>
                </div>

                <!-- Upload panel -->
                <div v-if="showUpload" class="border-b border-outline-variant/40 bg-surface-container/30 px-5 py-4 space-y-3">
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant/70 mb-1">Title *</label>
                        <input v-model="uploadForm.title" type="text" aria-label="Document title"
                               class="w-full rounded-lg border-outline-variant/60 text-[13px]" placeholder="e.g. 2025 Bank statement" />
                        <p v-if="uploadForm.errors.title" class="mt-1 text-[11px] text-rose-600">{{ uploadForm.errors.title }}</p>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant/70 mb-1">File * (pdf/doc/docx/jpg/png, ≤10MB)</label>
                        <input type="file" aria-label="Document file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" @change="pickUpload"
                               class="block w-full text-[12px] text-on-surface-variant" />
                        <p v-if="uploadForm.errors.document" class="mt-1 text-[11px] text-rose-600">{{ uploadForm.errors.document }}</p>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" @click="submitUpload" :disabled="uploadForm.processing"
                                class="rounded-lg bg-primary px-4 py-1.5 text-[12px] font-bold text-on-primary disabled:opacity-50">
                            {{ uploadForm.processing ? 'Uploading…' : 'Save document' }}
                        </button>
                    </div>
                </div>

                <ul v-if="documents?.length" class="divide-y divide-outline-variant/30">
                    <li v-for="d in documents" :key="d.id"
                        class="px-5 py-4 hover:bg-surface-container/40 transition-colors">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary/10">
                                    <span class="material-symbols-outlined text-[18px] text-secondary">attach_file</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[13.5px] font-bold text-on-surface truncate">{{ d.title }}</p>
                                    <p class="text-[11px] text-on-surface-variant/55">
                                        {{ fmt(d.created_at) }} · {{ d.mime_type }}
                                        <span v-if="!d.can_manage" class="ml-1 text-on-surface-variant/45">· from HR</span>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <a :href="d.download_url"
                                   class="rounded-lg bg-secondary/10 px-3 py-1.5 text-[12px] font-bold text-secondary hover:bg-secondary/20">
                                    Download
                                </a>
                                <button v-if="d.can_manage" type="button" @click="startEditDoc(d)"
                                        class="rounded-lg px-2.5 py-1.5 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container">
                                    Edit
                                </button>
                                <button v-if="d.can_manage" type="button" @click="deleteDoc(d)"
                                        class="rounded-lg px-2.5 py-1.5 text-[12px] font-bold text-rose-600 hover:bg-rose-50">
                                    Delete
                                </button>
                            </div>
                        </div>

                        <!-- Inline edit (rename + optional file replace) -->
                        <div v-if="editingDocId === d.id" class="mt-3 rounded-xl bg-surface-container/40 p-3 space-y-3">
                            <div>
                                <label class="block text-[11px] font-bold text-on-surface-variant/70 mb-1">Title *</label>
                                <input v-model="editDocForm.title" type="text" aria-label="Edit document title"
                                       class="w-full rounded-lg border-outline-variant/60 text-[13px]" />
                                <p v-if="editDocForm.errors.title" class="mt-1 text-[11px] text-rose-600">{{ editDocForm.errors.title }}</p>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-on-surface-variant/70 mb-1">Replace file (optional)</label>
                                <input type="file" aria-label="Replace document file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" @change="pickEditFile"
                                       class="block w-full text-[12px] text-on-surface-variant" />
                                <p v-if="editDocForm.errors.document" class="mt-1 text-[11px] text-rose-600">{{ editDocForm.errors.document }}</p>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="cancelEditDoc"
                                        class="rounded-lg px-3 py-1.5 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container">Cancel</button>
                                <button type="button" @click="submitEditDoc(d)" :disabled="editDocForm.processing"
                                        class="rounded-lg bg-primary px-4 py-1.5 text-[12px] font-bold text-on-primary disabled:opacity-50">Save</button>
                            </div>
                        </div>
                    </li>
                </ul>
                <EmptyState v-else-if="!showUpload" title="No documents yet" description="Upload your own, or documents from HR will appear here." icon="folder" />
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 TICKETS TAB
            â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeTab === 'tickets'" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                    <h3 class="text-[14px] font-bold text-on-surface">My Service Tickets</h3>
                    <Link :href="route('tickets.index')" class="text-[12px] font-bold text-secondary hover:underline">All tickets â†’</Link>
                </div>
                <ul v-if="recentTickets?.length" class="divide-y divide-outline-variant/30">
                    <li v-for="t in recentTickets" :key="t.id"
                        class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-surface-container/40 transition-colors">
                        <div class="min-w-0">
                            <p class="text-[13.5px] font-bold text-on-surface truncate">{{ t.title }}</p>
                            <p class="text-[11.5px] text-on-surface-variant/65">{{ fmt(t.created_at) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="t.status" type="ticket" />
                            <Link :href="route('tickets.show', t.id)" class="text-[11px] font-bold text-secondary hover:underline">View</Link>
                        </div>
                    </li>
                </ul>
                <EmptyState v-else title="No tickets" description="Open a ticket from the Service Desk module if you need help." icon="support_agent" />
            </div>

            <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
                 SECURITY TAB
            â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
            <div v-if="activeTab === 'security'" class="space-y-6 max-w-2xl">

                <!-- Change password -->
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                    <h3 class="mb-4 text-[14px] font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px] text-secondary">password</span>
                        Change Password
                    </h3>
                    <form @submit.prevent="savePassword" class="space-y-4">
                        <div>
                            <label :class="labelCls">Current Password</label>
                            <input v-model="passwordForm.current_password" aria-label="Current password" type="password" autocomplete="current-password" :class="inputCls" required />
                            <p v-if="passwordForm.errors.current_password" class="mt-1 text-[11px] text-red-500">{{ passwordForm.errors.current_password }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label :class="labelCls">New Password</label>
                                <input v-model="passwordForm.password" aria-label="New password" type="password" autocomplete="new-password" :class="inputCls" required />
                                <p v-if="passwordForm.errors.password" class="mt-1 text-[11px] text-red-500">{{ passwordForm.errors.password }}</p>
                            </div>
                            <div>
                                <label :class="labelCls">Confirm New Password</label>
                                <input v-model="passwordForm.password_confirmation" aria-label="Confirm new password" type="password" autocomplete="new-password" :class="inputCls" required />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" :disabled="passwordForm.processing"
                                    class="btn-shimmer rounded-xl px-5 py-2 text-[12px] font-bold text-white disabled:opacity-60"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                Update password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Danger zone -->
                <div class="rounded-2xl border border-red-200 dark:border-red-800/40 bg-red-50/40 dark:bg-red-900/10 p-6">
                    <h3 class="mb-2 text-[14px] font-bold text-red-700 dark:text-red-400 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">warning</span>
                        Danger Zone
                    </h3>
                    <p class="text-[12.5px] text-on-surface-variant/70 leading-relaxed mb-4">
                        Deleting your account is permanent. All your records — leave history, documents, ticket trail — will be removed. Contact HR before proceeding.
                    </p>
                    <form @submit.prevent="router.delete(route('profile.destroy'), { data: { password: $event.target.password.value } })" class="flex items-end gap-3">
                        <div class="flex-1 max-w-xs">
                            <label :class="labelCls">Confirm with current password</label>
                            <input name="password" aria-label="Current password (account deletion confirmation)" type="password" autocomplete="current-password" :class="inputCls" required />
                        </div>
                        <button type="submit"
                                class="rounded-xl border border-red-300 dark:border-red-700/50 bg-red-100 dark:bg-red-900/40 px-4 py-2 text-[12px] font-bold text-red-700 dark:text-red-400 hover:bg-red-200 transition-colors">
                            Delete Account
                        </button>
                    </form>
                </div>
            </div>

    </div>
</template>

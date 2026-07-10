<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    announcements:     Object,
    stats:             { type: Object, default: () => ({}) },
    typeBreakdown:     { type: Object, default: () => ({}) },
    severityBreakdown: { type: Object, default: () => ({}) },
});

const list = computed(() => props.announcements?.data ?? props.announcements ?? []);

// ── Compose form (shared by create + edit, mode determined by `editing`) ──
const showForm = ref(false);
const editing  = ref(null);
const form = useForm({
    type:          'notice',
    severity:      'info',
    title:         '',
    body:          '',
    link_url:      '',
    audience_role: '',
    pinned:        false,
    is_active:     true,
    starts_at:     '',
    ends_at:       '',
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const openEdit = (announcement) => {
    editing.value = announcement;
    form.type          = announcement.type          ?? 'notice';
    form.severity      = announcement.severity      ?? 'info';
    form.title         = announcement.title         ?? '';
    form.body          = announcement.body          ?? '';
    form.link_url      = announcement.link_url      ?? '';
    form.audience_role = announcement.audience_role ?? '';
    form.pinned        = !! announcement.pinned;
    form.is_active     = announcement.is_active !== false;
    form.starts_at     = announcement.starts_at ? announcement.starts_at.slice(0, 16) : '';
    form.ends_at       = announcement.ends_at   ? announcement.ends_at.slice(0, 16)   : '';
    form.clearErrors();
    showForm.value = true;
};

const submit = () => {
    if (editing.value) {
        form.patch(route('announcements.update', editing.value.id), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); showForm.value = false; editing.value = null; },
        });
    } else {
        form.post(route('announcements.store'), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); showForm.value = false; },
        });
    }
};

const remove = (id) => {
    if (!confirm('Remove this notice?')) return;
    router.delete(route('announcements.destroy', id), { preserveScroll: true });
};

// ── Palette-keyed type metadata ──
const TYPE_META = {
    notice:   { icon: 'campaign',  label: 'Notice',   tile: 'icon-brand',   accent: '#1a237e' },
    event:    { icon: 'event',     label: 'Event',    tile: 'icon-cyan',    accent: '#12d9e3' },
    birthday: { icon: 'cake',      label: 'Birthday', tile: 'icon-magenta', accent: '#d912e3' },
    task:     { icon: 'task_alt',  label: 'Task',     tile: 'icon-gold',    accent: '#ffd700' },
    system:   { icon: 'info',      label: 'System',   tile: 'icon-sky',     accent: '#7986cb' },
};
const typeMeta = (k) => TYPE_META[k] ?? TYPE_META.system;

// Severity → palette pill
const SEVERITY_META = {
    info:      { label: 'Info',      cls: 'bg-cyan-50 text-cyan-700 border-cyan-200',     dot: '#12d9e3' },
    important: { label: 'Important', cls: 'bg-amber-50 text-amber-700 border-amber-200',  dot: '#ffd700' },
    urgent:    { label: 'Urgent',    cls: 'bg-rose-50 text-rose-700 border-rose-200',     dot: '#d912e3' },
};
const sevMeta = (k) => SEVERITY_META[k] ?? SEVERITY_META.info;

const audienceRoles = [
    { value: '',                label: 'Everyone' },
    { value: 'hr_admin',        label: 'HR' },
    { value: 'manager',         label: 'Managers' },
    { value: 'employee',        label: 'Employees' },
    { value: 'finance_officer', label: 'Finance' },
    { value: 'it_support',      label: 'IT Support' },
    { value: 'marketing',       label: 'Marketing' },
];

// ── Filters ──
const typeFilter     = ref('');
const severityFilter = ref('');
const statusFilter   = ref(''); // '' | active | pinned | scheduled | expired
const search         = ref('');

const now = Date.now();
const noticeStatus = (a) => {
    if (!a.is_active) return 'inactive';
    if (a.ends_at && new Date(a.ends_at).getTime() < now) return 'expired';
    if (a.starts_at && new Date(a.starts_at).getTime() > now) return 'scheduled';
    return 'active';
};

const filtered = computed(() => {
    return list.value.filter(a => {
        if (typeFilter.value && a.type !== typeFilter.value) return false;
        if (severityFilter.value && a.severity !== severityFilter.value) return false;
        if (statusFilter.value === 'pinned' && !a.pinned) return false;
        if (statusFilter.value === 'active' && noticeStatus(a) !== 'active') return false;
        if (statusFilter.value === 'scheduled' && noticeStatus(a) !== 'scheduled') return false;
        if (statusFilter.value === 'expired' && noticeStatus(a) !== 'expired') return false;
        if (search.value.trim()) {
            const q = search.value.trim().toLowerCase();
            if (!(a.title ?? '').toLowerCase().includes(q) && !(a.body ?? '').toLowerCase().includes(q)) return false;
        }
        return true;
    });
});

const hasFilters = computed(() => typeFilter.value || severityFilter.value || statusFilter.value || search.value);
const clearFilters = () => { typeFilter.value = ''; severityFilter.value = ''; statusFilter.value = ''; search.value = ''; };

// ── Type donut data ──
const totalByType = computed(() => Object.values(props.typeBreakdown ?? {}).reduce((s, v) => s + Number(v), 0));
const typeSegs    = computed(() => {
    const t = totalByType.value || 1;
    return Object.fromEntries(
        Object.keys(TYPE_META).map(k => [k, ((props.typeBreakdown?.[k] ?? 0) / t) * 100])
    );
});

const fmtWhen = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <Head title="Notice Board" />
    <div data-page-root="true">

            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Notice Board</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Publish notices that scroll across the top of every authenticated page
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5 rounded-full bg-cyan-50 border border-cyan-200 px-3 py-1.5 dark:bg-cyan-900/20 dark:border-cyan-800/40">
                            <span class="h-1.5 w-1.5 rounded-full bg-cyan-500 live-dot"></span>
                            <span class="text-[10px] font-black uppercase tracking-widest text-cyan-700 dark:text-cyan-300">{{ stats?.active_now ?? 0 }} live on ticker</span>
                        </div>
                        <button @click="showForm ? (showForm = false, editing = null) : openCreate()"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                                style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                            <span class="material-symbols-outlined text-[18px]">{{ showForm ? 'close' : 'add' }}</span>
                            {{ showForm ? 'Cancel' : 'New notice' }}
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- ── Hero banner ── -->
                <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white animate-reveal-up"
                     style="background:linear-gradient(135deg,#1a237e 0%, #283593 55%, #3949ab 100%);border:1px solid rgba(255,255,255,0.06);">
                    <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(18,217,227,0.18),transparent 70%)"></div>
                    <div class="pointer-events-none absolute -left-8 bottom-0 h-48 w-48 rounded-full blur-2xl" style="background:rgba(255,215,0,0.06)"></div>

                    <div class="relative flex flex-wrap items-center justify-between gap-8">
                        <div>
                            <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-2" style="color:rgba(18,217,227,0.7)">Communications · ticker</p>
                            <h2 class="text-3xl font-black leading-tight">
                                <em class="not-italic" style="color:#12d9e3">{{ stats?.active_now ?? 0 }}</em> notice<span v-if="(stats?.active_now ?? 0) !== 1">s</span> on the live ticker
                                <span v-if="stats?.pinned" class="text-base font-bold opacity-50">· <span style="color:#ffd700">{{ stats.pinned }}</span> pinned</span>
                            </h2>
                            <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.5)">
                                <span style="color:#7986cb">{{ stats?.scheduled ?? 0 }}</span> queued for future ·
                                <span style="color:#7986cb">{{ stats?.expired ?? 0 }}</span> expired ·
                                scrolling on every authenticated page top
                            </p>
                        </div>
                        <div class="flex items-center gap-8 flex-shrink-0">
                            <div v-for="kpi in [
                                { label: 'Live',      val: stats?.active_now ?? 0, color: '#12d9e3' },
                                { label: 'Pinned',    val: stats?.pinned ?? 0,     color: '#ffd700' },
                                { label: 'Scheduled', val: stats?.scheduled ?? 0,  color: '#7986cb' },
                            ]" :key="kpi.label" class="text-center">
                                <p class="text-3xl font-black leading-none tabular-nums" :style="`color:${kpi.color}`">{{ kpi.val }}</p>
                                <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.35)">{{ kpi.label }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── KPI tiles ── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(card, i) in [
                        { label: 'Live now',    val: stats?.active_now ?? 0, sub: 'Showing on ticker',   cls: 'icon-cyan',    icon: 'campaign' },
                        { label: 'Pinned',      val: stats?.pinned ?? 0,     sub: 'Stays first in queue',cls: 'icon-gold',    icon: 'push_pin' },
                        { label: 'Scheduled',   val: stats?.scheduled ?? 0,  sub: 'Queued for later',    cls: 'icon-brand',   icon: 'schedule' },
                        { label: 'Expired',     val: stats?.expired ?? 0,    sub: 'Past their window',   cls: 'icon-magenta', icon: 'history' },
                    ]" :key="card.label"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.06}s`">
                        <div class="icon-tile" :class="card.cls">
                            <span class="material-symbols-outlined">{{ card.icon }}</span>
                        </div>
                        <p class="mt-3 text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                        <p class="mt-1 text-[28px] font-black tabular-nums text-primary leading-none">{{ card.val }}</p>
                        <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                    </div>
                </div>

                <!-- ── Compose form (collapsible) ── -->
                <Transition
                    enter-active-class="transition-all duration-300 ease-spring"
                    enter-from-class="opacity-0 -translate-y-3"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition-all duration-200 ease-in"
                    leave-to-class="opacity-0 -translate-y-3"
                >
                    <section v-if="showForm" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                        <header class="flex items-center justify-between border-b border-outline-variant/50 px-6 py-4 bg-surface-container-low/30">
                            <div class="flex items-center gap-2">
                                <div class="icon-tile icon-cyan" style="width:30px;height:30px;border-radius:8px">
                                    <span class="material-symbols-outlined text-[16px]">edit_note</span>
                                </div>
                                <h2 class="text-[13px] font-black uppercase tracking-[0.12em] text-primary">Compose a notice</h2>
                            </div>
                            <button @click="showForm = false" class="rounded-lg p-1 text-on-surface-variant hover:bg-surface-container transition-colors">
                                <span class="material-symbols-outlined text-[18px]">close</span>
                            </button>
                        </header>

                        <form @submit.prevent="submit" class="p-6 space-y-5">

                            <div class="rounded-xl bg-cyan-50/60 border border-cyan-200/60 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                                <span class="material-symbols-outlined text-cyan-600 text-[20px] mt-0.5">info</span>
                                <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                                    Notices stream across the top of every authenticated page. Use <span class="font-bold">Pinned</span> to keep a notice first; use <span class="font-bold">Urgent</span> severity sparingly — it shifts the ticker to alarm colours.
                                </p>
                            </div>

                            <!-- Title -->
                            <div>
                                <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Title <span class="text-rose-500">*</span></label>
                                <input aria-label="Title" v-model="form.title" type="text" required maxlength="180"
                                       placeholder="Office closes 1pm Friday for staff retreat"
                                       class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13.5px] focus:border-secondary focus:ring-secondary/20"
                                       :class="{ 'border-rose-400': form.errors.title }"/>
                                <p v-if="form.errors.title" class="mt-1 text-[11px] font-bold text-rose-500">{{ form.errors.title }}</p>
                            </div>

                            <!-- Body -->
                            <div>
                                <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Details <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(optional)</span></label>
                                <textarea aria-label="Details (optional)" v-model="form.body" rows="3" maxlength="2000"
                                          placeholder="Add context, dates, location, or a brief explanation."
                                          class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13.5px] focus:border-secondary focus:ring-secondary/20 resize-none"></textarea>
                                <InputError :message="form.errors.body" />
                            </div>

                            <!-- Type selector — pill row -->
                            <div>
                                <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-2">Type</label>
                                <div class="flex flex-wrap gap-2">
                                    <button v-for="(meta, k) in TYPE_META" :key="k" type="button"
                                            @click="form.type = k"
                                            :class="['inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-[11.5px] font-black uppercase tracking-wide transition-all',
                                                      form.type === k
                                                        ? 'border-2 text-white shadow-glow-sm'
                                                        : 'border-outline-variant text-on-surface-variant hover:border-secondary/40']"
                                            :style="form.type === k ? `background:${meta.accent};border-color:${meta.accent}` : ''">
                                        <span class="material-symbols-outlined text-[15px]">{{ meta.icon }}</span>
                                        {{ meta.label }}
                                    </button>
                                </div>
                                <InputError :message="form.errors.type" />
                            </div>

                            <!-- Severity + Audience + Link -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Severity</label>
                                    <select aria-label="Severity" v-model="form.severity"
                                            class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20">
                                        <option v-for="(meta, k) in SEVERITY_META" :key="k" :value="k">{{ meta.label }}</option>
                                    </select>
                                    <InputError :message="form.errors.severity" />
                                </div>
                                <div>
                                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Audience</label>
                                    <select aria-label="Audience" v-model="form.audience_role"
                                            class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20">
                                        <option v-for="r in audienceRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                                    </select>
                                    <InputError :message="form.errors.audience_role" />
                                </div>
                                <div>
                                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Link <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(optional)</span></label>
                                    <input aria-label="Link (optional)" v-model="form.link_url" type="url" placeholder="https://…"
                                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                                    <InputError :message="form.errors.link_url" />
                                </div>
                            </div>

                            <!-- Window + toggles -->
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-end">
                                <div>
                                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Starts <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(optional)</span></label>
                                    <input aria-label="Starts (optional)" v-model="form.starts_at" type="datetime-local"
                                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                                    <InputError :message="form.errors.starts_at" />
                                </div>
                                <div>
                                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Ends <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(optional)</span></label>
                                    <input aria-label="Ends (optional)" v-model="form.ends_at" type="datetime-local"
                                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                                    <InputError :message="form.errors.ends_at" />
                                </div>
                                <div class="flex items-center gap-3">
                                    <label class="cursor-pointer flex items-center justify-center gap-2 rounded-xl border px-3 py-2.5 text-[12px] font-black transition-all flex-1"
                                           :class="form.pinned ? 'border-amber-300 bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300' : 'border-outline-variant text-on-surface-variant hover:border-amber-300/50'">
                                        <input v-model="form.pinned" aria-label="Pin to front" type="checkbox" class="sr-only"/>
                                        <span class="material-symbols-outlined text-[16px]">push_pin</span>
                                        Pin to front
                                    </label>
                                    <label class="cursor-pointer flex items-center justify-center gap-2 rounded-xl border px-3 py-2.5 text-[12px] font-black transition-all flex-1"
                                           :class="form.is_active ? 'border-cyan-300 bg-cyan-50 text-cyan-700 dark:bg-cyan-900/20 dark:text-cyan-300' : 'border-outline-variant text-on-surface-variant hover:border-cyan-300/50'">
                                        <input v-model="form.is_active" aria-label="Active" type="checkbox" class="sr-only"/>
                                        <span class="material-symbols-outlined text-[16px]">visibility</span>
                                        Active
                                    </label>
                                </div>
                            </div>

                            <!-- Live preview -->
                            <div class="rounded-xl border border-outline-variant/40 bg-gradient-to-r from-[#070b3a] to-[#131a5c] px-4 py-3 text-white">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="material-symbols-outlined text-[14px]" style="color:#12d9e3">visibility</span>
                                    <span class="text-[9px] font-black uppercase tracking-widest" style="color:rgba(18,217,227,0.8)">Preview</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span v-if="form.pinned" class="material-symbols-outlined text-[14px]" style="color:#ffd700;transform:rotate(35deg)">push_pin</span>
                                    <span class="material-symbols-outlined text-[16px]" :style="`color:${typeMeta(form.type).accent}`">{{ typeMeta(form.type).icon }}</span>
                                    <span class="text-[12.5px] font-bold flex-1">{{ form.title || '— title preview —' }}</span>
                                    <span v-if="form.severity === 'urgent'" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wider" style="background:rgba(217,18,227,0.18);color:#d912e3">Urgent</span>
                                    <span v-else-if="form.severity === 'important'" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wider" style="background:rgba(255,215,0,0.18);color:#ffd700">Important</span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center justify-end gap-3 border-t border-outline-variant/50 -mx-6 px-6 pt-4">
                                <button type="button" @click="showForm = false"
                                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                                    Cancel
                                </button>
                                <button type="submit" :disabled="form.processing"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white disabled:opacity-60 shadow-glow-sm transition-all hover:-translate-y-px"
                                        style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                                    <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                                    <span v-else class="material-symbols-outlined text-[16px]">send</span>
                                    {{ form.processing ? 'Publishing…' : 'Publish notice' }}
                                </button>
                            </div>
                        </form>
                    </section>
                </Transition>

                <!-- ── Visual band: type donut + composition ── -->
                <div v-if="totalByType > 0" class="grid gap-6 lg:grid-cols-3 animate-reveal-up">

                    <!-- Type donut -->
                    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 flex flex-col">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-[15px] font-black text-primary">Notices by type</h3>
                            <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">{{ totalByType }} total</span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant mb-4">All-time composition of the register.</p>

                        <div class="flex items-center justify-center relative my-2">
                            <svg viewBox="0 0 100 100" width="180" height="180" class="-rotate-90">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="rgb(var(--ct-surface-low))" stroke-width="10"/>
                                <template v-for="(k, idx) in Object.keys(TYPE_META)" :key="k">
                                    <circle v-if="typeSegs[k] > 0" cx="50" cy="50" r="42" fill="none"
                                            :stroke="TYPE_META[k].accent" stroke-width="10"
                                            :stroke-dasharray="`${typeSegs[k] * 2.6389} ${263.89}`"
                                            :stroke-dashoffset="`${-Object.keys(TYPE_META).slice(0, idx).reduce((s, kk) => s + typeSegs[kk] * 2.6389, 0)}`"/>
                                </template>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Live</p>
                                <p class="text-3xl font-black tabular-nums text-primary leading-none">{{ stats?.active_now ?? 0 }}</p>
                                <p class="mt-0.5 text-[9.5px] font-bold text-on-surface-variant/70">on ticker</p>
                            </div>
                        </div>

                        <!-- Legend (click to filter) -->
                        <div class="mt-4 space-y-1.5">
                            <button v-for="(meta, k) in TYPE_META" :key="k"
                                    @click="typeFilter = typeFilter === k ? '' : k"
                                    class="w-full flex items-center justify-between text-[11.5px] transition-colors hover:opacity-80"
                                    :class="typeFilter && typeFilter !== k ? 'opacity-40' : ''">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full" :style="`background:${meta.accent}`"></span>
                                    <span class="font-semibold text-on-surface-variant">{{ meta.label }}</span>
                                </div>
                                <span class="font-black tabular-nums text-primary">{{ typeBreakdown[k] ?? 0 }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Severity composition + insights (spans 2/3) -->
                    <div class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 flex flex-col">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-[15px] font-black text-primary">Severity mix</h3>
                            <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">Distribution</span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant mb-5">How urgent the register is overall. Urgent should be rare.</p>

                        <div class="space-y-3 flex-1">
                            <button v-for="(meta, k) in SEVERITY_META" :key="k"
                                    @click="severityFilter = severityFilter === k ? '' : k"
                                    class="w-full group flex items-center gap-3 transition-all"
                                    :class="severityFilter && severityFilter !== k ? 'opacity-40' : ''">
                                <span class="w-28 flex-shrink-0 flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full" :style="`background:${meta.dot}`"></span>
                                    <span class="text-[12px] font-bold text-on-surface">{{ meta.label }}</span>
                                </span>
                                <div class="flex-1 h-6 rounded-lg bg-surface-container-low border border-outline-variant/30 relative overflow-hidden">
                                    <div class="absolute inset-y-0 left-0 rounded-lg transition-all duration-700 flex items-center justify-end pr-2.5"
                                         :style="`width:${totalByType > 0 ? Math.min(100, (severityBreakdown[k] ?? 0) / Math.max(1, totalByType) * 100 * 2) : 0}%;background:linear-gradient(90deg,${meta.dot}cc,${meta.dot})`">
                                        <span class="text-[10px] font-black text-white tabular-nums">{{ severityBreakdown[k] ?? 0 }}</span>
                                    </div>
                                </div>
                                <span class="w-12 text-right text-[10px] font-bold text-on-surface-variant/70 tabular-nums">{{ Math.round(((severityBreakdown[k] ?? 0) / Math.max(1, totalByType)) * 100) }}%</span>
                            </button>
                        </div>

                        <!-- Quick filters -->
                        <div class="mt-5 pt-4 border-t border-outline-variant/40 grid grid-cols-3 gap-2">
                            <button @click="statusFilter = statusFilter === 'pinned' ? '' : 'pinned'"
                                    class="rounded-xl border px-3 py-2 transition-all"
                                    :class="statusFilter === 'pinned' ? 'border-amber-300 bg-amber-50 dark:bg-amber-900/20' : 'border-outline-variant/40 hover:border-amber-300/60'">
                                <p class="text-[9px] font-black uppercase tracking-widest text-amber-700">Pinned</p>
                                <p class="text-[18px] font-black tabular-nums" :style="`color:#b88a08`">{{ stats?.pinned ?? 0 }}</p>
                            </button>
                            <button @click="statusFilter = statusFilter === 'scheduled' ? '' : 'scheduled'"
                                    class="rounded-xl border px-3 py-2 transition-all"
                                    :class="statusFilter === 'scheduled' ? 'border-cyan-300 bg-cyan-50 dark:bg-cyan-900/20' : 'border-outline-variant/40 hover:border-cyan-300/60'">
                                <p class="text-[9px] font-black uppercase tracking-widest text-cyan-700">Scheduled</p>
                                <p class="text-[18px] font-black tabular-nums" style="color:#0e8a93">{{ stats?.scheduled ?? 0 }}</p>
                            </button>
                            <button @click="statusFilter = statusFilter === 'expired' ? '' : 'expired'"
                                    class="rounded-xl border px-3 py-2 transition-all"
                                    :class="statusFilter === 'expired' ? 'border-slate-300 bg-slate-50 dark:bg-slate-900/30' : 'border-outline-variant/40 hover:border-slate-300/60'">
                                <p class="text-[9px] font-black uppercase tracking-widest text-on-surface-variant">Expired</p>
                                <p class="text-[18px] font-black tabular-nums text-on-surface-variant">{{ stats?.expired ?? 0 }}</p>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Notice register ── -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">

                    <!-- Filter row -->
                    <div class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-secondary">filter_list</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Filter</span>
                        </div>
                        <div class="relative flex-1 min-w-[200px] max-w-xs">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/50">search</span>
                            <input aria-label="Search" v-model="search" placeholder="Search title or body…"
                                   class="w-full rounded-xl border-outline-variant pl-9 text-[12.5px] focus:border-secondary focus:ring-secondary/20"/>
                        </div>
                        <select aria-label="TypeFilter" v-model="typeFilter"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All types</option>
                            <option v-for="(meta, k) in TYPE_META" :key="k" :value="k">{{ meta.label }}</option>
                        </select>
                        <select aria-label="SeverityFilter" v-model="severityFilter"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All severities</option>
                            <option v-for="(meta, k) in SEVERITY_META" :key="k" :value="k">{{ meta.label }}</option>
                        </select>
                        <select aria-label="StatusFilter" v-model="statusFilter"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="pinned">Pinned</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="expired">Expired</option>
                        </select>
                        <button v-if="hasFilters" @click="clearFilters"
                                class="ml-auto rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[15px]">close</span>
                            Clear
                        </button>
                    </div>

                    <!-- Empty states -->
                    <div v-if="!list.length" class="px-6 py-16">
                        <EmptyState icon="campaign" title="No notices yet" hint="Click 'New notice' to publish your first one." />
                    </div>
                    <div v-else-if="!filtered.length" class="px-6 py-16">
                        <EmptyState icon="filter_list" title="No notices match your filters" hint="Try clearing filters or broadening your search." />
                    </div>

                    <!-- List -->
                    <ul v-else class="divide-y divide-outline-variant/30">
                        <li v-for="(a, i) in filtered" :key="a.id"
                            class="flex items-start gap-4 px-6 py-4 hover:bg-surface-container-low/40 transition-colors"
                            :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.025}s;border-left:3px solid ${typeMeta(a.type).accent};`">

                            <!-- Type tile -->
                            <div class="icon-tile flex-shrink-0" :class="typeMeta(a.type).tile">
                                <span class="material-symbols-outlined">{{ a.icon || typeMeta(a.type).icon }}</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <!-- Title + pills -->
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-[14px] font-black text-primary leading-tight truncate">{{ a.title }}</p>
                                    <span v-if="a.pinned"
                                          class="inline-flex items-center gap-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">
                                        <span class="material-symbols-outlined text-[11px]" style="transform:rotate(35deg)">push_pin</span>
                                        Pinned
                                    </span>
                                    <span v-if="!a.is_active"
                                          class="inline-flex items-center gap-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">
                                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                        Inactive
                                    </span>
                                    <span v-else-if="noticeStatus(a) === 'scheduled'"
                                          class="inline-flex items-center gap-1 rounded-full bg-cyan-50 text-cyan-700 border border-cyan-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">
                                        <span class="material-symbols-outlined text-[11px]">schedule</span>
                                        Scheduled
                                    </span>
                                    <span v-else-if="noticeStatus(a) === 'expired'"
                                          class="inline-flex items-center gap-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">
                                        <span class="material-symbols-outlined text-[11px]">history</span>
                                        Expired
                                    </span>
                                    <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                          :class="sevMeta(a.severity).cls">
                                        <span class="h-1.5 w-1.5 rounded-full" :style="`background:${sevMeta(a.severity).dot}`"></span>
                                        {{ sevMeta(a.severity).label }}
                                    </span>
                                </div>

                                <!-- Body -->
                                <p v-if="a.body" class="mt-1 text-[12.5px] text-on-surface-variant leading-relaxed line-clamp-2">{{ a.body }}</p>

                                <!-- Meta row -->
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10.5px] font-bold uppercase tracking-wider text-on-surface-variant/70">
                                    <span class="inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[12px]" :style="`color:${typeMeta(a.type).accent}`">{{ typeMeta(a.type).icon }}</span>
                                        {{ typeMeta(a.type).label }}
                                    </span>
                                    <span class="opacity-50">·</span>
                                    <span class="inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[12px]">group</span>
                                        {{ a.audience_role ? a.audience_role.replace('_', ' ') : 'Everyone' }}
                                    </span>
                                    <template v-if="a.starts_at">
                                        <span class="opacity-50">·</span>
                                        <span class="inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[12px]">play_circle</span>
                                            {{ fmtWhen(a.starts_at) }}
                                        </span>
                                    </template>
                                    <template v-if="a.ends_at">
                                        <span class="opacity-50">·</span>
                                        <span class="inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[12px]">stop_circle</span>
                                            {{ fmtWhen(a.ends_at) }}
                                        </span>
                                    </template>
                                    <template v-if="a.author">
                                        <span class="opacity-50">·</span>
                                        <span class="inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[12px]">person</span>
                                            {{ a.author.name }}
                                        </span>
                                    </template>
                                    <a v-if="a.link_url" :href="a.link_url" target="_blank" rel="noopener"
                                       class="inline-flex items-center gap-1 text-secondary hover:underline">
                                        <span class="opacity-50">·</span>
                                        <span class="material-symbols-outlined text-[12px]">open_in_new</span>
                                        Open link
                                    </a>
                                </div>
                            </div>

                            <div class="flex items-center gap-1 flex-shrink-0">
                                <button @click="openEdit(a)"
                                        class="flex h-8 w-8 items-center justify-center rounded-lg text-on-surface-variant/60 hover:bg-secondary/10 hover:text-secondary transition-colors"
                                        aria-label="Edit notice">
                                    <span class="material-symbols-outlined text-[17px]">edit</span>
                                </button>
                                <button @click="remove(a.id)"
                                        class="flex h-8 w-8 items-center justify-center rounded-lg text-on-surface-variant/60 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-900/20 transition-colors"
                                        aria-label="Remove notice">
                                    <span class="material-symbols-outlined text-[17px]">delete</span>
                                </button>
                            </div>
                        </li>
                    </ul>

                    <!-- Footer count -->
                    <div v-if="filtered.length" class="px-6 py-3 border-t border-outline-variant/40 text-[11.5px] text-on-surface-variant flex items-center justify-between">
                        <span>Showing <span class="font-black text-primary">{{ filtered.length }}</span> of <span class="font-black text-primary">{{ list.length }}</span> notice<span v-if="list.length !== 1">s</span></span>
                        <span v-if="hasFilters" class="text-[10.5px] font-bold text-secondary">Filters active</span>
                    </div>
                </div>
            </div>

    </div>
</template>

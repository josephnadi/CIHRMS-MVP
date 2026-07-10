<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useToast } from '@/composables/useToast';

defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    slug:         { type: String, required: true },
    department:   { type: Object, default: null },   // real Department { id, name, code, head }
    headcount:    { type: Number, default: 0 },      // real active headcount
    members:      { type: Array,  default: () => [] },// real roster [{ name, position }]
    activeModule: { type: String, default: '' },
});

const { comingSoon } = useToast();

// Per-portal BRANDING only (icon / accent / tagline) — all figures below are live.
const BRAND = {
    it:             { title: 'IT & Technology',   tagline: 'Infrastructure, security and service operations.',  icon: 'computer',                 accent: '#12d9e3', rgb: '18,217,227' },
    hr:             { title: 'Human Resources',   tagline: 'People operations, recruitment and culture.',        icon: 'people',                   accent: '#d912e3', rgb: '217,18,227' },
    marketing:      { title: 'Marketing',         tagline: 'Brand, communications and campaign delivery.',       icon: 'campaign',                 accent: '#7986cb', rgb: '121,134,203' },
    finance:        { title: 'Finance',           tagline: 'Treasury, payroll and statutory compliance.',        icon: 'account_balance_wallet',   accent: '#3949ab', rgb: '57,73,171' },
    membership:     { title: 'Membership',        tagline: 'Member records, subscriptions and engagement.',      icon: 'card_membership',          accent: '#3949ab', rgb: '57,73,171' },
    pcp:            { title: 'PCP',               tagline: 'Professional certification programme.',              icon: 'workspace_premium',        accent: '#12d9e3', rgb: '18,217,227' },
    cpd:            { title: 'CPD',               tagline: 'Continuing professional development.',               icon: 'school',                   accent: '#12d9e3', rgb: '18,217,227' },
    administration: { title: 'Administration',    tagline: 'Corporate services and facilities.',                icon: 'apartment',                accent: '#7986cb', rgb: '121,134,203' },
};

const brand = computed(() => BRAND[props.slug] ?? BRAND.administration);
const title = computed(() => props.department?.name ?? brand.value.title);
const head  = computed(() => props.department?.head?.name ?? null);
const initials = (name) => (name || '?').split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
</script>

<template>
    <Head :title="`${title} — CIHRMS`" />
    <div data-page-root="true">
        <!-- Hero strip -->
        <div class="mb-6 overflow-hidden rounded-3xl text-white"
             style="background:linear-gradient(135deg,#1a237e,#3949ab);border:1px solid rgba(255,255,255,0.06);">
            <div class="relative px-7 py-7">
                <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl"
                     :style="`background:radial-gradient(circle,rgba(${brand.rgb},0.25),transparent 70%)`"></div>
                <div class="relative flex flex-wrap items-center justify-between gap-5">
                    <div class="flex items-center gap-5">
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl flex-shrink-0"
                             :style="`background:rgba(${brand.rgb},0.2);border:1px solid rgba(${brand.rgb},0.3)`">
                            <span class="material-symbols-outlined text-3xl"
                                  :style="`color:${brand.accent};font-variation-settings:'FILL' 1`">{{ brand.icon }}</span>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/45 mb-1">
                                Department Portal<span v-if="department"> · {{ department.code }}</span>
                            </p>
                            <h1 class="text-[24px] font-black tracking-tight">{{ title }}</h1>
                            <p class="text-[12.5px] text-white/55">{{ brand.tagline }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="head" class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-[11px] font-bold text-white/80">
                            <span class="material-symbols-outlined text-[14px]">badge</span> Led by {{ head }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live stats -->
        <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl" :style="`background:${brand.accent}18;border:1px solid ${brand.accent}30`">
                        <span class="material-symbols-outlined text-[18px]" :style="`color:${brand.accent};font-variation-settings:'FILL' 1`">groups</span>
                    </div>
                </div>
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Active headcount</p>
                <p class="mt-0.5 text-[24px] font-black tracking-tight text-on-surface tabular-nums">{{ headcount }}</p>
            </div>
            <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl" :style="`background:${brand.accent}18;border:1px solid ${brand.accent}30`">
                        <span class="material-symbols-outlined text-[18px]" :style="`color:${brand.accent};font-variation-settings:'FILL' 1`">badge</span>
                    </div>
                </div>
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Department head</p>
                <p class="mt-0.5 text-[16px] font-black tracking-tight text-on-surface truncate">{{ head ?? '—' }}</p>
            </div>
            <div class="col-span-2 sm:col-span-1 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl" :style="`background:${brand.accent}18;border:1px solid ${brand.accent}30`">
                        <span class="material-symbols-outlined text-[18px]" :style="`color:${brand.accent};font-variation-settings:'FILL' 1`">tag</span>
                    </div>
                </div>
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Code</p>
                <p class="mt-0.5 text-[24px] font-black tracking-tight text-on-surface">{{ department?.code ?? '—' }}</p>
            </div>
        </div>

        <!-- Roster -->
        <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-[14px] font-bold text-on-surface">Team roster</h3>
                <Link :href="route('employees.index')" class="text-[11px] font-bold text-secondary hover:underline">View all employees</Link>
            </div>
            <div v-if="members.length" class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="(m, i) in members" :key="i" class="flex items-center gap-3 rounded-xl border border-outline-variant/40 px-3 py-2">
                    <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-[11px] font-black text-white"
                          :style="`background:${brand.accent}`">{{ initials(m.name) }}</span>
                    <div class="min-w-0">
                        <p class="truncate text-[12.5px] font-bold text-on-surface">{{ m.name }}</p>
                        <p class="truncate text-[11px] text-on-surface-variant">{{ m.position || '—' }}</p>
                    </div>
                </div>
            </div>
            <p v-else class="py-8 text-center text-[13px] text-on-surface-variant">
                No active members recorded for this department yet.
            </p>
        </div>

        <!-- Cross-links -->
        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Link :href="route('employees.index')" class="card-lift flex flex-col items-center gap-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest px-5 py-4 text-center hover:border-secondary/30 transition-colors">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary/10 text-secondary">
                    <span class="material-symbols-outlined text-[19px]">badge</span>
                </span>
                <span class="text-[11.5px] font-black text-on-surface">Department Roster</span>
            </Link>
            <Link :href="route('tickets.index')" class="card-lift flex flex-col items-center gap-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest px-5 py-4 text-center hover:border-secondary/30 transition-colors">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600">
                    <span class="material-symbols-outlined text-[19px]">support_agent</span>
                </span>
                <span class="text-[11.5px] font-black text-on-surface">Service Desk</span>
            </Link>
            <Link :href="route('reports.index')" class="card-lift flex flex-col items-center gap-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest px-5 py-4 text-center hover:border-secondary/30 transition-colors">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl" style="background:rgba(255,215,0,0.14);color:#b88a08">
                    <span class="material-symbols-outlined text-[19px]" style="font-variation-settings:'FILL' 1">assessment</span>
                </span>
                <span class="text-[11.5px] font-black text-on-surface">Reports</span>
            </Link>
            <button @click="comingSoon(title + ' settings')" type="button"
                    class="card-lift flex flex-col items-center gap-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest px-5 py-4 text-center hover:border-secondary/30 transition-colors">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-500/10 text-slate-600 dark:bg-slate-400/10 dark:text-slate-300">
                    <span class="material-symbols-outlined text-[19px]">tune</span>
                </span>
                <span class="text-[11.5px] font-black text-on-surface">Configure</span>
            </button>
        </div>
    </div>
</template>

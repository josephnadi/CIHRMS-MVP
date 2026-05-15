<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useToast } from '@/composables/useToast';

defineProps({
    activeModule: { type: String, default: 'benefits' },
});

const { comingSoon } = useToast();
</script>

<template>
    <Head title="Benefits &amp; Welfare — CIHRMS" />

    <AuthenticatedLayout :activeModule="activeModule">

        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-[22px] font-black tracking-tight text-on-surface">Benefits &amp; Welfare</h1>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">Your active institutional coverage and well-being programmes.</p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 dark:border-emerald-800/40 bg-emerald-50 dark:bg-emerald-900/20 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                Active Coverage
            </span>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Health Insurance -->
            <div class="lg:col-span-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                <div class="mb-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-[15px] font-bold text-on-surface">Health Insurance &amp; Medical</h3>
                        <p class="text-[12px] text-on-surface-variant/70">Premium Executive tier · Nationwide Medical</p>
                    </div>
                    <span class="inline-flex items-center gap-1 rounded-full bg-secondary/10 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider text-secondary">
                        <span class="material-symbols-outlined text-[12px]">medical_services</span>
                        Tier 1
                    </span>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-low p-5">
                        <p class="mb-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/55">Plan Details</p>
                        <dl class="space-y-2.5 text-[12.5px]">
                            <div class="flex justify-between"><dt class="text-on-surface-variant">Provider</dt><dd class="font-semibold text-on-surface">Nationwide Medical</dd></div>
                            <div class="flex justify-between"><dt class="text-on-surface-variant">Policy</dt><dd class="font-mono font-bold text-on-surface">NW-CIHRM-2026</dd></div>
                            <div class="flex justify-between"><dt class="text-on-surface-variant">Tier</dt><dd class="font-semibold text-secondary">Premium Executive</dd></div>
                            <div class="flex justify-between"><dt class="text-on-surface-variant">Renewal</dt><dd class="font-semibold text-on-surface">31 Dec 2026</dd></div>
                        </dl>
                    </div>
                    <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-low p-5">
                        <p class="mb-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/55">Coverage</p>
                        <ul class="space-y-2 text-[12.5px]">
                            <li v-for="item in [
                                'In-patient (Full)',
                                'Out-patient (GHS 5,000)',
                                'Dental &amp; Optical',
                                'Maternity &amp; Newborn',
                                'Mental Wellness Programme',
                            ]" :key="item" class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-[15px] text-emerald-600">check_circle</span>
                                <span class="text-on-surface">{{ item }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <button @click="comingSoon('Insurance e-card download')" type="button"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[12.5px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-all"
                            style="background:linear-gradient(135deg,#0051d5,#316bf3)">
                        <span class="material-symbols-outlined text-[16px]">badge</span>
                        Download E-Card
                    </button>
                    <Link :href="route('tickets.index')"
                          class="flex items-center gap-2 rounded-xl border border-outline-variant px-4 py-2.5 text-[12.5px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                        <span class="material-symbols-outlined text-[16px]">request_quote</span>
                        Request Reimbursement
                    </Link>
                </div>
            </div>

            <!-- Provident fund -->
            <div class="rounded-2xl p-6 text-white shadow-xl"
                 style="background:linear-gradient(135deg,#0051d5,#316bf3);">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/60 mb-4">Tier 3 Provident Fund</p>
                <p class="text-[34px] font-black tabular-nums">GHS 42,150.80</p>
                <p class="mt-1 text-[10px] font-medium uppercase tracking-wider text-white/55">Accumulated balance</p>

                <div class="mt-6 space-y-4">
                    <div>
                        <div class="mb-1 flex items-center justify-between text-[11.5px] font-bold">
                            <span class="text-white/70">Monthly contribution</span>
                            <span>GHS 850.00</span>
                        </div>
                        <div class="h-1.5 w-full rounded-full bg-white/15 overflow-hidden">
                            <div class="h-full w-[45%] bg-white rounded-full"></div>
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 flex items-center justify-between text-[11.5px] font-bold">
                            <span class="text-white/70">Vesting</span>
                            <span>78%</span>
                        </div>
                        <div class="h-1.5 w-full rounded-full bg-white/15 overflow-hidden">
                            <div class="h-full w-[78%] bg-white/80 rounded-full"></div>
                        </div>
                    </div>
                </div>

                <button @click="comingSoon('Provident fund investment options')" type="button"
                        class="mt-6 w-full rounded-xl border border-white/15 bg-white/10 py-2.5 text-[12.5px] font-black text-white hover:bg-white/20 transition-colors">
                    Investment Options
                </button>
            </div>

            <!-- Wellness programmes -->
            <div class="lg:col-span-3 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-6 shadow-card">
                <h3 class="mb-4 text-[15px] font-bold text-on-surface">Wellness Programmes</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="p in [
                        { title: 'EAP Counselling',     desc: 'Confidential mental-health support, 24/7.', icon: 'psychology',    color: '#7c3aed' },
                        { title: 'Annual Health Check', desc: 'Free executive health assessment.',         icon: 'health_metrics', color: '#0891b2' },
                        { title: 'Gym Subsidy',         desc: 'GHS 200/month wellness allowance.',         icon: 'fitness_center', color: '#059669' },
                        { title: 'Family Cover',        desc: 'Add up to 4 dependants on health plan.',    icon: 'family_restroom',color: '#dc2626' },
                    ]" :key="p.title"
                         class="card-lift rounded-2xl border border-outline-variant/40 bg-surface-container-low p-5">
                        <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl"
                             :style="`background:${p.color}18;border:1px solid ${p.color}30`">
                            <span class="material-symbols-outlined text-[19px]"
                                  :style="`color:${p.color};font-variation-settings:'FILL' 1`">{{ p.icon }}</span>
                        </div>
                        <p class="text-[13px] font-bold text-on-surface">{{ p.title }}</p>
                        <p class="mt-1 text-[11.5px] text-on-surface-variant/70 leading-relaxed">{{ p.desc }}</p>
                        <button @click="comingSoon(p.title)" type="button"
                                class="mt-3 text-[11px] font-black text-secondary hover:underline">Enrol →</button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

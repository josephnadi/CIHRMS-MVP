<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useToast } from '@/composables/useToast';

defineProps({
    activeModule: { type: String, default: 'governance' },
});

const { comingSoon } = useToast();

const checks = [
    { name: 'Labour Act 651 Compliance',          status: 'passed',     date: '2026-04-12', score: 'A+' },
    { name: 'Data Protection (Act 843)',          status: 'passed',     date: '2026-04-08', score: 'A'  },
    { name: 'SSNIT Statutory Remittance',         status: 'passed',     date: '2026-04-15', score: 'A'  },
    { name: 'Internal Policy Review (Q2)',        status: 'in_review',  date: 'ongoing',    score: '—'  },
    { name: 'Whistleblower Channel Audit',        status: 'passed',     date: '2026-03-29', score: 'A'  },
    { name: 'Records Retention Schedule',         status: 'attention',  date: '2026-04-01', score: 'B+' },
    { name: 'Anti-bribery Training (annual)',     status: 'passed',     date: '2026-02-22', score: 'A+' },
];

const statusMeta = {
    passed:    { label: 'Passed',    color: '#059669', bg: 'bg-green-50  dark:bg-green-900/20',  text: 'text-green-700  dark:text-green-400'  },
    in_review: { label: 'In Review', color: '#d97706', bg: 'bg-amber-50  dark:bg-amber-900/20',  text: 'text-amber-700  dark:text-amber-400'  },
    attention: { label: 'Attention', color: '#dc2626', bg: 'bg-red-50    dark:bg-red-900/20',    text: 'text-red-700    dark:text-red-400'    },
};

const stats = [
    { label: 'Compliance Grade', val: 'A+',    sub: 'institutional',  rgb: '5,150,105',  icon: 'verified' },
    { label: 'Open Findings',    val: 1,       sub: 'requires action',rgb: '217,119,6',  icon: 'flag' },
    { label: 'Audits This Year', val: 7,       sub: 'completed',      rgb: '0,81,213',   icon: 'fact_check' },
    { label: 'Policy Updates',   val: 3,       sub: 'pending review', rgb: '124,92,255', icon: 'gavel' },
];
</script>

<template>
    <Head title="Governance &amp; Compliance — CIHRMS" />

    <AuthenticatedLayout :activeModule="activeModule">

        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-[22px] font-black tracking-tight text-on-surface">Governance &amp; Compliance</h1>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">Institutional audit, policy oversight, and statutory adherence.</p>
            </div>
            <div class="flex items-center gap-2 rounded-xl border border-emerald-200 dark:border-emerald-800/40 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-2">
                <span class="material-symbols-outlined text-[18px] text-emerald-600 dark:text-emerald-400" style="font-variation-settings:'FILL' 1">verified_user</span>
                <span class="text-[13px] font-bold text-emerald-700 dark:text-emerald-400">Grade A+ certified</span>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div v-for="(s, i) in stats" :key="i"
                class="card-lift relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl"
                         :style="`background:rgba(${s.rgb},0.12);border:1px solid rgba(${s.rgb},0.2)`">
                        <span class="material-symbols-outlined text-[18px]"
                              :style="`color:rgb(${s.rgb});font-variation-settings:'FILL' 1`">{{ s.icon }}</span>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/40">{{ s.sub }}</span>
                </div>
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">{{ s.label }}</p>
                <p class="mt-0.5 text-[26px] font-black tracking-tight text-on-surface">{{ s.val }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Compliance audit list -->
            <div class="lg:col-span-2 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                    <h3 class="text-[14px] font-bold text-on-surface">Compliance Audit Trail</h3>
                    <Link :href="route('audit-logs.index')"
                          class="text-[11px] font-bold text-secondary hover:underline">Full audit log →</Link>
                </div>
                <ul class="divide-y divide-outline-variant/30">
                    <li v-for="c in checks" :key="c.name" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-surface-container/40">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl border border-outline-variant/40 bg-surface-container-low">
                                <span class="material-symbols-outlined text-[18px] text-on-surface-variant">gavel</span>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[13.5px] font-bold text-on-surface truncate">{{ c.name }}</p>
                                <p class="text-[11px] text-on-surface-variant/60">Last verified · {{ c.date }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                  :class="`${statusMeta[c.status].bg} ${statusMeta[c.status].text}`">
                                {{ statusMeta[c.status].label }}
                            </span>
                            <span class="rounded-md bg-surface-container-low px-2 py-0.5 font-mono text-[11px] font-bold text-on-surface">{{ c.score }}</span>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Risk + actions panel -->
            <div class="space-y-5">
                <div class="rounded-2xl p-5 text-white shadow-xl"
                     style="background:linear-gradient(135deg,#0051d5,#316bf3);">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/60 mb-3">Risk Snapshot</p>
                    <div class="space-y-3">
                        <div>
                            <p class="text-[12px] font-bold mb-1">Policy Updates Required</p>
                            <p class="text-[11px] text-white/70 leading-relaxed">Hybrid work policy needs alignment with the latest executive mandate.</p>
                            <button @click="comingSoon('Policy editor')" type="button"
                                    class="mt-2 text-[10.5px] font-black text-white hover:underline">Draft Update →</button>
                        </div>
                        <hr class="border-white/10" />
                        <div>
                            <p class="text-[12px] font-bold mb-1">Certification Expiry</p>
                            <p class="text-[11px] text-white/70 leading-relaxed">34 staff certifications expire in the next 48 hours.</p>
                            <button @click="comingSoon('Bulk certification reminder')" type="button"
                                    class="mt-2 text-[10.5px] font-black text-white hover:underline">Notify Staff →</button>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card">
                    <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/55 mb-3">Quick Links</p>
                    <div class="space-y-2">
                        <Link :href="route('audit-logs.index')" class="flex items-center gap-2 rounded-xl bg-surface-container-low px-3 py-2 text-[12.5px] font-bold text-on-surface hover:bg-surface-container/60 transition-colors">
                            <span class="material-symbols-outlined text-[16px] text-secondary">history</span>
                            Audit log
                        </Link>
                        <Link :href="route('reports.index')" class="flex items-center gap-2 rounded-xl bg-surface-container-low px-3 py-2 text-[12.5px] font-bold text-on-surface hover:bg-surface-container/60 transition-colors">
                            <span class="material-symbols-outlined text-[16px] text-secondary">download</span>
                            Compliance reports
                        </Link>
                        <Link :href="route('complaints.track')" class="flex items-center gap-2 rounded-xl bg-surface-container-low px-3 py-2 text-[12.5px] font-bold text-on-surface hover:bg-surface-container/60 transition-colors">
                            <span class="material-symbols-outlined text-[16px] text-secondary">forum</span>
                            Whistleblower channel
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    existing:     Array,
    current_year: Number,
    activeModule: String,
});

const form = useForm({
    year:         props.current_year,
    jurisdiction: 'GH',
});

const submit = () => form.post(route('ag-reports.generate'), {
    preserveScroll: true,
    onSuccess: () => { /* refresh list via Inertia */ },
});

const fmtSize = (b) => (b > 1024 * 1024) ? (b / 1024 / 1024).toFixed(2) + ' MB' : (b / 1024).toFixed(1) + ' KB';
const fmtDate = (unix) => new Date(unix * 1000).toLocaleString('en-GH');

// ── Editorial Sovereign masthead + sub-metric labels ─────────────
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

// Most recent pack (existing is pre-sorted newest first by the controller).
const latestPack = computed(() => (props.existing ?? [])[0] ?? null);

const lastPackLabel = computed(() => {
    if (! latestPack.value) return 'None';
    return new Date(latestPack.value.created * 1000).toLocaleDateString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
    });
});

const packCount = computed(() => (props.existing ?? []).length);

// Each pack ships one consolidated MANIFEST.md with one SHA-256 entry per
// bundled file. The pack count is a tamper-evident audit signature on its own.
const sealedBytes = computed(() => (props.existing ?? []).reduce((s, p) => s + (p.size ?? 0), 0));
const sealedSizeLabel = computed(() => {
    if (sealedBytes.value === 0) return '0 KB';
    return fmtSize(sealedBytes.value);
});

// Next AG pack is conventionally due on the close of the fiscal year (31 Dec).
const nextDueLabel = computed(() => {
    const due = new Date(props.current_year, 11, 31);
    return due.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
});
</script>

<template>
    <Head title="Auditor-General Report Pack" />
    <div data-page-root="true">

            <Teleport to="#page-header-mount" defer>
                <div class="space-y-2">
                    <nav class="flex items-center gap-1.5 text-[12px] font-semibold text-on-surface-variant/60" aria-label="Breadcrumb">
                        <Link :href="route('reports.index')" class="hover:text-secondary transition-colors">Reports</Link>
                        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                        <span class="text-on-surface" aria-current="page">Auditor-General</span>
                    </nav>
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">gavel</span>
                                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">AUDITOR-GENERAL PACK · ACT 921</p>
                            </div>
                            <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Auditor-General Report Pack</h1>
                            <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                                Year-end bundle — payroll, statutory returns, GhIPSS, Ghana Card register, audit-chain verification. SHA-256 sealed.
                            </p>
                        </div>
                        <div class="flex items-center gap-1.5 rounded-full border border-outline-variant/40 bg-surface-container-lowest px-3 py-1.5">
                            <span class="material-symbols-outlined text-[14px] text-secondary">verified</span>
                            <span class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant">{{ packCount }} sealed pack{{ packCount === 1 ? '' : 's' }}</span>
                        </div>
                    </div>
                </div>
            </Teleport>

            <div class="py-6 space-y-6">
                <!-- What's in the pack — gold hairline accent on top edge -->
                <div class="relative rounded-2xl bg-brand-navy/[0.04] border border-brand-navy/15 p-6 space-y-3 overflow-hidden">
                    <div class="pointer-events-none absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.55),transparent)"></div>
                    <p class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.18em] text-brand-navy/70">
                        <span class="flex h-5 w-5 items-center justify-center rounded-md" style="background:rgba(255,215,0,0.14)">
                            <span class="material-symbols-outlined text-[12px]" style="color:#b88a08;font-variation-settings:'FILL' 1">inventory_2</span>
                        </span>
                        What's bundled
                    </p>
                    <ul class="text-sm grid md:grid-cols-2 gap-2 text-on-surface-variant">
                        <li class="flex gap-2"><span class="material-symbols-outlined text-[16px] text-emerald-600">check_circle</span> All payroll runs (totals + per-employee lines)</li>
                        <li class="flex gap-2"><span class="material-symbols-outlined text-[16px] text-emerald-600">check_circle</span> Statutory returns (PAYE, SSNIT, NHIA, Tier-2)</li>
                        <li class="flex gap-2"><span class="material-symbols-outlined text-[16px] text-emerald-600">check_circle</span> GhIPSS bank disbursement files (verbatim)</li>
                        <li class="flex gap-2"><span class="material-symbols-outlined text-[16px] text-emerald-600">check_circle</span> Ghana Card verification register (masked)</li>
                        <li class="flex gap-2"><span class="material-symbols-outlined text-[16px] text-emerald-600">check_circle</span> Loan accounts + open balances at year-end</li>
                        <li class="flex gap-2"><span class="material-symbols-outlined text-[16px] text-emerald-600">check_circle</span> Off-boarding cases + final settlements</li>
                        <li class="flex gap-2"><span class="material-symbols-outlined text-[16px] text-emerald-600">check_circle</span> Audit-chain verification output</li>
                        <li class="flex gap-2"><span class="material-symbols-outlined text-[16px] text-amber-600">info</span> Whistleblower stats only (Act 720 segregation)</li>
                    </ul>
                    <p class="text-xs text-on-surface-variant/70 pt-2">
                        Every file is independently SHA-256 hashed in <code>MANIFEST.md</code> so the auditor can verify the pack has not been tampered with after generation.
                    </p>
                </div>

                <!-- Generate -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/40 p-6">
                    <h2 class="font-semibold text-base mb-3">Generate a new pack</h2>
                    <form @submit.prevent="submit" class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="block text-xs font-medium text-on-surface-variant mb-1">Fiscal year</label>
                            <input v-model.number="form.year" type="number" min="2000" max="2100"
                                   class="rounded-lg border-outline-variant text-sm w-32" required>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-on-surface-variant mb-1">Jurisdiction</label>
                            <select v-model="form.jurisdiction" aria-label="Jurisdiction" class="rounded-lg border-outline-variant text-sm">
                                <option value="GH">Ghana</option>
                            </select>
                        </div>
                        <PrimaryButton type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Generating…' : 'Generate pack (2FA required)' }}
                        </PrimaryButton>
                    </form>
                    <p class="text-xs text-on-surface-variant/60 mt-3">
                        Generation can take 30–120 seconds depending on payroll volume. The page will refresh when ready.
                    </p>
                </div>

                <!-- Existing packs -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/40">
                    <div class="px-5 py-4 border-b border-outline-variant/40">
                        <h2 class="font-semibold text-base">Existing packs</h2>
                    </div>
                    <table v-if="existing.length > 0" class="w-full text-sm">
                        <thead class="bg-surface-container-low/95 backdrop-blur-sm">
                            <tr>
                                <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">File</th>
                                <th class="px-5 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Size</th>
                                <th class="px-5 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Generated</th>
                                <th class="px-5 py-3 text-right text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="p in existing" :key="p.path" class="group transition-colors hover:bg-secondary/[0.04]">
                                <td class="px-5 py-3 font-mono text-[12px] text-on-surface truncate">{{ p.name }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-on-surface-variant">{{ fmtSize(p.size) }}</td>
                                <td class="px-5 py-3 text-on-surface-variant tabular-nums">{{ fmtDate(p.created) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a :href="route('ag-reports.download', { filename: p.name })"
                                       class="inline-flex items-center gap-1 rounded-lg border border-transparent px-2.5 py-1 text-[12px] font-bold text-secondary hover:bg-secondary/10 hover:border-secondary/20 transition-all">
                                        <span class="material-symbols-outlined text-[14px]">download</span>
                                        Download
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="px-5 py-10 text-center text-sm text-on-surface-variant/60">
                        No packs generated yet. Use the form above.
                    </div>
                </div>
            </div>
    </div>
</template>

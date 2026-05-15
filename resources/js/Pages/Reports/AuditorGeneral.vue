<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

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
</script>

<template>
    <Head title="Auditor-General Report Pack" />

    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div>
                <p class="text-xs text-on-surface-variant/60">Phase 2 · External audit readiness</p>
                <h1 class="text-2xl font-semibold tracking-tight">Auditor-General Report Pack</h1>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <!-- What's in the pack -->
            <div class="rounded-2xl bg-brand-navy/[0.04] border border-brand-navy/15 p-6 space-y-3">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">What's bundled</p>
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
                        <select v-model="form.jurisdiction" class="rounded-lg border-outline-variant text-sm">
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
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">File</th>
                            <th class="px-5 py-3 text-right">Size</th>
                            <th class="px-5 py-3 text-left">Generated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="p in existing" :key="p.path" class="hover:bg-surface-container-low/60">
                            <td class="px-5 py-3 font-mono text-xs">{{ p.name }}</td>
                            <td class="px-5 py-3 text-right">{{ fmtSize(p.size) }}</td>
                            <td class="px-5 py-3">{{ fmtDate(p.created) }}</td>
                            <td class="px-5 py-3 text-right">
                                <a :href="route('ag-reports.download', { filename: p.name })"
                                   class="text-secondary hover:underline">Download</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="px-5 py-10 text-center text-sm text-on-surface-variant/60">
                    No packs generated yet. Use the form above.
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
defineProps({ summary: { type: Array, default: () => [] }, unresolved: { type: Array, default: () => [] }, activeModule: String });
const ghs = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head title="Collections Reconciliation" />
    <div class="p-6 max-w-5xl mx-auto space-y-6">
        <header>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Finance</p>
            <h1 class="text-2xl font-black text-primary">Collections Reconciliation</h1>
            <p class="text-sm text-on-surface-variant mt-1">Website fee collections ingested into the ledger — collected vs posted, and anything unresolved.</p>
        </header>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[10px] uppercase bg-surface-container-low/20"><tr>
                    <th class="text-left p-3">Fee code</th><th class="text-right p-3">Collected</th>
                    <th class="text-right p-3">Posted</th><th class="text-right p-3">Unresolved</th>
                </tr></thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-if="!summary.length">
                        <td class="p-4 text-center text-on-surface-variant" colspan="4">No website collections ingested yet.</td>
                    </tr>
                    <tr v-for="s in summary" :key="s.fee_code">
                        <td class="p-3 font-bold text-primary">{{ s.fee_code }}</td>
                        <td class="p-3 text-right tabular-nums">{{ ghs(s.collected) }}</td>
                        <td class="p-3 text-right tabular-nums">{{ ghs(s.posted) }}</td>
                        <td class="p-3 text-right" :class="Number(s.unresolved_count) ? 'text-rose-600 font-bold' : 'text-on-surface-variant'">{{ s.unresolved_count }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="unresolved.length" class="rounded-2xl border border-rose-300/60 bg-rose-50/40 overflow-hidden">
            <div class="px-5 py-3 border-b border-rose-200/60"><h2 class="text-sm font-black uppercase tracking-wide text-rose-700">Unresolved — accountant worklist</h2></div>
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[10px] uppercase"><tr>
                    <th class="text-left p-3">Ref</th><th class="text-left p-3">Fee code</th><th class="text-right p-3">Amount</th>
                    <th class="text-left p-3">Status</th><th class="text-left p-3">Note</th>
                </tr></thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="u in unresolved" :key="u.id">
                        <td class="p-3 font-mono text-xs">{{ u.external_ref }}</td>
                        <td class="p-3">{{ u.fee_code }}</td>
                        <td class="p-3 text-right tabular-nums">{{ ghs(u.amount) }}</td>
                        <td class="p-3 capitalize">{{ u.status }}</td>
                        <td class="p-3 text-xs text-on-surface-variant">{{ u.status_note }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

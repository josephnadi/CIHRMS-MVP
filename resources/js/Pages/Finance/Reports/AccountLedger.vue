<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ account: { type: Object, required: true }, from: String, to: String, lines: { type: Array, default: () => [] } });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>
<template>
    <Head :title="`Ledger — ${account.code}`" />
    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6"><h1 class="text-2xl font-black text-primary">{{ account.code }} · {{ account.name }}</h1>
            <p class="text-on-surface-variant text-sm mt-1">Posted lines{{ from ? ' from ' + from : '' }} to {{ to }}</p></header>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[11px] uppercase border-b border-outline-variant/40"><tr><th class="text-left p-3">Date</th><th class="text-left p-3">Reference</th><th class="text-left p-3">Narration</th><th class="text-right p-3">Debit</th><th class="text-right p-3">Credit</th></tr></thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="(l, i) in lines" :key="i"><td class="p-3 text-on-surface-variant">{{ l.entry_date }}</td><td class="p-3 font-mono text-primary">{{ l.reference }}</td><td class="p-3 text-primary">{{ l.narration }}</td><td class="p-3 text-right text-primary">{{ l.debit ? money(l.debit) : '' }}</td><td class="p-3 text-right text-primary">{{ l.credit ? money(l.credit) : '' }}</td></tr>
                    <tr v-if="lines.length === 0"><td colspan="5" class="p-6 text-center text-on-surface-variant">No posted lines in this window.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

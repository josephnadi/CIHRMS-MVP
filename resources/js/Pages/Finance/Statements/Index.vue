<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    customers:           { type: Array,  default: () => [] },
    statement:           { type: Object, default: () => null },
    selectedCustomerId:  { type: Number, default: null },
});

const today = new Date().toISOString().slice(0, 10);
const defaultFromIso = (() => {
    const d = new Date();
    d.setMonth(d.getMonth() - 2);
    d.setDate(1);
    return d.toISOString().slice(0, 10);
})();

const customerId = ref(props.selectedCustomerId ?? '');
const from = ref(props.statement?.period?.from ?? defaultFromIso);
const to   = ref(props.statement?.period?.to   ?? today);

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const generate = () => {
    if (!customerId.value) return;
    router.get(route('finance.statements.show', customerId.value), {
        from: from.value, to: to.value,
    }, { preserveState: true });
};

const printStatement = () => window.print();

const s = computed(() => props.statement);
</script>

<template>
    <Head :title="`Statement${s ? ' — ' + s.customer.name : ''}`" />

    <div class="space-y-6 animate-reveal-up print:space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-4 print:hidden">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — ACCOUNTS RECEIVABLE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Customer Statements</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">Date-range view with running balance and aging summary.</p>
            </div>
            <button v-if="s" @click="printStatement"
                    class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container-low">
                <span class="material-symbols-outlined text-[14px] align-middle mr-1">print</span>Print
            </button>
        </div>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 print:hidden">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="md:col-span-2">
                    <label for="customer" class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Customer</label>
                    <select id="customer" v-model="customerId" aria-label="Customer"
                            class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option value="">— Select customer —</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label for="from" class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">From</label>
                    <input id="from" v-model="from" type="date" aria-label="From date"
                           class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                </div>
                <div>
                    <label for="to" class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">To</label>
                    <input id="to" v-model="to" type="date" aria-label="To date"
                           class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                </div>
            </div>
            <div class="mt-3 flex justify-end">
                <button @click="generate" :disabled="!customerId"
                        class="rounded-xl bg-primary px-4 py-2 text-[13px] font-black text-on-primary disabled:opacity-50 disabled:cursor-not-allowed">
                    Generate
                </button>
            </div>
        </div>

        <div v-if="s" class="space-y-5">
            <!-- Customer header (also visible on print) -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <div class="flex items-baseline justify-between">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-secondary/80">Statement of Account</p>
                        <h2 class="text-[1.4rem] font-black text-primary mt-1">{{ s.customer.name }}</h2>
                        <p class="text-[12px] font-mono text-on-surface-variant">{{ s.customer.code }}</p>
                    </div>
                    <div class="text-right text-[12px] text-on-surface-variant">
                        <p>Period: <span class="font-mono">{{ s.period.from }}</span> to <span class="font-mono">{{ s.period.to }}</span></p>
                        <p>Issued: <span class="font-mono">{{ today }}</span></p>
                    </div>
                </div>
            </div>

            <!-- Aging summary -->
            <div class="grid gap-3 grid-cols-2 md:grid-cols-4">
                <div v-for="(label, key) in { current: 'Current', '30': '1–30 days', '60': '31–60 days', '90_plus': '61+ days' }" :key="key"
                     class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
                    <p class="text-[9px] font-black uppercase tracking-widest text-on-surface-variant">{{ label }}</p>
                    <p :class="['mt-1 text-[16px] font-black font-mono', key === '90_plus' && s.aging[key] > 0 ? 'text-rose-700' : 'text-primary']">
                        {{ cedi(s.aging[key]) }}
                    </p>
                </div>
            </div>

            <!-- Transactions table -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <table class="w-full text-[12px]">
                    <thead class="bg-surface-container border-b border-outline-variant/40">
                        <tr class="text-left">
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Date</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reference</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Type</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Description</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Debit</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Credit</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-t border-outline-variant/30 bg-surface-container/30">
                            <td colspan="6" class="px-4 py-2 font-bold uppercase text-[10px] tracking-wider text-on-surface-variant">Opening balance</td>
                            <td class="px-4 py-2 text-right font-mono font-black text-primary">{{ cedi(s.opening_balance) }}</td>
                        </tr>
                        <tr v-for="(line, i) in s.lines" :key="i" class="border-t border-outline-variant/30">
                            <td class="px-4 py-2 font-mono text-on-surface-variant">{{ line.date }}</td>
                            <td class="px-4 py-2 font-mono font-bold">{{ line.reference }}</td>
                            <td class="px-4 py-2 capitalize">{{ line.type }}</td>
                            <td class="px-4 py-2 text-on-surface-variant">{{ line.description }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ line.debit > 0 ? cedi(line.debit) : '—' }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ line.credit > 0 ? cedi(line.credit) : '—' }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ cedi(line.running_balance) }}</td>
                        </tr>
                        <tr v-if="!s.lines.length" class="border-t border-outline-variant/30">
                            <td colspan="7" class="px-4 py-6 text-center text-on-surface-variant italic">No activity in this period.</td>
                        </tr>
                        <tr class="border-t-2 border-primary bg-surface-container/50">
                            <td colspan="6" class="px-4 py-3 font-bold uppercase text-[10px] tracking-wider text-primary">Closing balance</td>
                            <td class="px-4 py-3 text-right font-mono font-black text-[14px] text-primary">{{ cedi(s.closing_balance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <EmptyState v-else-if="customerId" icon="description" title="Generate the statement" description="Pick a date range and click Generate." />
        <EmptyState v-else icon="description" title="Customer Statements"
                    description="Select a customer to generate a date-range statement with running balance and aging summary." />
    </div>
</template>

<style>
@media print {
    /* Hide everything outside this view when printing */
    body * { visibility: hidden; }
    .print-area, .print-area * { visibility: visible; }
}
</style>

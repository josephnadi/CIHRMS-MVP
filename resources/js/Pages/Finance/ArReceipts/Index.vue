<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    receipts:     { type: Object, required: true },
    filters:      { type: Object, default: () => ({}) },
    customers:    { type: Array,  default: () => [] },
    openInvoices: { type: Array,  default: () => [] },
    bankAccounts: { type: Array,  default: () => [] },
});

const page = usePage();
const canReceive = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('ar_invoices.receive');
});

const rows = computed(() => props.receipts.data ?? props.receipts ?? []);
const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const panelOpen = ref(false);
const form = useForm({
    customer_id: null, receipt_date: new Date().toISOString().slice(0, 10), amount: 0,
    org_bank_account_id: null, currency: 'GHS', external_ref: '', narration: '',
    allocations: [],
});

const candidates = computed(() => props.openInvoices.filter(inv => inv.customer_id === form.customer_id));

watch(() => form.customer_id, () => {
    form.allocations = [];
    // Pre-fill preferred bank if set on the customer
    const c = props.customers.find(x => x.id === form.customer_id);
    if (c?.default_bank_account_id && !form.org_bank_account_id) {
        form.org_bank_account_id = c.default_bank_account_id;
    }
});

const addAllocation = (invoiceId) => {
    if (form.allocations.find(a => a.ar_invoice_id === invoiceId)) return;
    const inv = props.openInvoices.find(i => i.id === invoiceId);
    const remaining = (Number(inv.total) - Number(inv.amount_received)).toFixed(2);
    form.allocations.push({ ar_invoice_id: invoiceId, allocated_amount: Number(remaining) });
};

const removeAllocation = (i) => form.allocations.splice(i, 1);

const allocSum = computed(() => form.allocations.reduce((s, a) => s + (Number(a.allocated_amount) || 0), 0));

const submit = () => form.post(route('finance.ar-receipts.store'), { onSuccess: () => panelOpen.value = false });

const voidReceipt = (r) => {
    const reason = prompt('Reason for voiding?');
    if (!reason) return;
    router.post(route('finance.ar-receipts.void', r.id), { reason });
};

const statusColor = (val) => ({
    pending:   'text-amber-700 bg-amber-50 border-amber-100',
    processed: 'text-emerald-700 bg-emerald-50 border-emerald-100',
    voided:    'text-rose-700 bg-rose-50 border-rose-100',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="AR Receipts" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — ACCOUNTS RECEIVABLE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">AR Receipts</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} receipt{{ rows.length === 1 ? '' : 's' }} · journal posts atomically with each record.</p>
            </div>
            <PrimaryButton v-if="canReceive" @click="panelOpen = true">
                <span class="material-symbols-outlined text-[16px] mr-1">savings</span>Record Receipt
            </PrimaryButton>
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reference</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Customer</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Date</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Amount</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Bank</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">External ref</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" class="border-t border-outline-variant/30 hover:bg-surface-container/40">
                        <td class="px-4 py-2 font-mono font-bold text-primary">{{ r.reference }}</td>
                        <td class="px-4 py-2 text-on-surface">{{ r.customer?.code }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ r.receipt_date }}</td>
                        <td class="px-4 py-2 text-right font-mono text-primary">{{ cedi(r.amount) }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ r.bank_account?.bank_name }}</td>
                        <td class="px-4 py-2 text-on-surface-variant font-mono">{{ r.external_ref ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(r.status.value)">{{ r.status.label }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button v-if="canReceive && r.status.value === 'processed'" @click="voidReceipt(r)"
                                    class="text-[11px] font-bold text-rose-600 hover:underline">Void</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="savings" title="No receipts yet" description="Record a receipt to settle one or more open AR invoices." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false" title="Record AR Receipt">
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="customer_id" value="Customer" />
                        <select id="customer_id" v-model="form.customer_id" aria-label="Customer"
                                class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                            <option :value="null">—</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <InputError :message="form.errors.customer_id" />
                    </div>
                    <div>
                        <InputLabel for="receipt_date" value="Receipt date" />
                        <input id="receipt_date" v-model="form.receipt_date" type="date" aria-label="Receipt date" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="amount" value="Amount (GHS)" />
                        <input id="amount" v-model.number="form.amount" type="number" step="0.01" min="0.01" aria-label="Receipt amount"
                               class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                        <InputError :message="form.errors.amount" />
                    </div>
                    <div>
                        <InputLabel for="org_bank_account_id" value="Receiving bank" />
                        <select id="org_bank_account_id" v-model="form.org_bank_account_id" aria-label="Receiving bank"
                                class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                            <option :value="null">—</option>
                            <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} — {{ b.account_name }}</option>
                        </select>
                        <InputError :message="form.errors.org_bank_account_id" />
                    </div>
                </div>
                <div>
                    <InputLabel for="external_ref" value="External reference (bank / MoMo transaction id)" />
                    <TextInput id="external_ref" v-model="form.external_ref" class="mt-1 block w-full" />
                </div>

                <div>
                    <div class="flex justify-between items-center mb-2">
                        <p class="text-[12px] font-black uppercase tracking-wider text-on-surface-variant">Allocate to invoices</p>
                        <p class="text-[10px] text-on-surface-variant" :class="Math.abs(allocSum - form.amount) > 0.005 ? 'text-rose-600 font-bold' : ''">
                            Allocated: {{ cedi(allocSum) }} / Amount: {{ cedi(form.amount) }}
                        </p>
                    </div>
                    <div v-if="!form.customer_id" class="text-[11px] text-on-surface-variant italic">Select a customer first.</div>
                    <div v-else-if="!candidates.length" class="text-[11px] text-on-surface-variant italic">This customer has no open invoices.</div>
                    <div v-else class="space-y-2">
                        <div v-for="inv in candidates" :key="inv.id" class="flex items-center gap-2 rounded-lg border border-outline-variant/40 p-2 text-[11px]">
                            <input type="checkbox" :id="`inv-${inv.id}`"
                                   :checked="form.allocations.find(a => a.ar_invoice_id === inv.id)"
                                   @change="e => e.target.checked ? addAllocation(inv.id) : removeAllocation(form.allocations.findIndex(a => a.ar_invoice_id === inv.id))" />
                            <label :for="`inv-${inv.id}`" class="flex-1 font-mono">{{ inv.reference }}</label>
                            <span class="text-on-surface-variant">outstanding {{ cedi(inv.total - inv.amount_received) }}</span>
                            <input aria-label="Allocated amount" v-if="form.allocations.find(a => a.ar_invoice_id === inv.id)" v-model.number="form.allocations.find(a => a.ar_invoice_id === inv.id).allocated_amount" type="number" step="0.01" min="0.01" class="w-28 rounded-md border border-outline-variant bg-surface-container-lowest px-2 py-1 text-[11px]" />
                        </div>
                    </div>
                    <InputError :message="form.errors.allocations" />
                </div>

                <div>
                    <InputLabel for="narration" value="Narration" />
                    <textarea id="narration" v-model="form.narration" rows="2" aria-label="Narration" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing || Math.abs(allocSum - form.amount) > 0.005 || !form.allocations.length">
                        Record
                    </PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import GlossaryText from '@/Components/GlossaryText.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    payments:     { type: Object, required: true },
    filters:      { type: Object, default: () => ({}) },
    vendors:      { type: Array,  default: () => [] },
    openInvoices: { type: Array,  default: () => [] },
    bankAccounts: { type: Array,  default: () => [] },
});

const page = usePage();
const canPay = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('ap_invoices.pay');
});

const rows = computed(() => props.payments.data ?? props.payments ?? []);
const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const panelOpen = ref(false);
const form = useForm({
    vendor_id: null, payment_date: new Date().toISOString().slice(0, 10), amount: 0,
    org_bank_account_id: null, currency: 'GHS', narration: '',
    allocations: [],
});

const candidates = computed(() => props.openInvoices.filter(inv => inv.vendor_id === form.vendor_id));

watch(() => form.vendor_id, () => { form.allocations = []; });

const addAllocation = (invoiceId) => {
    if (form.allocations.find(a => a.vendor_invoice_id === invoiceId)) return;
    const inv = props.openInvoices.find(i => i.id === invoiceId);
    const remaining = (Number(inv.total) - Number(inv.amount_paid)).toFixed(2);
    form.allocations.push({ vendor_invoice_id: invoiceId, allocated_amount: Number(remaining) });
};

const removeAllocation = (i) => form.allocations.splice(i, 1);

const allocSum = computed(() => form.allocations.reduce((s, a) => s + (Number(a.allocated_amount) || 0), 0));

const openNew = () => {
    form.reset();
    form.clearErrors();
    panelOpen.value = true;
};

const submit = () => form.post(route('finance.ap-payments.store'), {
    onSuccess: () => { panelOpen.value = false; form.reset(); },
});

const voidPayment = (p) => {
    const reason = prompt('Reason for voiding?');
    if (!reason) return;
    router.post(route('finance.ap-payments.void', p.id), { reason });
};

const statusColor = (val) => ({
    pending: 'text-amber-700 bg-amber-50 border-amber-100',
    processed: 'text-emerald-700 bg-emerald-50 border-emerald-100',
    voided: 'text-rose-700 bg-rose-50 border-rose-100',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="AP Payments" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — ACCOUNTS PAYABLE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight"><GlossaryText text="AP Payments" /></h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} payments · journal posts atomically with each record.</p>
            </div>
            <PrimaryButton v-if="canPay" @click="openNew">
                <span class="material-symbols-outlined text-[16px] mr-1">payments</span>Record Payment
            </PrimaryButton>
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reference</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Vendor</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Date</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Amount</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">From</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in rows" :key="p.id" class="border-t border-outline-variant/30">
                        <td class="px-4 py-2 font-mono font-bold text-primary">{{ p.reference }}</td>
                        <td class="px-4 py-2 text-on-surface">{{ p.vendor?.code }} — {{ p.vendor?.name }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ p.payment_date }}</td>
                        <td class="px-4 py-2 text-right font-mono text-primary">{{ cedi(p.amount) }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ p.bank_account?.bank_name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(p.status.value)">{{ p.status.label }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button v-if="canPay && p.status.value === 'processed'" @click="voidPayment(p)" class="text-[11px] font-bold text-rose-600 hover:underline">Void</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="payments" title="No payments yet" description="Record a payment to settle approved invoices." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false" title="Record AP Payment">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="vendor_id" value="Vendor" />
                    <select id="vendor_id" v-model="form.vendor_id" aria-label="Vendor" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.code }} — {{ v.name }}</option>
                    </select>
                    <InputError :message="form.errors.vendor_id" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="payment_date" value="Payment date" />
                        <input id="payment_date" v-model="form.payment_date" type="date" aria-label="Payment date" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                        <InputError :message="form.errors.payment_date" />
                    </div>
                    <div>
                        <InputLabel for="amount" value="Amount (GHS)" />
                        <input id="amount" v-model.number="form.amount" type="number" step="0.01" aria-label="Amount" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                        <InputError :message="form.errors.amount" />
                    </div>
                </div>
                <div>
                    <InputLabel for="org_bank_account_id" value="Source bank account" />
                    <select id="org_bank_account_id" v-model="form.org_bank_account_id" aria-label="Source bank account" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} — {{ b.account_name }}</option>
                    </select>
                    <InputError :message="form.errors.org_bank_account_id" />
                </div>

                <div v-if="form.vendor_id">
                    <p class="text-[12px] font-black uppercase tracking-wider text-on-surface-variant mb-2">Allocate to invoices</p>
                    <div class="space-y-2 max-h-48 overflow-y-auto">
                        <div v-for="inv in candidates" :key="inv.id" class="flex items-center justify-between rounded-lg border border-outline-variant/40 p-2 text-[11px]">
                            <span class="font-mono">{{ inv.reference }} · {{ cedi(Number(inv.total) - Number(inv.amount_paid)) }} outstanding</span>
                            <button type="button" @click="addAllocation(inv.id)" class="text-secondary font-bold hover:underline">+ Add</button>
                        </div>
                        <p v-if="!candidates.length" class="text-[11px] text-on-surface-variant">No open invoices for this vendor.</p>
                    </div>

                    <div v-if="form.allocations.length" class="mt-2 space-y-1.5">
                        <div v-for="(a, i) in form.allocations" :key="a.vendor_invoice_id" class="flex items-center gap-2 text-[11px]">
                            <span class="font-mono flex-1">{{ openInvoices.find(x => x.id === a.vendor_invoice_id)?.reference }}</span>
                            <input v-model.number="a.allocated_amount" type="number" step="0.01" aria-label="Allocated amount" class="w-28 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1 text-[11px]" />
                            <button type="button" @click="removeAllocation(i)" aria-label="Remove allocation" class="text-rose-600 font-bold">×</button>
                        </div>
                        <p class="text-[11px] text-on-surface-variant mt-1">Allocated: <span class="font-mono">{{ cedi(allocSum) }}</span> of <span class="font-mono">{{ cedi(form.amount) }}</span></p>
                    </div>
                    <InputError :message="form.errors.allocations" />
                </div>

                <div>
                    <InputLabel for="narration" value="Narration (optional)" />
                    <input id="narration" v-model="form.narration" type="text" aria-label="Narration" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    <InputError :message="form.errors.narration" />
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing || Math.abs(allocSum - form.amount) > 0.005">Record</PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>

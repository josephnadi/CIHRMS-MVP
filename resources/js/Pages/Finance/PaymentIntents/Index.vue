<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    intents:      { type: Object, required: true },
    filters:      { type: Object, default: () => ({}) },
    customers:    { type: Array,  default: () => [] },
    openInvoices: { type: Array,  default: () => [] },
    focusIntent:  { type: [Object, null], default: null },
});

const page = usePage();
const canCreate = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('gateway.create');
});
const canRefund = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('gateway.refund');
});

const rows = computed(() => props.intents.data ?? props.intents ?? []);

const statusFilter = ref(props.filters.status ?? '');
const apply = () => router.get(route('finance.payment-intents.index'), {
    status: statusFilter.value || undefined,
}, { preserveState: true, replace: true });

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const panelOpen = ref(false);
const form = useForm({
    customer_id:   null,
    ar_invoice_id: null,
    amount:        0,
    narration:     '',
});

const candidates = computed(() => props.openInvoices.filter(inv => inv.customer_id === form.customer_id));

watch(() => form.customer_id, () => {
    form.ar_invoice_id = null;
    form.amount        = 0;
});

watch(() => form.ar_invoice_id, () => {
    const inv = props.openInvoices.find(x => x.id === form.ar_invoice_id);
    if (inv) form.amount = Number(inv.total) - Number(inv.amount_received);
});

const openNew = () => {
    form.reset();
    form.clearErrors();
    panelOpen.value = true;
};

const submit = () => form.post(route('finance.payment-intents.store'), {
    onSuccess: () => { panelOpen.value = false; form.reset(); },
});

const copyLink = (url) => {
    navigator.clipboard?.writeText(url);
};

// F4-R: refund modal
const refundModal = ref(null);
const refundForm = useForm({ reason: '' });

const openRefund = (intent) => { refundModal.value = intent; refundForm.reset(); };

// P3: bulk refund
const selectedIds = ref(new Set());
const refundableRows = computed(() => rows.value.filter(r => r.status?.value === 'success'));
const allRefundableSelected = computed(() =>
    refundableRows.value.length > 0
    && refundableRows.value.every(r => selectedIds.value.has(r.id)),
);
const toggleSelect = (id) => {
    const next = new Set(selectedIds.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selectedIds.value = next;
};
const toggleSelectAll = () => {
    selectedIds.value = allRefundableSelected.value
        ? new Set()
        : new Set(refundableRows.value.map(r => r.id));
};
const clearSelection = () => { selectedIds.value = new Set(); };

const bulkRefundOpen = ref(false);
const bulkRefundForm = useForm({ reason: '' });
const openBulkRefund = () => { bulkRefundForm.reset(); bulkRefundOpen.value = true; };
const submitBulkRefund = () => {
    bulkRefundForm
        .transform(data => ({ ...data, intent_ids: Array.from(selectedIds.value) }))
        .post(route('finance.payment-intents.bulk-refund'), {
            preserveScroll: true,
            onSuccess: () => { bulkRefundOpen.value = false; clearSelection(); bulkRefundForm.reset(); },
        });
};

const submitRefund = () => {
    if (! refundModal.value) return;
    refundForm.post(route('finance.payment-intents.refund', refundModal.value.id), {
        preserveScroll: true,
        onSuccess: () => { refundModal.value = null; refundForm.reset(); },
    });
};

const statusColor = (val) => ({
    created:   'text-on-surface-variant bg-surface-container border-outline-variant',
    pending:   'text-amber-700 bg-amber-50 border-amber-100',
    success:   'text-emerald-700 bg-emerald-50 border-emerald-100',
    failed:    'text-rose-700 bg-rose-50 border-rose-100',
    abandoned: 'text-slate-700 bg-slate-100 border-slate-200',
    expired:   'text-slate-500 bg-slate-50 border-slate-100',
}[val] ?? 'text-on-surface-variant');
</script>

<template>
    <Head title="Payment Links" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — PAYMENT GATEWAY</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Payment Links</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} intents · Paystack hosted checkout.</p>
            </div>
            <div class="flex items-center gap-2">
                <button v-if="canRefund && selectedIds.size > 0" @click="openBulkRefund"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-rose-700 text-white px-3 py-2 text-[12px] font-bold hover:bg-rose-800 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">currency_exchange</span>
                    Refund {{ selectedIds.size }} selected
                </button>
                <PrimaryButton v-if="canCreate" @click="openNew">
                    <span class="material-symbols-outlined text-[16px] mr-1">link</span>Send Payment Link
                </PrimaryButton>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 items-center">
            <button v-for="t in [
                { v: '',           label: 'All' },
                { v: 'pending',    label: 'Pending' },
                { v: 'success',    label: 'Success' },
                { v: 'failed',     label: 'Failed' },
                { v: 'abandoned',  label: 'Abandoned' },
                { v: 'expired',    label: 'Expired' },
            ]" :key="t.v" @click="statusFilter = t.v; apply();"
                :class="['px-3 py-1.5 rounded-full text-[11px] font-bold border transition-colors',
                    statusFilter === t.v ? 'bg-primary text-on-primary border-primary'
                                         : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:border-secondary/40']">
                {{ t.label }}
            </button>
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th v-if="canRefund" class="px-3 py-2.5 w-10">
                            <input type="checkbox" :checked="allRefundableSelected" @change="toggleSelectAll"
                                   :disabled="refundableRows.length === 0"
                                   aria-label="Select all refundable" class="accent-secondary" />
                        </th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reference</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Customer</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Invoice</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Amount</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="intent in rows" :key="intent.id" class="border-t border-outline-variant/30">
                        <td v-if="canRefund" class="px-3 py-2 w-10">
                            <input v-if="intent.status?.value === 'success'" type="checkbox"
                                   :checked="selectedIds.has(intent.id)" @change="toggleSelect(intent.id)"
                                   :aria-label="`Select ${intent.reference}`" class="accent-secondary" />
                        </td>
                        <td class="px-4 py-2 font-mono font-bold text-primary">{{ intent.reference }}</td>
                        <td class="px-4 py-2 text-on-surface">{{ intent.customer?.code }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ intent.invoice?.reference ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono text-primary">{{ cedi(intent.amount) }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(intent.status.value)">{{ intent.status.label }}</span>
                        </td>
                        <td class="px-4 py-2 text-right space-x-2">
                            <button v-if="intent.authorization_url && intent.status.value === 'pending'"
                                    @click="copyLink(intent.authorization_url)"
                                    class="text-[11px] font-bold text-secondary hover:underline">Copy link</button>
                            <button v-if="canRefund && intent.status.value === 'success'"
                                    @click="openRefund(intent)"
                                    class="text-[11px] font-bold text-rose-700 hover:underline">Refund</button>
                            <span v-if="intent.status.value === 'refunded'"
                                  class="text-[10px] font-bold text-violet-700">Refunded</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="link" title="No payment links yet" description="Generate a payment link for an approved or partially-paid AR invoice." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false" title="Generate Payment Link">
            <form @submit.prevent="submit" class="space-y-4">
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
                    <InputLabel for="ar_invoice_id" value="Invoice" />
                    <select id="ar_invoice_id" v-model="form.ar_invoice_id" aria-label="Invoice"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="inv in candidates" :key="inv.id" :value="inv.id">
                            {{ inv.reference }} · {{ cedi(Number(inv.total) - Number(inv.amount_received)) }} outstanding
                        </option>
                    </select>
                    <InputError :message="form.errors.ar_invoice_id" />
                </div>
                <div>
                    <InputLabel for="amount" value="Amount (GHS)" />
                    <input id="amount" v-model.number="form.amount" type="number" step="0.01" min="0.01" aria-label="Amount"
                           class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    <InputError :message="form.errors.amount" />
                </div>
                <div>
                    <InputLabel for="narration" value="Narration (optional)" />
                    <input id="narration" v-model="form.narration" type="text" aria-label="Narration"
                           class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing || !form.ar_invoice_id">Generate</PrimaryButton>
                </div>
            </form>
        </SlidePanel>

        <!-- P3: Bulk refund modal -->
        <div v-if="bulkRefundOpen" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center">
            <div class="bg-surface-container-lowest rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-[14px] font-black text-primary mb-1">Refund {{ selectedIds.size }} payment links</h3>
                <p class="text-[11px] text-on-surface-variant mb-4">
                    The same reason will be sent to Paystack for each selected intent. Linked AR receipts will be voided.
                </p>
                <p class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 mb-3">
                    Cannot be undone. Per-intent failures are reported but do not roll back successful refunds.
                </p>
                <form @submit.prevent="submitBulkRefund" class="space-y-3">
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Reason (visible to Paystack support)</label>
                        <textarea aria-label="Reason (visible to Paystack support)" v-model="bulkRefundForm.reason" rows="3"
                                  class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                        <InputError :message="bulkRefundForm.errors.reason" />
                        <InputError :message="bulkRefundForm.errors.bulk_refund" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="bulkRefundOpen = false"
                                class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                        <button type="submit" :disabled="bulkRefundForm.processing"
                                class="rounded-xl bg-rose-700 text-white px-3 py-2 text-[12px] font-bold disabled:opacity-50">Confirm bulk refund</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- F4-R: Refund modal -->
        <div v-if="refundModal" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center">
            <div class="bg-surface-container-lowest rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-[14px] font-black text-primary mb-1">Refund Payment Link</h3>
                <p class="text-[11px] text-on-surface-variant mb-4">{{ refundModal.reference }} · {{ cedi(refundModal.amount) }}</p>
                <p class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 mb-3">
                    This calls Paystack /refund and immediately voids the linked AR receipt. Cannot be undone.
                </p>
                <form @submit.prevent="submitRefund" class="space-y-3">
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Reason (visible to Paystack support)</label>
                        <textarea aria-label="Reason (visible to Paystack support)" v-model="refundForm.reason" rows="3"
                                  class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                        <InputError :message="refundForm.errors.reason" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="refundModal = null"
                                class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                        <button type="submit" :disabled="refundForm.processing"
                                class="rounded-xl bg-rose-700 text-white px-3 py-2 text-[12px] font-bold disabled:opacity-50">Confirm refund</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

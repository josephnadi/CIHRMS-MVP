<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    invoices:        { type: Object, required: true },
    filters:         { type: Object, default: () => ({}) },
    customers:       { type: Array,  default: () => [] },
    incomeAccounts:  { type: Array,  default: () => [] },
});

const page = usePage();
const perms = computed(() => {
    const p = page.props?.auth?.permissions ?? [];
    return Array.isArray(p) ? p : (typeof p === 'function' ? p() : []);
});
const canCreate = computed(() => perms.value.includes('ar_invoices.create'));
const canWriteOff = computed(() => perms.value.includes('ar_invoices.write_off'));

const rows = computed(() => props.invoices.data ?? props.invoices ?? []);
const statusFilter   = ref(props.filters.status ?? '');
const customerFilter = ref(props.filters.customer_id ?? '');
const searchTerm     = ref(props.filters.search ?? '');

const apply = () => router.get(route('finance.ar-invoices.index'), {
    status:      statusFilter.value || undefined,
    customer_id: customerFilter.value || undefined,
    search:      searchTerm.value || undefined,
}, { preserveState: true, replace: true });

let timer = null;
watch(searchTerm, () => { clearTimeout(timer); timer = setTimeout(apply, 320); });

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const panelOpen = ref(false);
// tax_rate is entered as a PERCENTAGE in the form (e.g. 12 = 12%); it is
// converted to the fraction the backend stores (0.12) on submit via transform().
const form = useForm({
    customer_id: null, customer_invoice_no: '', invoice_date: new Date().toISOString().slice(0,10),
    due_date: '', currency: 'GHS', notes: '',
    lines: [{ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null }],
});
form.transform((data) => ({
    ...data,
    lines: (data.lines ?? []).map((l) => ({ ...l, tax_rate: (Number(l.tax_rate) || 0) / 100 })),
}));

const showCreatedPrompt = ref(false);

const totals = computed(() => {
    const sub = form.lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
    const tax = form.lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0) * ((Number(l.tax_rate) || 0) / 100), 0);
    return { subtotal: sub, tax_amount: tax, total: sub + tax };
});

const addLine = () => form.lines.push({ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null });
const removeLine = (i) => { if (form.lines.length > 1) form.lines.splice(i, 1); };

const editingId = ref(null);

const openNew = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    Object.assign(form, {
        customer_id: null, customer_invoice_no: '', invoice_date: new Date().toISOString().slice(0,10),
        due_date: '', currency: 'GHS', notes: '',
        lines: [{ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null }],
    });
    panelOpen.value = true;
};

const openEdit = (inv) => {
    editingId.value = inv.id;
    form.clearErrors();
    Object.assign(form, {
        customer_id: inv.customer?.id ?? null,
        customer_invoice_no: inv.customer_invoice_no ?? '',
        invoice_date: inv.invoice_date,
        due_date: inv.due_date ?? '',
        currency: inv.currency ?? 'GHS',
        notes: inv.notes ?? '',
        lines: (inv.lines ?? []).map(l => ({
            description: l.description, quantity: Number(l.quantity), unit_price: Number(l.unit_price),
            tax_rate: Number(l.tax_rate) * 100, // stored as fraction → show as %
            gl_account_id: l.gl_account_id ?? l.gl_account?.id ?? null,
        })),
    });
    if (!form.lines.length) form.lines = [{ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null }];
    panelOpen.value = true;
};

const destroyDraft = (inv) => {
    if (!confirm(`Delete draft ${inv.reference}? Its accrual journal will be reversed.`)) return;
    router.delete(route('finance.ar-invoices.destroy', inv.id), { preserveScroll: true });
};

const onCustomerChange = () => {
    const c = props.customers.find(x => x.id === form.customer_id);
    if (c && c.default_income_gl_account_id) {
        form.lines.forEach(l => { if (!l.gl_account_id) l.gl_account_id = c.default_income_gl_account_id; });
    }
};

const submit = () => {
    if (editingId.value) {
        form.patch(route('finance.ar-invoices.update', editingId.value), {
            onSuccess: () => { panelOpen.value = false; editingId.value = null; },
        });
    } else {
        form.post(route('finance.ar-invoices.store'), {
            preserveScroll: true,
            onSuccess: () => { panelOpen.value = false; showCreatedPrompt.value = true; },
        });
    }
};

// ── Bulk create: one draft invoice per selected customer, all sharing lines ──
const bulkOpen = ref(false);
const bulkForm = useForm({
    customer_ids: [],
    invoice_date: new Date().toISOString().slice(0, 10),
    due_date: '', currency: 'GHS', notes: '',
    lines: [{ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null }],
});
bulkForm.transform((data) => ({
    ...data,
    lines: (data.lines ?? []).map((l) => ({ ...l, tax_rate: (Number(l.tax_rate) || 0) / 100 })),
}));

const bulkPerInvoice = computed(() => {
    const sub = bulkForm.lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
    const tax = bulkForm.lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0) * ((Number(l.tax_rate) || 0) / 100), 0);
    return { subtotal: sub, tax, total: sub + tax };
});
const allCustomersSelected = computed(() => props.customers.length > 0 && bulkForm.customer_ids.length === props.customers.length);

const toggleCustomer = (id) => {
    const i = bulkForm.customer_ids.indexOf(id);
    if (i === -1) bulkForm.customer_ids.push(id); else bulkForm.customer_ids.splice(i, 1);
};
const toggleAllCustomers = () => {
    bulkForm.customer_ids = allCustomersSelected.value ? [] : props.customers.map((c) => c.id);
};
const bulkAddLine = () => bulkForm.lines.push({ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null });
const bulkRemoveLine = (i) => { if (bulkForm.lines.length > 1) bulkForm.lines.splice(i, 1); };

const openBulk = () => {
    bulkForm.reset();
    bulkForm.clearErrors();
    bulkForm.customer_ids = [];
    bulkForm.lines = [{ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null }];
    bulkOpen.value = true;
};
const bulkSubmit = () => bulkForm.post(route('finance.ar-invoices.bulk-store'), {
    preserveScroll: true,
    onSuccess: () => { bulkOpen.value = false; },
});

const submitForApproval = (inv) => router.post(route('finance.ar-invoices.submit', inv.id));
const approve = (inv) => router.post(route('finance.ar-invoices.approve', inv.id));
const cancel  = (inv) => {
    const reason = prompt('Reason for cancellation?');
    if (!reason) return;
    router.post(route('finance.ar-invoices.cancel', inv.id), { reason });
};
const writeOff = (inv) => {
    const reason = prompt(`Write off ${cedi(inv.outstanding)} on ${inv.reference}?\nReason (required, e.g. "uncollectible after 180 days"):`);
    if (!reason) return;
    router.post(route('finance.ar-invoices.write-off', inv.id), { reason });
};

const statusColor = (val) => ({
    draft:            'text-on-surface-variant bg-surface-container border-outline-variant',
    pending_approval: 'text-amber-700 bg-amber-50 border-amber-100',
    approved:         'text-blue-700 bg-blue-50 border-blue-100',
    partially_paid:   'text-violet-700 bg-violet-50 border-violet-100',
    paid:             'text-emerald-700 bg-emerald-50 border-emerald-100',
    cancelled:        'text-rose-700 bg-rose-50 border-rose-100',
    written_off:      'text-rose-900 bg-rose-100 border-rose-200',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="AR Invoices" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — ACCOUNTS RECEIVABLE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Customer Invoices</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} invoice{{ rows.length === 1 ? '' : 's' }} · accrual posts automatically.</p>
            </div>
            <div v-if="canCreate" class="flex items-center gap-2">
                <button type="button" @click="openBulk"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">library_add</span>Bulk create
                </button>
                <PrimaryButton @click="openNew">
                    <span class="material-symbols-outlined text-[16px] mr-1">add</span>New Invoice
                </PrimaryButton>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 items-center">
            <button v-for="t in [
                { v: '',                  label: 'All' },
                { v: 'draft',             label: 'Draft' },
                { v: 'pending_approval',  label: 'Pending' },
                { v: 'approved',          label: 'Approved' },
                { v: 'partially_paid',    label: 'Partial' },
                { v: 'paid',              label: 'Paid' },
                { v: 'cancelled',         label: 'Cancelled' },
                { v: 'written_off',       label: 'Written Off' },
            ]" :key="t.v" @click="statusFilter = t.v; apply();"
                :class="['px-3 py-1.5 rounded-full text-[11px] font-bold border transition-colors',
                    statusFilter === t.v ? 'bg-primary text-on-primary border-primary'
                                         : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:border-secondary/40']">
                {{ t.label }}
            </button>
            <select v-model="customerFilter" @change="apply" aria-label="Filter by customer"
                    class="ml-2 rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] bg-surface-container-lowest">
                <option value="">All customers</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
            </select>
            <input v-model="searchTerm" type="text" placeholder="Search reference..." aria-label="Search invoice reference"
                   class="ml-auto rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] bg-surface-container-lowest" />
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reference</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Customer</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Customer ref</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Date</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Total</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Outstanding</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="inv in rows" :key="inv.id" class="border-t border-outline-variant/30 hover:bg-surface-container/40">
                        <td class="px-4 py-2 font-mono font-bold text-primary">
                            <Link :href="route('finance.ar-invoices.show', inv.id)" class="hover:underline">{{ inv.reference }}</Link>
                        </td>
                        <td class="px-4 py-2 text-on-surface">{{ inv.customer?.code }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ inv.customer_invoice_no ?? '—' }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ inv.invoice_date }}</td>
                        <td class="px-4 py-2 text-right font-mono text-primary">{{ cedi(inv.total) }}</td>
                        <td class="px-4 py-2 text-right font-mono text-primary">{{ cedi(inv.outstanding) }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(inv.status.value)">{{ inv.status.label }}</span>
                        </td>
                        <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                            <a :href="route('finance.ar-invoices.print', inv.id)" target="_blank" class="text-[11px] font-bold text-on-surface-variant hover:underline">Print</a>
                            <button v-if="canCreate && inv.status.value === 'draft'"            @click="openEdit(inv)"         class="text-[11px] font-bold text-blue-700 hover:underline">Edit</button>
                            <button v-if="canCreate && inv.status.value === 'draft'"            @click="submitForApproval(inv)" class="text-[11px] font-bold text-secondary hover:underline">Submit</button>
                            <button v-if="inv.status.value === 'pending_approval'"              @click="approve(inv)"          class="text-[11px] font-bold text-emerald-700 hover:underline">Approve</button>
                            <button v-if="canCreate && inv.status.value === 'draft'"            @click="destroyDraft(inv)"     class="text-[11px] font-bold text-rose-600 hover:underline">Delete</button>
                            <button v-if="['pending_approval','approved'].includes(inv.status.value)" @click="cancel(inv)" class="text-[11px] font-bold text-rose-600 hover:underline">Cancel</button>
                            <button v-if="canWriteOff && ['approved','partially_paid'].includes(inv.status.value)" @click="writeOff(inv)" class="text-[11px] font-bold text-rose-700 hover:underline">Write off</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="request_quote" title="No invoices match" description="Adjust filters or create a new invoice." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false" :title="editingId ? 'Edit AR Invoice' : 'New AR Invoice'">
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="customer_id" value="Customer" />
                        <select id="customer_id" v-model="form.customer_id" @change="onCustomerChange" aria-label="Customer"
                                class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                            <option :value="null">—</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <InputError :message="form.errors.customer_id" />
                    </div>
                    <div>
                        <InputLabel for="customer_invoice_no" value="Customer reference (optional)" />
                        <TextInput id="customer_invoice_no" v-model="form.customer_invoice_no" class="mt-1 block w-full" />
                        <InputError :message="form.errors.customer_invoice_no" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="invoice_date" value="Invoice date" />
                        <input id="invoice_date" v-model="form.invoice_date" type="date" aria-label="Invoice date" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                        <InputError :message="form.errors.invoice_date" />
                    </div>
                    <div>
                        <InputLabel for="due_date" value="Due date" />
                        <input id="due_date" v-model="form.due_date" type="date" aria-label="Due date" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    </div>
                </div>

                <div>
                    <div class="flex justify-between items-center mb-2">
                        <p class="text-[12px] font-black uppercase tracking-wider text-on-surface-variant">Lines</p>
                        <button type="button" @click="addLine" class="text-[11px] font-bold text-secondary hover:underline">+ Add line</button>
                    </div>
                    <div class="space-y-2">
                        <div v-for="(line, i) in form.lines" :key="i" class="rounded-xl border border-outline-variant/50 p-3 space-y-2">
                            <input v-model="line.description" type="text" placeholder="Description" aria-label="Line description"
                                   class="block w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-1.5 text-[12px]" />
                            <InputError :message="form.errors[`lines.${i}.description`]" />
                            <div class="grid grid-cols-4 gap-2">
                                <input v-model.number="line.quantity"   type="number" step="0.001" placeholder="Qty" aria-label="Quantity"
                                       class="rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1.5 text-[12px]" />
                                <input v-model.number="line.unit_price" type="number" step="0.0001" placeholder="Unit price" aria-label="Unit price"
                                       class="rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1.5 text-[12px]" />
                                <input v-model.number="line.tax_rate"   type="number" step="0.01" min="0" max="100" placeholder="Tax %" aria-label="Tax percent"
                                       class="rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1.5 text-[12px]" />
                                <button type="button" @click="removeLine(i)" :disabled="form.lines.length === 1"
                                        class="text-[11px] font-bold text-rose-600 disabled:text-on-surface-variant/30">Remove</button>
                            </div>
                            <p class="text-[10px] text-on-surface-variant/70">Qty · Unit price · Tax % (0–100)</p>
                            <InputError :message="form.errors[`lines.${i}.quantity`]" />
                            <InputError :message="form.errors[`lines.${i}.unit_price`]" />
                            <InputError :message="form.errors[`lines.${i}.tax_rate`]" />
                            <select v-model="line.gl_account_id" aria-label="Income GL account"
                                    class="block w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-1.5 text-[12px]">
                                <option :value="null">— Income GL —</option>
                                <option v-for="a in incomeAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                            </select>
                            <InputError :message="form.errors[`lines.${i}.gl_account_id`]" />
                        </div>
                    </div>
                    <InputError :message="form.errors.lines" />
                </div>

                <div class="rounded-xl bg-surface-container p-3 text-[12px] space-y-1">
                    <div class="flex justify-between"><span>Subtotal</span><span class="font-mono">{{ cedi(totals.subtotal) }}</span></div>
                    <div class="flex justify-between"><span>Tax</span><span class="font-mono">{{ cedi(totals.tax_amount) }}</span></div>
                    <div class="flex justify-between font-black text-primary"><span>Total</span><span class="font-mono">{{ cedi(totals.total) }}</span></div>
                </div>

                <div>
                    <InputLabel for="notes" value="Notes" />
                    <textarea id="notes" v-model="form.notes" rows="2" aria-label="Notes" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing">{{ editingId ? 'Save changes' : 'Create' }}</PrimaryButton>
                </div>
            </form>
        </SlidePanel>

        <!-- After a successful create, offer to add another. -->
        <ConfirmDialog
            :open="showCreatedPrompt"
            title="Invoice created"
            message="The customer invoice was created. Would you like to create another?"
            confirm-text="Create another"
            @confirm="() => { showCreatedPrompt = false; openNew(); }"
            @cancel="() => { showCreatedPrompt = false; }"
        />

        <!-- Bulk create: one draft invoice per selected customer, sharing the lines. -->
        <SlidePanel :open="bulkOpen" @close="bulkOpen = false" title="Bulk create customer invoices">
            <form @submit.prevent="bulkSubmit" class="space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <InputLabel value="Customers" />
                        <button type="button" @click="toggleAllCustomers" class="text-[11px] font-bold text-secondary hover:underline">
                            {{ allCustomersSelected ? 'Clear all' : 'Select all' }}
                        </button>
                    </div>
                    <div class="max-h-52 overflow-y-auto rounded-xl border border-outline-variant divide-y divide-outline-variant/40">
                        <label v-for="c in customers" :key="c.id" class="flex items-center gap-2 px-3 py-2 text-[12px] cursor-pointer hover:bg-surface-container">
                            <input type="checkbox" :checked="bulkForm.customer_ids.includes(c.id)" @change="toggleCustomer(c.id)" class="rounded border-outline-variant" />
                            <span class="font-bold text-on-surface">{{ c.code }}</span>
                            <span class="text-on-surface-variant">— {{ c.name }}</span>
                        </label>
                        <p v-if="!customers.length" class="px-3 py-4 text-[12px] text-on-surface-variant text-center">No customers available.</p>
                    </div>
                    <InputError :message="bulkForm.errors.customer_ids" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="bulk_invoice_date" value="Invoice date" />
                        <input id="bulk_invoice_date" v-model="bulkForm.invoice_date" type="date" aria-label="Invoice date" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                        <InputError :message="bulkForm.errors.invoice_date" />
                    </div>
                    <div>
                        <InputLabel for="bulk_due_date" value="Due date" />
                        <input id="bulk_due_date" v-model="bulkForm.due_date" type="date" aria-label="Due date" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    </div>
                </div>

                <div>
                    <div class="flex justify-between items-center mb-2">
                        <p class="text-[12px] font-black uppercase tracking-wider text-on-surface-variant">Lines (applied to every invoice)</p>
                        <button type="button" @click="bulkAddLine" class="text-[11px] font-bold text-secondary hover:underline">+ Add line</button>
                    </div>
                    <div class="space-y-2">
                        <div v-for="(line, i) in bulkForm.lines" :key="i" class="rounded-xl border border-outline-variant/50 p-3 space-y-2">
                            <input v-model="line.description" type="text" placeholder="Description" aria-label="Line description" class="block w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-1.5 text-[12px]" />
                            <InputError :message="bulkForm.errors[`lines.${i}.description`]" />
                            <div class="grid grid-cols-4 gap-2">
                                <input v-model.number="line.quantity" type="number" step="0.001" placeholder="Qty" aria-label="Quantity" class="rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1.5 text-[12px]" />
                                <input v-model.number="line.unit_price" type="number" step="0.0001" placeholder="Unit price" aria-label="Unit price" class="rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1.5 text-[12px]" />
                                <input v-model.number="line.tax_rate" type="number" step="0.01" min="0" max="100" placeholder="Tax %" aria-label="Tax percent" class="rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1.5 text-[12px]" />
                                <button type="button" @click="bulkRemoveLine(i)" :disabled="bulkForm.lines.length === 1" class="text-[11px] font-bold text-rose-600 disabled:text-on-surface-variant/30">Remove</button>
                            </div>
                            <InputError :message="bulkForm.errors[`lines.${i}.tax_rate`]" />
                            <select v-model="line.gl_account_id" aria-label="Income GL account" class="block w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-1.5 text-[12px]">
                                <option :value="null">— Income GL —</option>
                                <option v-for="a in incomeAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                            </select>
                            <InputError :message="bulkForm.errors[`lines.${i}.gl_account_id`]" />
                        </div>
                    </div>
                    <InputError :message="bulkForm.errors.lines" />
                </div>

                <div class="rounded-xl bg-surface-container p-3 text-[12px] space-y-1">
                    <div class="flex justify-between"><span>Per invoice</span><span class="font-mono">{{ cedi(bulkPerInvoice.total) }}</span></div>
                    <div class="flex justify-between font-black text-primary">
                        <span>{{ bulkForm.customer_ids.length }} invoice{{ bulkForm.customer_ids.length === 1 ? '' : 's' }} · total</span>
                        <span class="font-mono">{{ cedi(bulkPerInvoice.total * bulkForm.customer_ids.length) }}</span>
                    </div>
                </div>

                <div>
                    <InputLabel for="bulk_notes" value="Notes (all invoices)" />
                    <textarea id="bulk_notes" v-model="bulkForm.notes" rows="2" aria-label="Notes" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="bulkOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="bulkForm.processing || !bulkForm.customer_ids.length">
                        Create {{ bulkForm.customer_ids.length || '' }} invoice{{ bulkForm.customer_ids.length === 1 ? '' : 's' }}
                    </PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>

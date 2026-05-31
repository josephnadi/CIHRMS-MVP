<script setup>
import { ref, computed, reactive, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    invoices:        { type: Object, required: true },
    filters:         { type: Object, default: () => ({}) },
    vendors:         { type: Array,  default: () => [] },
    expenseAccounts: { type: Array,  default: () => [] },
});

const page = usePage();
const canCreate = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('ap_invoices.create');
});

const rows = computed(() => props.invoices.data ?? props.invoices ?? []);
const statusFilter = ref(props.filters.status ?? '');
const vendorFilter = ref(props.filters.vendor_id ?? '');
const searchTerm   = ref(props.filters.search ?? '');

const apply = () => router.get(route('finance.ap-invoices.index'), {
    status:    statusFilter.value || undefined,
    vendor_id: vendorFilter.value || undefined,
    search:    searchTerm.value   || undefined,
}, { preserveState: true, replace: true });

let timer = null;
watch(searchTerm, () => { clearTimeout(timer); timer = setTimeout(apply, 320); });

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// ── New invoice slide panel ──
const panelOpen = ref(false);
const form = useForm({
    vendor_id: null, vendor_invoice_no: '', invoice_date: new Date().toISOString().slice(0,10),
    due_date: '', currency: 'GHS', notes: '',
    lines: [{ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null }],
});

const totals = computed(() => {
    const sub = form.lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
    const tax = form.lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0) * (Number(l.tax_rate) || 0), 0);
    return { subtotal: sub, tax_amount: tax, total: sub + tax };
});

const addLine = () => form.lines.push({ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null });
const removeLine = (i) => { if (form.lines.length > 1) form.lines.splice(i, 1); };

const openNew = () => {
    form.reset();
    Object.assign(form, {
        vendor_id: null, vendor_invoice_no: '', invoice_date: new Date().toISOString().slice(0,10),
        due_date: '', currency: 'GHS', notes: '',
        lines: [{ description: '', quantity: 1, unit_price: 0, tax_rate: 0, gl_account_id: null }],
    });
    panelOpen.value = true;
};

const onVendorChange = () => {
    const v = props.vendors.find(x => x.id === form.vendor_id);
    if (v && v.default_expense_gl_account_id) {
        form.lines.forEach(l => { if (!l.gl_account_id) l.gl_account_id = v.default_expense_gl_account_id; });
    }
};

const submit = () => form.post(route('finance.ap-invoices.store'), { onSuccess: () => panelOpen.value = false });

const submitForApproval = (inv) => router.post(route('finance.ap-invoices.submit', inv.id));
const approve = (inv) => router.post(route('finance.ap-invoices.approve', inv.id));
const cancel  = (inv) => {
    const reason = prompt('Reason for cancellation?');
    if (!reason) return;
    router.post(route('finance.ap-invoices.cancel', inv.id), { reason });
};

const statusColor = (val) => ({
    draft:            'text-on-surface-variant bg-surface-container border-outline-variant',
    pending_approval: 'text-amber-700 bg-amber-50 border-amber-100',
    approved:         'text-blue-700 bg-blue-50 border-blue-100',
    partially_paid:   'text-violet-700 bg-violet-50 border-violet-100',
    paid:             'text-emerald-700 bg-emerald-50 border-emerald-100',
    cancelled:        'text-rose-700 bg-rose-50 border-rose-100',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="AP Invoices" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — ACCOUNTS PAYABLE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Vendor Invoices</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} invoices · accrual posts automatically.</p>
            </div>
            <PrimaryButton v-if="canCreate" @click="openNew">
                <span class="material-symbols-outlined text-[16px] mr-1">add</span>New Invoice
            </PrimaryButton>
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
            ]" :key="t.v" @click="statusFilter = t.v; apply();"
                :class="['px-3 py-1.5 rounded-full text-[11px] font-bold border transition-colors',
                    statusFilter === t.v ? 'bg-primary text-on-primary border-primary'
                                         : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:border-secondary/40']">
                {{ t.label }}
            </button>
            <select aria-label="VendorFilter" v-model="vendorFilter" @change="apply"
                    class="ml-2 rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] bg-surface-container-lowest">
                <option value="">All vendors</option>
                <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.code }} — {{ v.name }}</option>
            </select>
            <input aria-label="SearchTerm" v-model="searchTerm" type="text" placeholder="Search reference..."
                   class="ml-auto rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] bg-surface-container-lowest" />
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reference</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Vendor</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Invoice #</th>
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
                            <Link :href="route('finance.ap-invoices.show', inv.id)" class="hover:underline">{{ inv.reference }}</Link>
                        </td>
                        <td class="px-4 py-2 text-on-surface">{{ inv.vendor?.code }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ inv.vendor_invoice_no ?? '—' }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ inv.invoice_date }}</td>
                        <td class="px-4 py-2 text-right font-mono text-primary">{{ cedi(inv.total) }}</td>
                        <td class="px-4 py-2 text-right font-mono text-primary">{{ cedi(inv.outstanding) }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(inv.status.value)">{{ inv.status.label }}</span>
                        </td>
                        <td class="px-4 py-2 text-right space-x-2">
                            <button v-if="canCreate && inv.status.value === 'draft'"            @click="submitForApproval(inv)" class="text-[11px] font-bold text-secondary hover:underline">Submit</button>
                            <button v-if="inv.status.value === 'pending_approval'"              @click="approve(inv)"          class="text-[11px] font-bold text-emerald-700 hover:underline">Approve</button>
                            <button v-if="['draft','pending_approval','approved'].includes(inv.status.value)" @click="cancel(inv)" class="text-[11px] font-bold text-rose-600 hover:underline">Cancel</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="receipt_long" title="No invoices match" description="Adjust filters or create a new invoice." />

        <!-- New invoice slide panel -->
        <SlidePanel :open="panelOpen" @close="panelOpen = false" title="New Vendor Invoice">
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="vendor_id" value="Vendor" />
                        <select aria-label="Vendor id" id="vendor_id" v-model="form.vendor_id" @change="onVendorChange"
                                class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                            <option :value="null">—</option>
                            <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.code }} — {{ v.name }}</option>
                        </select>
                        <InputError :message="form.errors.vendor_id" />
                    </div>
                    <div>
                        <InputLabel for="vendor_invoice_no" value="Vendor invoice #" />
                        <TextInput id="vendor_invoice_no" v-model="form.vendor_invoice_no" class="mt-1 block w-full" />
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
                            <input aria-label="Description" v-model="line.description" type="text" placeholder="Description"
                                   class="block w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-1.5 text-[12px]" />
                            <div class="grid grid-cols-4 gap-2">
                                <input aria-label="Quantity" v-model.number="line.quantity"   type="number" step="0.001" placeholder="Qty"
                                       class="rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1.5 text-[12px]" />
                                <input aria-label="Unit price" v-model.number="line.unit_price" type="number" step="0.0001" placeholder="Unit price"
                                       class="rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1.5 text-[12px]" />
                                <input aria-label="Tax rate" v-model.number="line.tax_rate"   type="number" step="0.001" placeholder="Tax rate (0.125 = 12.5%)"
                                       class="rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1.5 text-[12px]" />
                                <button type="button" @click="removeLine(i)" :disabled="form.lines.length === 1"
                                        class="text-[11px] font-bold text-rose-600 disabled:text-on-surface-variant/30">Remove</button>
                            </div>
                            <select aria-label="Gl account id" v-model="line.gl_account_id"
                                    class="block w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-1.5 text-[12px]">
                                <option :value="null">— Expense GL —</option>
                                <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                            </select>
                        </div>
                    </div>
                    <InputError :message="form.errors.lines" />
                </div>

                <div class="rounded-xl bg-surface-container p-3 text-[12px] space-y-1">
                    <div class="flex justify-between"><span>Subtotal</span><span class="font-mono">{{ cedi(totals.subtotal) }}</span></div>
                    <div class="flex justify-between"><span>Tax</span><span class="font-mono">{{ cedi(totals.tax_amount) }}</span></div>
                    <div class="flex justify-between font-black text-primary"><span>Total</span><span class="font-mono">{{ cedi(totals.total) }}</span></div>
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing">Create</PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>

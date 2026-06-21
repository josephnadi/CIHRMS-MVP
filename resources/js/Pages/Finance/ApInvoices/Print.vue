<script setup>
import { onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

// Standalone (no app chrome) so it prints clean.
defineOptions({ layout: null });

const props = defineProps({
    invoice: { type: Object, required: true },
    org:     { type: Object, default: () => ({ name: 'Organisation' }) },
});

const cedi = (v) => (props.invoice.currency || 'GHS') + ' ' +
    (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const printNow = () => window.print();
onMounted(() => { setTimeout(printNow, 350); });
</script>

<template>
    <Head :title="`Invoice ${invoice.reference}`" />
    <div class="invoice-page">
        <div class="no-print toolbar">
            <Link :href="route('finance.ap-invoices.show', invoice.id)" class="btn-link">← Back</Link>
            <button type="button" class="btn-print" @click="printNow">Print</button>
        </div>

        <article class="sheet">
            <header class="head">
                <div>
                    <h1 class="org">{{ org.name }}</h1>
                    <p class="muted">Purchase Invoice</p>
                </div>
                <div class="meta">
                    <div class="ref">{{ invoice.reference }}</div>
                    <div class="badge">{{ invoice.status.label }}</div>
                </div>
            </header>

            <section class="parties">
                <div>
                    <p class="label">Bill from</p>
                    <p class="strong">{{ invoice.vendor?.name }}</p>
                    <p class="muted">{{ invoice.vendor?.code }}</p>
                </div>
                <div class="dates">
                    <p><span class="label">Invoice date</span> {{ invoice.invoice_date }}</p>
                    <p v-if="invoice.due_date"><span class="label">Due date</span> {{ invoice.due_date }}</p>
                    <p v-if="invoice.vendor_invoice_no"><span class="label">Vendor ref</span> {{ invoice.vendor_invoice_no }}</p>
                </div>
            </section>

            <table class="lines">
                <thead>
                    <tr>
                        <th class="l">Description</th>
                        <th class="r">Qty</th>
                        <th class="r">Unit price</th>
                        <th class="r">Tax</th>
                        <th class="r">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in invoice.lines" :key="l.id ?? l.line_no">
                        <td class="l">{{ l.description }}</td>
                        <td class="r">{{ l.quantity }}</td>
                        <td class="r">{{ cedi(l.unit_price) }}</td>
                        <td class="r">{{ (Number(l.tax_rate) * 100).toFixed(1) }}%</td>
                        <td class="r">{{ cedi(Number(l.line_total) + Number(l.tax_amount)) }}</td>
                    </tr>
                </tbody>
            </table>

            <section class="totals">
                <div class="row"><span>Subtotal</span><span>{{ cedi(invoice.subtotal) }}</span></div>
                <div class="row"><span>Tax</span><span>{{ cedi(invoice.tax_amount) }}</span></div>
                <div class="row grand"><span>Total</span><span>{{ cedi(invoice.total) }}</span></div>
                <div class="row" v-if="invoice.amount_paid"><span>Paid</span><span>{{ cedi(invoice.amount_paid) }}</span></div>
            </section>

            <section v-if="invoice.notes" class="notes">
                <p class="label">Notes</p>
                <p>{{ invoice.notes }}</p>
            </section>

            <footer class="foot muted">Generated from {{ org.name }} · {{ invoice.reference }}</footer>
        </article>
    </div>
</template>

<style scoped>
.invoice-page { background:#f3f4f6; min-height:100vh; padding:24px; font-family: ui-sans-serif, system-ui, sans-serif; color:#111827; }
.toolbar { max-width:800px; margin:0 auto 16px; display:flex; justify-content:space-between; }
.btn-link { color:#475569; font-weight:700; font-size:13px; text-decoration:none; }
.btn-print { background:#1d4ed8; color:#fff; border:0; border-radius:8px; padding:8px 18px; font-weight:700; font-size:13px; cursor:pointer; }
.sheet { max-width:800px; margin:0 auto; background:#fff; padding:48px; box-shadow:0 1px 4px rgba(0,0,0,.08); border-radius:8px; }
.head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #111827; padding-bottom:16px; }
.org { font-size:22px; font-weight:800; margin:0; }
.meta { text-align:right; }
.ref { font-family: ui-monospace, monospace; font-weight:800; font-size:15px; }
.badge { display:inline-block; margin-top:4px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:999px; padding:2px 10px; }
.parties { display:flex; justify-content:space-between; gap:24px; margin:24px 0; }
.dates { text-align:right; font-size:13px; }
.dates p { margin:2px 0; }
.label { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; margin-right:6px; }
.strong { font-weight:700; font-size:15px; margin:2px 0; }
.muted { color:#6b7280; }
.lines { width:100%; border-collapse:collapse; margin-top:12px; font-size:13px; }
.lines th { text-transform:uppercase; font-size:10px; letter-spacing:.04em; color:#6b7280; border-bottom:1px solid #e5e7eb; padding:8px 6px; }
.lines td { padding:8px 6px; border-bottom:1px solid #f3f4f6; }
.l { text-align:left; }
.r { text-align:right; white-space:nowrap; }
.totals { margin-top:16px; margin-left:auto; width:280px; font-size:13px; }
.totals .row { display:flex; justify-content:space-between; padding:4px 0; }
.totals .grand { border-top:2px solid #111827; margin-top:4px; padding-top:8px; font-weight:800; font-size:15px; }
.notes { margin-top:28px; font-size:13px; }
.foot { margin-top:40px; padding-top:12px; border-top:1px solid #e5e7eb; font-size:11px; text-align:center; }
@media print {
    .invoice-page { background:#fff; padding:0; }
    .no-print { display:none !important; }
    .sheet { box-shadow:none; max-width:none; padding:0; border-radius:0; }
}
</style>

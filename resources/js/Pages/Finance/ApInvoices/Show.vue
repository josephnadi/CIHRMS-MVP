<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({ invoice: { type: Object, required: true } });

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const statusColor = (val) => ({
    draft: 'text-on-surface-variant bg-surface-container border-outline-variant',
    pending_approval: 'text-amber-700 bg-amber-50 border-amber-100',
    approved: 'text-blue-700 bg-blue-50 border-blue-100',
    partially_paid: 'text-violet-700 bg-violet-50 border-violet-100',
    paid: 'text-emerald-700 bg-emerald-50 border-emerald-100',
    cancelled: 'text-rose-700 bg-rose-50 border-rose-100',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head :title="`Invoice ${invoice.reference}`" />

    <div class="space-y-6 animate-reveal-up">
        <div>
            <Link :href="route('finance.ap-invoices.index')" class="text-[11px] font-bold text-secondary hover:underline">← Back to invoices</Link>
            <div class="mt-2 flex items-center justify-between">
                <div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary">{{ invoice.reference }}</h1>
                    <p class="text-[13px] text-on-surface-variant mt-0.5">{{ invoice.vendor?.code }} — {{ invoice.vendor?.name }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-[10px] font-black uppercase border" :class="statusColor(invoice.status.value)">{{ invoice.status.label }}</span>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-4">
                <table class="w-full text-[12px]">
                    <thead class="border-b border-outline-variant/40">
                        <tr class="text-left">
                            <th class="py-2 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">#</th>
                            <th class="py-2 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Description</th>
                            <th class="py-2 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Qty</th>
                            <th class="py-2 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Unit</th>
                            <th class="py-2 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Line Total</th>
                            <th class="py-2 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Tax</th>
                            <th class="py-2 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Expense GL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in invoice.lines" :key="l.id" class="border-b border-outline-variant/20">
                            <td class="py-2 font-mono">{{ l.line_no }}</td>
                            <td class="py-2 text-on-surface">{{ l.description }}</td>
                            <td class="py-2 text-right font-mono">{{ l.quantity }}</td>
                            <td class="py-2 text-right font-mono">{{ cedi(l.unit_price) }}</td>
                            <td class="py-2 text-right font-mono">{{ cedi(l.line_total) }}</td>
                            <td class="py-2 text-right font-mono">{{ cedi(l.tax_amount) }}</td>
                            <td class="py-2 text-on-surface-variant font-mono">{{ l.gl_account?.code }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="space-y-4">
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 text-[12px] space-y-2">
                    <div class="flex justify-between"><span>Subtotal</span><span class="font-mono">{{ cedi(invoice.subtotal) }}</span></div>
                    <div class="flex justify-between"><span>Tax</span><span class="font-mono">{{ cedi(invoice.tax_amount) }}</span></div>
                    <div class="flex justify-between font-black text-primary text-[14px] pt-2 border-t border-outline-variant/40"><span>Total</span><span class="font-mono">{{ cedi(invoice.total) }}</span></div>
                    <div class="flex justify-between"><span>Paid</span><span class="font-mono">{{ cedi(invoice.amount_paid) }}</span></div>
                    <div class="flex justify-between font-black text-rose-700"><span>Outstanding</span><span class="font-mono">{{ cedi(invoice.outstanding) }}</span></div>
                </div>

                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 text-[11px] space-y-1.5">
                    <p class="font-black text-on-surface-variant uppercase tracking-wider text-[9px]">Dates</p>
                    <p>Invoice date: <span class="font-mono">{{ invoice.invoice_date }}</span></p>
                    <p>Due date: <span class="font-mono">{{ invoice.due_date ?? '—' }}</span></p>
                    <p v-if="invoice.approved_at">Approved: <span class="font-mono">{{ invoice.approved_at }}</span></p>
                    <p v-if="invoice.cancelled_at">Cancelled: <span class="font-mono">{{ invoice.cancelled_at }}</span></p>
                </div>

                <div v-if="invoice.accrual_journal_entry_id" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <Link :href="route('finance.journal.show', invoice.accrual_journal_entry_id)" class="text-[11px] font-bold text-secondary hover:underline">
                        → View accrual journal entry
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>

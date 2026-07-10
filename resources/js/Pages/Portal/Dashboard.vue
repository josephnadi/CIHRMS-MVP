<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    member:            { type: Object, required: true },
    outstanding_total: { type: Number, default: 0 },
    currency:          { type: String, default: 'GHS' },
    open_invoices:     { type: Array,  default: () => [] },
});

const money = (v) => Number(v || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const payForm = useForm({});
function pay(invoiceId) {
    payForm.post(route('portal.fees.pay', invoiceId));
}
</script>

<template>
<Head title="Dashboard — CIHRM Portal" />
<div class="space-y-6">
    <section class="rounded-2xl border border-outline-variant/60 bg-gradient-to-br from-primary/5 to-secondary/5 p-8">
        <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Welcome back</p>
        <h1 class="mt-1 text-2xl font-black tracking-tight text-primary">{{ member.name }}</h1>
        <p class="mt-1 text-xs text-on-surface-variant">
            <span class="font-mono">{{ member.member_no }}</span> · <span class="capitalize">{{ member.class }}</span>
        </p>
    </section>

    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
        <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Outstanding fees</p>
        <p class="mt-1 text-3xl font-black tracking-tight tabular-nums" :class="outstanding_total > 0 ? 'text-amber-700' : 'text-emerald-700'">
            {{ currency }} {{ money(outstanding_total) }}
        </p>
        <p v-if="outstanding_total === 0" class="mt-1 text-xs text-on-surface-variant">All your fees are settled — thank you.</p>
    </section>

    <section v-if="open_invoices.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
        <header class="px-6 py-4 border-b border-outline-variant/60 flex items-center justify-between">
            <h2 class="text-sm font-black text-primary">Open invoices</h2>
            <Link :href="route('portal.fees')" class="text-xs font-bold text-primary hover:underline">View all →</Link>
        </header>
        <table class="w-full text-sm">
            <thead class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                <tr>
                    <th class="px-6 py-2">Reference</th>
                    <th class="px-6 py-2">Date</th>
                    <th class="px-6 py-2">Due</th>
                    <th class="px-6 py-2 text-right">Outstanding</th>
                    <th class="px-6 py-2 text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="inv in open_invoices" :key="inv.id" class="border-t border-outline-variant/40">
                    <td class="px-6 py-2 font-mono text-xs">{{ inv.reference }}</td>
                    <td class="px-6 py-2">{{ inv.invoice_date }}</td>
                    <td class="px-6 py-2">{{ inv.due_date ?? '—' }}</td>
                    <td class="px-6 py-2 text-right tabular-nums font-semibold">
                        {{ inv.currency }} {{ money(inv.outstanding) }}
                    </td>
                    <td class="px-6 py-2 text-right">
                        <button @click="pay(inv.id)" :disabled="payForm.processing"
                                class="rounded-xl bg-primary px-3 py-1.5 text-xs font-black text-white shadow-glow-sm disabled:opacity-50">
                            Pay now
                        </button>
                        <p v-if="payForm.errors.status" class="text-[12px] text-rose-600 mt-1">{{ payForm.errors.status }}</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
</div>
</template>

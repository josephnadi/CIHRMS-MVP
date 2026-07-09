<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    invoice: { type: Object, required: true },
    vendors: { type: Array, default: () => [] },
    expenseAccounts: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const s = props.invoice.status.value;

const vetForm = useForm({ notes: '' });
const returnForm = useForm({ reason: '' });
const postForm = useForm({ vendor_id: '', lines: [{ description: '', quantity: 1, unit_price: props.invoice.amount, tax_rate: 0, gl_account_id: '' }] });

function act(name) { router.post(route(name, props.invoice.id)); }
</script>

<template>
    <Head :title="invoice.reference" />
    <AuthenticatedLayout>
        <div class="p-6 max-w-3xl space-y-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold">{{ invoice.reference }}</h1>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-sm">{{ invoice.status.label }}</span>
            </div>

            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Vendor</dt><dd>{{ invoice.vendor_name }}</dd>
                <dt class="text-gray-500">Amount</dt><dd>{{ invoice.currency }} {{ invoice.amount.toFixed(2) }}</dd>
                <dt class="text-gray-500">Date</dt><dd>{{ invoice.invoice_date }}</dd>
                <dt class="text-gray-500">Description</dt><dd>{{ invoice.description }}</dd>
            </dl>

            <div v-if="invoice.attachments?.length">
                <h2 class="font-medium">Attachments</h2>
                <ul class="text-sm list-disc pl-5">
                    <li v-for="a in invoice.attachments" :key="a.id">
                        <a :href="route('auditor.incoming-invoices.download', [invoice.id, a.id])" class="text-blue-600">{{ a.original_name }}</a>
                    </li>
                </ul>
            </div>

            <div v-if="invoice.return_reason && s === 'returned'" class="rounded-lg bg-amber-50 p-3 text-sm">
                <strong>Returned:</strong> {{ invoice.return_reason }}
            </div>

            <!-- Submitter -->
            <div v-if="can.submit && (s === 'draft' || s === 'returned')" class="flex gap-2">
                <button @click="act('auditor.incoming-invoices.submit')" class="rounded-lg bg-blue-600 text-white px-4 py-2">Submit for vetting</button>
            </div>

            <!-- Auditor -->
            <div v-if="can.vet && s === 'submitted'" class="space-y-2 border-t pt-4">
                <textarea v-model="vetForm.notes" placeholder="Vetting notes (optional)" aria-label="Vetting notes" class="w-full rounded-lg border-gray-300"></textarea>
                <div class="flex gap-2">
                    <button @click="vetForm.post(route('auditor.incoming-invoices.vet', invoice.id))" class="rounded-lg bg-green-600 text-white px-4 py-2">Accept & send to CEO</button>
                    <button @click="returnForm.post(route('auditor.incoming-invoices.vet-return', invoice.id))" class="rounded-lg bg-red-600 text-white px-4 py-2">Return</button>
                </div>
                <input v-model="returnForm.reason" placeholder="Return reason" aria-label="Return reason" class="w-full rounded-lg border-gray-300" />
                <div v-if="returnForm.errors.reason" class="text-red-600 text-xs">{{ returnForm.errors.reason }}</div>
            </div>

            <!-- CEO -->
            <div v-if="can.approve && s === 'vetted'" class="space-y-2 border-t pt-4">
                <div class="flex gap-2">
                    <button @click="act('auditor.incoming-invoices.approve')" class="rounded-lg bg-green-600 text-white px-4 py-2">Approve</button>
                    <button @click="returnForm.post(route('auditor.incoming-invoices.ceo-return', invoice.id))" class="rounded-lg bg-red-600 text-white px-4 py-2">Return</button>
                </div>
                <input v-model="returnForm.reason" placeholder="Return reason" aria-label="Return reason" class="w-full rounded-lg border-gray-300" />
                <div v-if="returnForm.errors.reason" class="text-red-600 text-xs">{{ returnForm.errors.reason }}</div>
            </div>

            <!-- Finance posting -->
            <div v-if="can.post && s === 'approved'" class="space-y-2 border-t pt-4">
                <h2 class="font-medium">Post to ledger</h2>
                <select v-model="postForm.vendor_id" aria-label="Vendor" class="w-full rounded-lg border-gray-300">
                    <option value="">Select vendor…</option>
                    <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.code }} — {{ v.name }}</option>
                </select>
                <div v-for="(line, i) in postForm.lines" :key="i" class="grid grid-cols-4 gap-2">
                    <input v-model="line.description" placeholder="Description" aria-label="Line description" class="rounded-lg border-gray-300" />
                    <input type="number" v-model.number="line.quantity" placeholder="Qty" aria-label="Quantity" class="rounded-lg border-gray-300" />
                    <input type="number" v-model.number="line.unit_price" placeholder="Unit price" aria-label="Unit price" class="rounded-lg border-gray-300" />
                    <select v-model="line.gl_account_id" aria-label="GL account" class="rounded-lg border-gray-300">
                        <option value="">GL account…</option>
                        <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                    </select>
                </div>
                <button @click="postForm.post(route('auditor.incoming-invoices.post', invoice.id))" class="rounded-lg bg-blue-600 text-white px-4 py-2">Post</button>
            </div>

            <!-- Timeline -->
            <div v-if="invoice.events?.length" class="border-t pt-4">
                <h2 class="font-medium">History</h2>
                <ol class="text-sm space-y-1">
                    <li v-for="e in invoice.events" :key="e.id" class="text-gray-600">
                        <span class="font-medium">{{ e.action }}</span>
                        <span v-if="e.actor"> by {{ e.actor.name }}</span>
                        <span class="text-gray-400"> · {{ e.created_at }}</span>
                        <span v-if="e.comment"> — {{ e.comment }}</span>
                    </li>
                </ol>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

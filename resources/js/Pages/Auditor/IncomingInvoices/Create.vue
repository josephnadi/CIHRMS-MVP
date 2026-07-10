<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    vendor_name: '',
    vendor_invoice_no: '',
    invoice_date: '',
    currency: 'GHS',
    amount: '',
    description: '',
    attachments: [],
});

function submit() {
    form.post(route('auditor.incoming-invoices.store'), { forceFormData: true });
}
</script>

<template>
    <Head title="New Incoming Invoice" />
    <AuthenticatedLayout>
        <form @submit.prevent="submit" class="p-6 max-w-xl space-y-4">
            <h1 class="text-2xl font-semibold text-primary">New Incoming Invoice</h1>

            <div>
                <label class="block text-sm text-on-surface-variant">Vendor name</label>
                <input v-model="form.vendor_name" aria-label="Vendor name" class="w-full rounded-lg border-outline-variant bg-surface-container-low text-on-surface focus:border-secondary focus:ring-secondary/20" />
                <div v-if="form.errors.vendor_name" class="text-red-600 dark:text-red-400 text-xs">{{ form.errors.vendor_name }}</div>
            </div>
            <div>
                <label class="block text-sm text-on-surface-variant">Vendor invoice #</label>
                <input v-model="form.vendor_invoice_no" aria-label="Vendor invoice number" class="w-full rounded-lg border-outline-variant bg-surface-container-low text-on-surface focus:border-secondary focus:ring-secondary/20" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-on-surface-variant">Invoice date</label>
                    <input type="date" v-model="form.invoice_date" aria-label="Invoice date" class="w-full rounded-lg border-outline-variant bg-surface-container-low text-on-surface focus:border-secondary focus:ring-secondary/20" />
                    <div v-if="form.errors.invoice_date" class="text-red-600 dark:text-red-400 text-xs">{{ form.errors.invoice_date }}</div>
                </div>
                <div>
                    <label class="block text-sm text-on-surface-variant">Amount</label>
                    <input type="number" step="0.01" v-model="form.amount" aria-label="Amount" class="w-full rounded-lg border-outline-variant bg-surface-container-low text-on-surface focus:border-secondary focus:ring-secondary/20" />
                    <div v-if="form.errors.amount" class="text-red-600 dark:text-red-400 text-xs">{{ form.errors.amount }}</div>
                </div>
            </div>
            <div>
                <label class="block text-sm text-on-surface-variant">Description</label>
                <textarea v-model="form.description" aria-label="Description" class="w-full rounded-lg border-outline-variant bg-surface-container-low text-on-surface focus:border-secondary focus:ring-secondary/20"></textarea>
            </div>
            <div>
                <label class="block text-sm text-on-surface-variant">Attachments (scan/upload)</label>
                <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" @input="form.attachments = Array.from($event.target.files)" class="text-on-surface-variant" />
                <div v-if="form.errors['attachments.0']" class="text-red-600 dark:text-red-400 text-xs">{{ form.errors['attachments.0'] }}</div>
            </div>

            <button :disabled="form.processing" class="rounded-lg bg-secondary text-white px-4 py-2 disabled:opacity-50">Submit to intake</button>
        </form>
    </AuthenticatedLayout>
</template>

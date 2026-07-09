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
            <h1 class="text-2xl font-semibold">New Incoming Invoice</h1>

            <div>
                <label class="block text-sm">Vendor name</label>
                <input v-model="form.vendor_name" class="w-full rounded-lg border-gray-300" />
                <div v-if="form.errors.vendor_name" class="text-red-600 text-xs">{{ form.errors.vendor_name }}</div>
            </div>
            <div>
                <label class="block text-sm">Vendor invoice #</label>
                <input v-model="form.vendor_invoice_no" class="w-full rounded-lg border-gray-300" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm">Invoice date</label>
                    <input type="date" v-model="form.invoice_date" class="w-full rounded-lg border-gray-300" />
                    <div v-if="form.errors.invoice_date" class="text-red-600 text-xs">{{ form.errors.invoice_date }}</div>
                </div>
                <div>
                    <label class="block text-sm">Amount</label>
                    <input type="number" step="0.01" v-model="form.amount" class="w-full rounded-lg border-gray-300" />
                    <div v-if="form.errors.amount" class="text-red-600 text-xs">{{ form.errors.amount }}</div>
                </div>
            </div>
            <div>
                <label class="block text-sm">Description</label>
                <textarea v-model="form.description" class="w-full rounded-lg border-gray-300"></textarea>
            </div>
            <div>
                <label class="block text-sm">Attachments (scan/upload)</label>
                <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" @input="form.attachments = Array.from($event.target.files)" />
                <div v-if="form.errors['attachments.0']" class="text-red-600 text-xs">{{ form.errors['attachments.0'] }}</div>
            </div>

            <button :disabled="form.processing" class="rounded-lg bg-blue-600 text-white px-4 py-2">Submit to intake</button>
        </form>
    </AuthenticatedLayout>
</template>

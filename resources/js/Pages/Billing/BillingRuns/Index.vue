<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    runs:     { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const showForm = ref(false);
const form = useForm({
    fee_product_id: null,
    period_label: new Date().getFullYear().toString(),
    invoice_date: new Date().toISOString().slice(0, 10),
    due_date: '',
});

function submit() {
    form.post(route('billing.runs.store'), {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); form.period_label = new Date().getFullYear().toString(); form.invoice_date = new Date().toISOString().slice(0, 10); },
    });
}
</script>

<template>
<Head title="Billing runs — CIHRM" />
<div class="p-6 max-w-7xl mx-auto">
    <header class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-primary">Billing runs</h1>
            <p class="text-sm text-on-surface-variant">Mint AR invoices for a fee product against eligible members. Idempotent per (product × period).</p>
        </div>
        <PrimaryButton @click="showForm = true">Start a billing run</PrimaryButton>
    </header>

    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-surface-container">
                <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    <th class="px-4 py-3">Fee product</th>
                    <th class="px-4 py-3">Period</th>
                    <th class="px-4 py-3">Assignments</th>
                    <th class="px-4 py-3">Invoices minted</th>
                    <th class="px-4 py-3">Last run</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in runs" :key="`${r.fee_product_id}-${r.period_label}`" class="border-t border-outline-variant/40">
                    <td class="px-4 py-2">
                        <span class="font-mono text-xs">{{ r.fee_product?.code ?? '—' }}</span> —
                        <span class="font-semibold">{{ r.fee_product?.name ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-2 font-mono">{{ r.period_label }}</td>
                    <td class="px-4 py-2 tabular-nums">{{ r.assignments }}</td>
                    <td class="px-4 py-2 tabular-nums">{{ r.invoices_minted }}</td>
                    <td class="px-4 py-2 text-xs">{{ r.last_assigned_at ? new Date(r.last_assigned_at).toLocaleString() : '—' }}</td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="runs.length === 0" title="No billing runs yet" subtitle="Click 'Start a billing run' to mint your first batch of invoices." />
    </div>

    <SlidePanel v-if="showForm" @close="showForm = false" title="Start a billing run">
        <form @submit.prevent="submit" class="space-y-4">
            <p class="text-xs text-on-surface-variant">
                Re-running for an existing (fee × period) is a no-op — members already billed are skipped.
                Invoices are minted in Draft; finance approves them in the existing AR flow.
            </p>
            <div>
                <InputLabel for="fee_product_id" value="Fee product" />
                <select id="fee_product_id" v-model.number="form.fee_product_id" required aria-label="Fee product" class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                    <option :value="null">— Select —</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }} ({{ p.currency }} {{ Number(p.amount).toFixed(2) }})</option>
                </select>
                <InputError :message="form.errors.fee_product_id" class="mt-1" />
            </div>
            <div>
                <InputLabel for="period_label" value="Period label (e.g. 2026, 2026-S1)" />
                <TextInput id="period_label" v-model="form.period_label" required class="mt-1 w-full" />
                <InputError :message="form.errors.period_label" class="mt-1" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <InputLabel for="invoice_date" value="Invoice date" />
                    <TextInput id="invoice_date" v-model="form.invoice_date" type="date" class="mt-1 w-full" />
                    <InputError :message="form.errors.invoice_date" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="due_date" value="Due date" />
                    <TextInput id="due_date" v-model="form.due_date" type="date" class="mt-1 w-full" />
                    <InputError :message="form.errors.due_date" class="mt-1" />
                </div>
            </div>
            <div class="pt-2 flex justify-end gap-2">
                <button type="button" @click="showForm = false" class="rounded-xl px-4 py-2 text-sm">Cancel</button>
                <PrimaryButton type="submit" :disabled="form.processing">Run</PrimaryButton>
            </div>
        </form>
    </SlidePanel>
</div>
</template>

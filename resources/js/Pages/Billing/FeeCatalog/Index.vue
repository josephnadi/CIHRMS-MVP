<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    products:       { type: Object, required: true },
    incomeAccounts: { type: Array,  default: () => [] },
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('fee_catalog.manage') || list.includes('*');
});

const rows = computed(() => props.products.data ?? props.products ?? []);

const showForm = ref(false);
const form = useForm({
    code: '',
    name: '',
    description: '',
    amount: 0,
    currency: 'GHS',
    billing_cycle: 'annual',
    applies_to_classes: [],
    gl_income_account_id: null,
    is_active: true,
});

function submit() {
    form.post(route('billing.fee-catalog.store'), {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); },
    });
}
</script>

<template>
<Head title="Fee catalog — CIHRM" />
<div class="p-6 max-w-7xl mx-auto">
    <header class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-primary">Fee catalog</h1>
            <p class="text-sm text-on-surface-variant">Reusable fee products — Annual Dues, Exam Fees, etc.</p>
        </div>
        <PrimaryButton v-if="canManage" @click="showForm = true">New fee product</PrimaryButton>
    </header>

    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-surface-container">
                <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Amount</th>
                    <th class="px-4 py-3">Cycle</th>
                    <th class="px-4 py-3">Applies to</th>
                    <th class="px-4 py-3">Active</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in rows" :key="p.id" class="border-t border-outline-variant/40 hover:bg-surface-container/50">
                    <td class="px-4 py-2 font-mono text-xs">{{ p.code }}</td>
                    <td class="px-4 py-2 font-semibold">{{ p.name }}</td>
                    <td class="px-4 py-2 tabular-nums">{{ p.currency }} {{ p.amount.toFixed(2) }}</td>
                    <td class="px-4 py-2 capitalize">{{ p.billing_cycle }}</td>
                    <td class="px-4 py-2 capitalize text-xs">
                        <span v-if="!p.applies_to_classes || p.applies_to_classes.length === 0">All</span>
                        <span v-else>{{ p.applies_to_classes.join(', ') }}</span>
                    </td>
                    <td class="px-4 py-2">{{ p.is_active ? 'Yes' : 'No' }}</td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="rows.length === 0" title="No fee products yet" subtitle="Add one to start billing members." />
    </div>

    <SlidePanel v-if="showForm" @close="showForm = false" title="New fee product">
        <form @submit.prevent="submit" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <InputLabel for="code" value="Code" />
                    <TextInput id="code" v-model="form.code" required class="mt-1 w-full" />
                    <InputError :message="form.errors.code" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="amount" value="Amount" />
                    <TextInput id="amount" v-model.number="form.amount" type="number" step="0.01" required class="mt-1 w-full" />
                    <InputError :message="form.errors.amount" class="mt-1" />
                </div>
            </div>
            <div>
                <InputLabel for="name" value="Name" />
                <TextInput id="name" v-model="form.name" required class="mt-1 w-full" />
                <InputError :message="form.errors.name" class="mt-1" />
            </div>
            <div>
                <InputLabel for="billing_cycle" value="Billing cycle" />
                <select id="billing_cycle" v-model="form.billing_cycle" aria-label="Billing cycle" class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                    <option value="once">One-off</option>
                    <option value="annual">Annual</option>
                    <option value="semester">Semester</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
            <div>
                <InputLabel value="Applies to classes (leave empty = all)" />
                <div class="mt-1 flex flex-wrap gap-3 text-sm">
                    <label v-for="c in ['associate','professional','fellow','student','alumni']" :key="c" class="inline-flex items-center gap-2">
                        <input type="checkbox" :value="c" v-model="form.applies_to_classes" :aria-label="`Apply to ${c}`" />
                        <span class="capitalize">{{ c }}</span>
                    </label>
                </div>
            </div>
            <div>
                <InputLabel for="gl_income_account_id" value="GL income account" />
                <select id="gl_income_account_id" v-model.number="form.gl_income_account_id" required aria-label="GL income account" class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                    <option :value="null">— Select —</option>
                    <option v-for="acct in incomeAccounts" :key="acct.id" :value="acct.id">{{ acct.code }} — {{ acct.name }}</option>
                </select>
                <InputError :message="form.errors.gl_income_account_id" class="mt-1" />
            </div>
            <div class="pt-2 flex justify-end gap-2">
                <button type="button" @click="showForm = false" class="rounded-xl px-4 py-2 text-sm">Cancel</button>
                <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
            </div>
        </form>
    </SlidePanel>
</div>
</template>

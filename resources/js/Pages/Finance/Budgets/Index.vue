<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    year:     { type: Number, required: true },
    budget:   { type: Object, required: true },
    accounts: { type: Array,  default: () => [] },
});

const year = ref(props.year);
const isApproved = computed(() => props.budget.status === 'approved');
const draft = reactive(Object.fromEntries(props.accounts.map((a) => [a.id, a.annual_amount])));

const gotoYear = () => router.get(route('finance.budgets.index'), { year: year.value }, { preserveState: false });

const save = (account) => router.post(route('finance.budgets.line'),
    { year: props.year, gl_account_id: account.id, annual_amount: draft[account.id] },
    { preserveScroll: true });

const approve = () => router.post(route('finance.budgets.approve'), { year: props.year }, { preserveScroll: true });
const revert  = () => router.post(route('finance.budgets.revert'),  { year: props.year }, { preserveScroll: true });
</script>

<template>
    <Head title="Budgets" />

    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-primary">Annual Budget</h1>
                <p class="text-on-surface-variant text-sm mt-1">
                    Fiscal year {{ year }} ·
                    <span :class="isApproved ? 'text-emerald-300' : 'text-amber-300'" class="font-bold">{{ budget.status }}</span>
                </p>
            </div>
            <div class="flex items-end gap-3">
                <label class="text-xs font-bold text-on-surface-variant">Year
                    <input type="number" v-model.number="year" aria-label="Fiscal year" @change="gotoYear"
                           class="mt-1 block w-24 rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <button v-if="!isApproved" @click="approve" class="rounded-lg bg-emerald-500/20 px-3 py-2 text-sm font-bold text-emerald-300">Approve</button>
                <button v-else @click="revert" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm font-bold text-primary">Revert to draft</button>
            </div>
        </header>

        <p v-if="isApproved" class="mb-4 text-sm text-on-surface-variant">This budget is approved and read-only. Revert to draft to edit.</p>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[11px] uppercase border-b border-outline-variant/40">
                    <tr><th class="text-left p-3">Code</th><th class="text-left p-3">Account</th><th class="text-left p-3">Type</th><th class="text-right p-3">Annual budget</th><th class="p-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="a in accounts" :key="a.id">
                        <td class="p-3 font-mono text-on-surface-variant">{{ a.code }}</td>
                        <td class="p-3 text-primary">{{ a.name }}</td>
                        <td class="p-3 text-on-surface-variant">{{ a.type }}</td>
                        <td class="p-3 text-right">
                            <input type="number" step="0.01" v-model.number="draft[a.id]" :disabled="isApproved"
                                   :aria-label="`Annual budget for ${a.code}`"
                                   class="w-32 text-right rounded-lg bg-surface-container border-outline-variant/60 text-sm text-primary disabled:opacity-50" />
                        </td>
                        <td class="p-3 text-right">
                            <button v-if="!isApproved" @click="save(a)" class="text-secondary text-xs font-bold hover:underline">Save</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

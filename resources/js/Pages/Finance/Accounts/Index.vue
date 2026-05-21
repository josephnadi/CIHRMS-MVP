<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    tree:    { type: Object, required: true },   // { data: GlAccountResource[] }
    flat:    { type: Object, required: true },   // { data: GlAccountResource[] }
    filters: { type: Object, default: () => ({}) },
});

const treeRows = computed(() => props.tree.data ?? props.tree ?? []);
const flatRows = computed(() => props.flat.data ?? props.flat ?? []);

const typeFilter = ref(props.filters.type ?? '');
const searchTerm = ref(props.filters.search ?? '');

const applyFilters = () => {
    router.get(route('finance.accounts.index'), {
        type:   typeFilter.value || undefined,
        search: searchTerm.value || undefined,
    }, { preserveState: true, replace: true });
};

let searchTimer = null;
watch(searchTerm, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 320);
});

// ── Slide panel ──
const panelOpen  = ref(false);
const editing    = ref(null);

const blank = () => ({ code: '', name: '', type: 'asset', parent_id: null, is_active: true, description: '' });

const form = useForm(blank());

const openNew = () => {
    editing.value = null;
    form.reset();
    Object.assign(form, blank());
    panelOpen.value = true;
};

const openEdit = (account) => {
    editing.value = account;
    Object.assign(form, {
        code:        account.code,
        name:        account.name,
        type:        account.type.value,
        parent_id:   account.parent_id,
        is_active:   account.is_active,
        description: account.description ?? '',
    });
    panelOpen.value = true;
};

const submit = () => {
    if (editing.value) {
        form.patch(route('finance.accounts.update', editing.value.id), {
            onSuccess: () => { panelOpen.value = false; },
        });
    } else {
        form.post(route('finance.accounts.store'), {
            onSuccess: () => { panelOpen.value = false; },
        });
    }
};

const archive = (account) => {
    if (! confirm(`Archive account ${account.code} (${account.name})?`)) return;
    router.delete(route('finance.accounts.destroy', account.id));
};

const typeColor = (typeValue) => ({
    asset:     'text-emerald-700 bg-emerald-50 border-emerald-100',
    liability: 'text-rose-700 bg-rose-50 border-rose-100',
    equity:    'text-violet-700 bg-violet-50 border-violet-100',
    income:    'text-blue-700 bg-blue-50 border-blue-100',
    expense:   'text-amber-700 bg-amber-50 border-amber-100',
}[typeValue] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="Chart of Accounts" />

    <div class="space-y-6 animate-reveal-up">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Chart of Accounts</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    General-ledger account catalogue. {{ flatRows.length }} accounts.
                </p>
            </div>
            <PrimaryButton @click="openNew">
                <span class="material-symbols-outlined text-[16px] mr-1">add</span>
                New Account
            </PrimaryButton>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-2 items-center">
            <button v-for="t in [
                { v: '',          label: 'All' },
                { v: 'asset',     label: 'Assets' },
                { v: 'liability', label: 'Liabilities' },
                { v: 'equity',    label: 'Equity' },
                { v: 'income',    label: 'Income' },
                { v: 'expense',   label: 'Expenses' },
            ]" :key="t.v"
                @click="typeFilter = t.v; applyFilters();"
                :class="['px-3 py-1.5 rounded-full text-[11px] font-bold border transition-colors',
                    typeFilter === t.v
                        ? 'bg-primary text-on-primary border-primary'
                        : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:border-secondary/40']">
                {{ t.label }}
            </button>
            <input v-model="searchTerm" type="text" placeholder="Search code or name..."
                   class="ml-auto rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] bg-surface-container-lowest" />
        </div>

        <!-- Table -->
        <div v-if="flatRows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Code</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Name</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Type</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Balance</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="acc in flatRows" :key="acc.id" class="border-t border-outline-variant/30 hover:bg-surface-container/40">
                        <td class="px-4 py-2 font-mono font-bold text-primary">{{ acc.code }}</td>
                        <td class="px-4 py-2 text-on-surface">{{ acc.name }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="typeColor(acc.type.value)">
                                {{ acc.type.label }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono text-primary">
                            GHS {{ (acc.balance ?? 0).toFixed(2) }}
                        </td>
                        <td class="px-4 py-2 text-right space-x-2">
                            <button @click="openEdit(acc)" class="text-[11px] font-bold text-secondary hover:underline">Edit</button>
                            <button @click="archive(acc)"  class="text-[11px] font-bold text-rose-600 hover:underline">Archive</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="account_tree"
                    title="No accounts match the filters"
                    description="Adjust the type filter or search term, or seed a chart of accounts." />

        <!-- Slide panel -->
        <SlidePanel :open="panelOpen" @close="panelOpen = false"
                    :title="editing ? `Edit ${editing.code}` : 'New GL Account'">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="code" value="Code" />
                    <TextInput id="code" v-model="form.code" class="mt-1 block w-full" />
                    <InputError :message="form.errors.code" />
                </div>
                <div>
                    <InputLabel for="name" value="Name" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" />
                    <InputError :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="type" value="Type" />
                    <select id="type" v-model="form.type"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option value="asset">Asset</option>
                        <option value="liability">Liability</option>
                        <option value="equity">Equity</option>
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                    </select>
                    <InputError :message="form.errors.type" />
                </div>
                <div>
                    <InputLabel for="parent_id" value="Parent account" />
                    <select id="parent_id" v-model="form.parent_id"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">— (root)</option>
                        <option v-for="acc in flatRows" :key="acc.id"
                                :value="acc.id"
                                :disabled="editing && acc.id === editing.id">
                            {{ acc.code }} — {{ acc.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.parent_id" />
                </div>
                <div>
                    <InputLabel for="description" value="Description" />
                    <textarea id="description" v-model="form.description" rows="3"
                              class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                    <InputError :message="form.errors.description" />
                </div>
                <div class="flex items-center gap-2">
                    <input id="is_active" type="checkbox" v-model="form.is_active" class="rounded border-outline-variant" />
                    <label for="is_active" class="text-[12px] font-bold text-on-surface-variant">Active</label>
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false"
                            class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing">
                        {{ editing ? 'Save' : 'Create' }}
                    </PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>

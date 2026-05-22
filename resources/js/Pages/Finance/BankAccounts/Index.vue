<script setup>
import { ref, computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const canManage = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    return Array.isArray(perms)
        ? perms.includes('bank_accounts.manage')
        : (typeof perms === 'function' ? perms().includes('bank_accounts.manage') : false);
});
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    banks:         { type: Object, required: true }, // { data: [...] }
    assetAccounts: { type: Array,  default: () => [] },
    filters:       { type: Object, default: () => ({}) },
});

const rows = computed(() => props.banks.data ?? props.banks ?? []);

const panelOpen = ref(false);
const editing   = ref(null);

const blank = () => ({
    gl_account_id:   null,
    bank_name:       '',
    branch:          '',
    account_name:    '',
    account_number:  '',
    sort_code:       '',
    swift:           '',
    currency:        'GHS',
    purpose:         'operating',
    opening_balance: 0,
    is_active:       true,
    notes:           '',
});

const form = useForm(blank());

const openNew = () => {
    editing.value = null;
    form.reset();
    Object.assign(form, blank());
    panelOpen.value = true;
};

const openEdit = (bank) => {
    editing.value = bank;
    Object.assign(form, {
        gl_account_id:   bank.gl_account?.id ?? null,
        bank_name:       bank.bank_name,
        branch:          bank.branch ?? '',
        account_name:    bank.account_name,
        account_number:  bank.account_number,
        sort_code:       bank.sort_code ?? '',
        swift:           bank.swift ?? '',
        currency:        bank.currency,
        purpose:         bank.purpose.value,
        opening_balance: bank.opening_balance,
        is_active:       bank.is_active,
        notes:           bank.notes ?? '',
    });
    panelOpen.value = true;
};

const submit = () => {
    if (editing.value) {
        form.patch(route('finance.bank-accounts.update', editing.value.id), {
            onSuccess: () => { panelOpen.value = false; },
        });
    } else {
        form.post(route('finance.bank-accounts.store'), {
            onSuccess: () => { panelOpen.value = false; },
        });
    }
};

const archive = (bank) => {
    if (! confirm(`Archive ${bank.bank_name} — ${bank.account_name}?`)) return;
    router.delete(route('finance.bank-accounts.destroy', bank.id));
};

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
});

const purposeColor = (val) => ({
    operating:        'text-blue-700 bg-blue-50 border-blue-100',
    payroll:          'text-emerald-700 bg-emerald-50 border-emerald-100',
    statutory_escrow: 'text-amber-700 bg-amber-50 border-amber-100',
    receipts:         'text-violet-700 bg-violet-50 border-violet-100',
    reserve:          'text-rose-700 bg-rose-50 border-rose-100',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="Organisational Bank Accounts" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Organisational Bank Accounts</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    The institute's own bank accounts. {{ rows.length }} total.
                </p>
            </div>
            <PrimaryButton v-if="canManage" @click="openNew">
                <span class="material-symbols-outlined text-[16px] mr-1">add</span>
                New Bank Account
            </PrimaryButton>
        </div>

        <div v-if="rows.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="bank in rows" :key="bank.id"
                     class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-3">
                <header class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant">{{ bank.bank_name }}</p>
                        <p class="text-[14px] font-black text-primary leading-tight">{{ bank.account_name }}</p>
                        <p class="text-[10px] font-medium text-on-surface-variant mt-0.5">{{ bank.branch || '—' }}</p>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border whitespace-nowrap"
                          :class="purposeColor(bank.purpose.value)">{{ bank.purpose.label }}</span>
                </header>
                <p class="font-mono text-[12px] text-on-surface tracking-wider">{{ bank.account_number }}</p>
                <p class="text-[10px] text-on-surface-variant">GL {{ bank.gl_account?.code }} — {{ bank.gl_account?.name }}</p>
                <p class="text-[13px] font-black text-primary">Opening: {{ cedi(bank.opening_balance) }}</p>
                <div v-if="canManage" class="flex gap-2 pt-1">
                    <button @click="openEdit(bank)" class="text-[11px] font-bold text-secondary hover:underline">Edit</button>
                    <button @click="archive(bank)"  class="text-[11px] font-bold text-rose-600 hover:underline">Archive</button>
                </div>
            </article>
        </div>
        <EmptyState v-else icon="account_balance_wallet"
                    title="No bank accounts yet"
                    description="Add the institute's operating, payroll and escrow accounts." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false"
                    :title="editing ? `Edit ${editing.bank_name}` : 'New Bank Account'">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="gl_account_id" value="Linked GL account (asset)" />
                    <select id="gl_account_id" v-model="form.gl_account_id"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="a in assetAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                    </select>
                    <InputError :message="form.errors.gl_account_id" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="bank_name" value="Bank" />
                        <TextInput id="bank_name" v-model="form.bank_name" class="mt-1 block w-full" />
                        <InputError :message="form.errors.bank_name" />
                    </div>
                    <div>
                        <InputLabel for="branch" value="Branch" />
                        <TextInput id="branch" v-model="form.branch" class="mt-1 block w-full" />
                    </div>
                </div>
                <div>
                    <InputLabel for="account_name" value="Account name" />
                    <TextInput id="account_name" v-model="form.account_name" class="mt-1 block w-full" />
                    <InputError :message="form.errors.account_name" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="account_number" value="Account number" />
                        <TextInput id="account_number" v-model="form.account_number" class="mt-1 block w-full" />
                        <InputError :message="form.errors.account_number" />
                    </div>
                    <div>
                        <InputLabel for="sort_code" value="Sort code" />
                        <TextInput id="sort_code" v-model="form.sort_code" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="swift" value="SWIFT" />
                        <TextInput id="swift" v-model="form.swift" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="currency" value="Currency" />
                        <TextInput id="currency" v-model="form.currency" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="purpose" value="Purpose" />
                        <select id="purpose" v-model="form.purpose"
                                class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                            <option value="operating">Operating</option>
                            <option value="payroll">Payroll</option>
                            <option value="statutory_escrow">Statutory Escrow</option>
                            <option value="receipts">Receipts</option>
                            <option value="reserve">Reserve</option>
                        </select>
                        <InputError :message="form.errors.purpose" />
                    </div>
                    <div>
                        <InputLabel for="opening_balance" value="Opening balance" />
                        <TextInput id="opening_balance" v-model="form.opening_balance" type="number" step="0.01" class="mt-1 block w-full" />
                        <InputError :message="form.errors.opening_balance" />
                    </div>
                </div>
                <div>
                    <InputLabel for="notes" value="Notes" />
                    <textarea id="notes" v-model="form.notes" rows="3"
                              class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
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

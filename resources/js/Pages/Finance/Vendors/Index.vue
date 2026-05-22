<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    vendors:         { type: Object, required: true },
    filters:         { type: Object, default: () => ({}) },
    expenseAccounts: { type: Array,  default: () => [] },
    apAccounts:      { type: Array,  default: () => [] },
    bankAccounts:    { type: Array,  default: () => [] },
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('vendors.manage');
});

const rows = computed(() => props.vendors.data ?? props.vendors ?? []);
const statusFilter = ref(props.filters.status ?? '');
const searchTerm   = ref(props.filters.search ?? '');

const apply = () => router.get(route('finance.vendors.index'), {
    status: statusFilter.value || undefined,
    search: searchTerm.value || undefined,
}, { preserveState: true, replace: true });

let timer = null;
watch(searchTerm, () => { clearTimeout(timer); timer = setTimeout(apply, 320); });

const panelOpen = ref(false);
const editing = ref(null);
const blank = () => ({
    code: '', name: '', tax_id: '', status: 'active', email: '', phone: '', address: '', notes: '',
    default_expense_gl_account_id: null, default_ap_gl_account_id: null, default_bank_account_id: null,
});
const form = useForm(blank());

const openNew = () => { editing.value = null; form.reset(); Object.assign(form, blank()); panelOpen.value = true; };
const openEdit = (v) => {
    editing.value = v;
    Object.assign(form, {
        code: v.code, name: v.name, tax_id: v.tax_id ?? '', status: v.status.value, email: v.email ?? '',
        phone: v.phone ?? '', address: v.address ?? '', notes: v.notes ?? '',
        default_expense_gl_account_id: v.default_expense_gl_account_id,
        default_ap_gl_account_id: v.default_ap_gl_account_id,
        default_bank_account_id: v.default_bank_account_id,
    });
    panelOpen.value = true;
};

const submit = () => {
    if (editing.value) {
        form.patch(route('finance.vendors.update', editing.value.id), { onSuccess: () => panelOpen.value = false });
    } else {
        form.post(route('finance.vendors.store'), { onSuccess: () => panelOpen.value = false });
    }
};

const archive = (v) => {
    if (!confirm(`Archive ${v.code} (${v.name})?`)) return;
    router.delete(route('finance.vendors.destroy', v.id));
};

const statusColor = (val) => ({
    active: 'text-emerald-700 bg-emerald-50 border-emerald-100',
    inactive: 'text-amber-700 bg-amber-50 border-amber-100',
    suspended: 'text-rose-700 bg-rose-50 border-rose-100',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="Vendors" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Vendors</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} vendors on file.</p>
            </div>
            <PrimaryButton v-if="canManage" @click="openNew">
                <span class="material-symbols-outlined text-[16px] mr-1">add</span>New Vendor
            </PrimaryButton>
        </div>

        <div class="flex flex-wrap gap-2 items-center">
            <button v-for="t in [
                { v: '',          label: 'All' },
                { v: 'active',    label: 'Active' },
                { v: 'inactive',  label: 'Inactive' },
                { v: 'suspended', label: 'Suspended' },
            ]" :key="t.v" @click="statusFilter = t.v; apply();"
                :class="['px-3 py-1.5 rounded-full text-[11px] font-bold border transition-colors',
                    statusFilter === t.v ? 'bg-primary text-on-primary border-primary'
                                         : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:border-secondary/40']">
                {{ t.label }}
            </button>
            <input v-model="searchTerm" type="text" placeholder="Search code, name, tax id..."
                   class="ml-auto rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] bg-surface-container-lowest" />
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Code</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Name</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Tax ID</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="v in rows" :key="v.id" class="border-t border-outline-variant/30 hover:bg-surface-container/40">
                        <td class="px-4 py-2 font-mono font-bold text-primary">{{ v.code }}</td>
                        <td class="px-4 py-2 text-on-surface">{{ v.name }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ v.tax_id ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(v.status.value)">{{ v.status.label }}</span>
                        </td>
                        <td v-if="canManage" class="px-4 py-2 text-right space-x-2">
                            <button @click="openEdit(v)"  class="text-[11px] font-bold text-secondary hover:underline">Edit</button>
                            <button @click="archive(v)"   class="text-[11px] font-bold text-rose-600 hover:underline">Archive</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="store" title="No vendors match" description="Adjust the filters or add a new vendor." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false" :title="editing ? `Edit ${editing.code}` : 'New Vendor'">
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="code" value="Code" />
                        <TextInput id="code" v-model="form.code" class="mt-1 block w-full" />
                        <InputError :message="form.errors.code" />
                    </div>
                    <div>
                        <InputLabel for="status" value="Status" />
                        <select id="status" v-model="form.status"
                                class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
                <div>
                    <InputLabel for="name" value="Name" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="tax_id" value="Tax ID" />
                        <TextInput id="tax_id" v-model="form.tax_id" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" type="email" v-model="form.email" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel for="phone" value="Phone" />
                        <TextInput id="phone" v-model="form.phone" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="default_bank_account_id" value="Default bank account" />
                        <select id="default_bank_account_id" v-model="form.default_bank_account_id"
                                class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                            <option :value="null">—</option>
                            <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} — {{ b.account_name }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <InputLabel for="default_expense_gl_account_id" value="Default expense GL" />
                    <select id="default_expense_gl_account_id" v-model="form.default_expense_gl_account_id"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                    </select>
                    <InputError :message="form.errors.default_expense_gl_account_id" />
                </div>
                <div>
                    <InputLabel for="default_ap_gl_account_id" value="Default AP liability GL" />
                    <select id="default_ap_gl_account_id" v-model="form.default_ap_gl_account_id"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">— (defaults to GL 2100)</option>
                        <option v-for="a in apAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                    </select>
                    <InputError :message="form.errors.default_ap_gl_account_id" />
                </div>
                <div>
                    <InputLabel for="address" value="Address" />
                    <textarea id="address" v-model="form.address" rows="2" aria-label="Address" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                </div>
                <div>
                    <InputLabel for="notes" value="Notes" />
                    <textarea id="notes" v-model="form.notes" rows="2" aria-label="Notes" class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing">{{ editing ? 'Save' : 'Create' }}</PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>

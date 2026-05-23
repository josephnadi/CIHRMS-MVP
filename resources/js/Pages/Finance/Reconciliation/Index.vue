<script setup>
import { ref, computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    statements:   { type: Object, default: () => ({ data: [] }) },
    bankAccounts: { type: Array,  default: () => [] },
});

const page = usePage();
const canImport = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('reconciliation.import');
});

const rows = computed(() => props.statements.data ?? props.statements ?? []);

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const panelOpen = ref(false);
const form = useForm({
    org_bank_account_id: null,
    bank_key:            'gcb',
    file:                null,
});

const openUpload = () => {
    form.reset();
    panelOpen.value = true;
};

const submit = () => form.post(route('finance.reconciliation.store'), {
    forceFormData: true,
    onSuccess: () => { panelOpen.value = false; form.reset(); },
});

const open = (id) => router.visit(route('finance.reconciliation.show', id));
</script>

<template>
    <Head title="Bank Reconciliation" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — RECONCILIATION</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Bank Reconciliation</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} imported statements.</p>
            </div>
            <PrimaryButton v-if="canImport" @click="openUpload">
                <span class="material-symbols-outlined text-[16px] mr-1">upload_file</span>Upload Statement
            </PrimaryButton>
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Bank</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Statement Date</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Opening</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Closing</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Progress</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Format</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in rows" :key="s.id" @click="open(s.id)"
                        class="border-t border-outline-variant/30 hover:bg-secondary/5 cursor-pointer">
                        <td class="px-4 py-2 text-on-surface">{{ s.org_bank_account?.bank_name ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono">{{ s.statement_date }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ cedi(s.opening_balance) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ cedi(s.closing_balance) }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 rounded-full bg-surface-container">
                                    <div class="h-full rounded-full bg-emerald-500" :style="{ width: s.reconciled_pct + '%' }"></div>
                                </div>
                                <span class="text-[10px] font-bold text-on-surface-variant">{{ s.reconciled_pct }}%</span>
                            </div>
                        </td>
                        <td class="px-4 py-2 uppercase font-mono text-[10px]">{{ s.format }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="upload_file" title="No statements imported yet" description="Upload a bank statement CSV, OFX, or MT940 file to begin reconciliation." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false" title="Upload Statement">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="org_bank_account_id" value="Bank Account" />
                    <select id="org_bank_account_id" v-model="form.org_bank_account_id" aria-label="Bank Account"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} · {{ b.account_name }}</option>
                    </select>
                    <InputError :message="form.errors.org_bank_account_id" />
                </div>
                <div>
                    <InputLabel for="bank_key" value="Bank Profile (for CSV)" />
                    <select id="bank_key" v-model="form.bank_key" aria-label="Bank profile"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option value="gcb">GCB Bank</option>
                        <option value="stanbic">Stanbic</option>
                        <option value="gtb">GTBank</option>
                        <option value="ecobank">Ecobank</option>
                    </select>
                </div>
                <div>
                    <InputLabel for="file" value="Statement File (.csv, .ofx, .mt940)" />
                    <input id="file" type="file" aria-label="Statement file"
                           @input="form.file = $event.target.files[0]"
                           class="mt-1 block w-full text-[13px] text-on-surface-variant" />
                    <InputError :message="form.errors.file" />
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing || !form.org_bank_account_id || !form.file">Upload</PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>

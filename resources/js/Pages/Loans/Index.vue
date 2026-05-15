<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    loans:        Object,
    products:     Object,
    stats:        Object,
    filters:      Object,
    activeModule: String,
});

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const localFilters = reactive({
    status:     props.filters?.status     ?? '',
    product_id: props.filters?.product_id ?? '',
    q:          props.filters?.q          ?? '',
});

const applyFilters = () => router.get(route('loans.index'), {
    status:     localFilters.status     || undefined,
    product_id: localFilters.product_id || undefined,
    q:          localFilters.q          || undefined,
}, { preserveState: true, replace: true });

// ── Apply slide-panel ────────────────────────────────────────────────────────
const showApply = ref(false);
const form = useForm({
    product_id:  '',
    principal:   '',
    term_months: '',
    purpose:     '',
});

const productList = computed(() => props.products?.data ?? props.products ?? []);
const selectedProduct = computed(() => productList.value.find(p => String(p.id) === String(form.product_id)));

const preview = ref(null);
const previewing = ref(false);
const previewQuote = async () => {
    if (!form.product_id || !form.principal || !form.term_months) {
        preview.value = null;
        return;
    }
    previewing.value = true;
    try {
        const r = await fetch(route('loans.preview'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                product_id:  form.product_id,
                principal:   form.principal,
                term_months: form.term_months,
            }),
        });
        preview.value = r.ok ? await r.json() : null;
    } finally {
        previewing.value = false;
    }
};

const submit = () => form.post(route('loans.store'), {
    preserveScroll: true,
    onSuccess: () => { showApply.value = false; form.reset(); preview.value = null; },
});
</script>

<template>
    <Head title="Loans & Advances" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold tracking-tight">Loans & Advances</h1>
                <PrimaryButton @click="showApply = true">Apply for loan</PrimaryButton>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="Active loans" :value="stats.active_count" />
                <StatCard label="Outstanding (org)" :value="cedi(stats.total_outstanding)" />
                <StatCard label="Pending approval" :value="stats.pending_approval" tone="warn" />
                <StatCard label="Disbursed this year" :value="cedi(stats.disbursed_this_year)" />
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100">
                <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap gap-3 items-center">
                    <input v-model="localFilters.q" @keyup.enter="applyFilters"
                           placeholder="Reference or employee" class="rounded-lg border-slate-200 text-sm flex-1 min-w-[200px]">
                    <select v-model="localFilters.status" @change="applyFilters"
                            class="rounded-lg border-slate-200 text-sm">
                        <option value="">All statuses</option>
                        <option value="pending_approval">Pending approval</option>
                        <option value="approved">Approved</option>
                        <option value="disbursed">Disbursed</option>
                        <option value="repaying">Repaying</option>
                        <option value="paid_off">Paid off</option>
                        <option value="rejected">Rejected</option>
                    </select>
                    <select v-model="localFilters.product_id" @change="applyFilters"
                            class="rounded-lg border-slate-200 text-sm">
                        <option value="">All products</option>
                        <option v-for="p in productList" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>

                <div v-if="(loans?.data?.length ?? 0) === 0">
                    <EmptyState title="No loans yet"
                                description="Submit the first application to begin." />
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Reference</th>
                            <th class="px-5 py-3 text-left">Employee</th>
                            <th class="px-5 py-3 text-left">Product</th>
                            <th class="px-5 py-3 text-right">Principal</th>
                            <th class="px-5 py-3 text-right">Outstanding</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="l in loans.data" :key="l.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3 font-mono text-xs">{{ l.reference }}</td>
                            <td class="px-5 py-3">
                                <div class="font-medium">{{ l.employee?.name ?? '—' }}</div>
                                <div class="text-xs text-slate-500">{{ l.employee?.employee_no }}</div>
                            </td>
                            <td class="px-5 py-3">{{ l.product?.data?.name ?? l.product?.name ?? '—' }}</td>
                            <td class="px-5 py-3 text-right">{{ cedi(l.principal) }}</td>
                            <td class="px-5 py-3 text-right">{{ cedi(l.outstanding_balance) }}</td>
                            <td class="px-5 py-3"><StatusBadge :status="l.status" :label="l.status_label" /></td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('loans.show', l.id)" class="text-secondary hover:underline">Open</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-slate-100">
                    <Pagination :links="loans?.meta?.links ?? []" />
                </div>
            </div>
        </div>

        <SlidePanel v-model="showApply" title="Apply for a loan">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Product</label>
                    <select v-model="form.product_id" @change="previewQuote"
                            class="w-full rounded-lg border-slate-200" required>
                        <option value="">Choose a product</option>
                        <option v-for="p in productList" :key="p.id" :value="p.id">
                            {{ p.name }} — {{ (Number(p.annual_interest_rate) * 100).toFixed(2) }}% pa
                        </option>
                    </select>
                    <p v-if="selectedProduct" class="mt-1 text-xs text-slate-500">
                        Range: {{ cedi(selectedProduct.min_amount) }} – {{ cedi(selectedProduct.max_amount) }},
                        {{ selectedProduct.min_term_months }}–{{ selectedProduct.max_term_months }} months,
                        {{ selectedProduct.amortization_label }}.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Principal (GHS)</label>
                        <input v-model="form.principal" @blur="previewQuote" type="number" min="1" step="0.01"
                               class="w-full rounded-lg border-slate-200" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Term (months)</label>
                        <input v-model="form.term_months" @blur="previewQuote" type="number" min="1" max="240"
                               class="w-full rounded-lg border-slate-200" required>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Purpose</label>
                    <textarea v-model="form.purpose" rows="2" class="w-full rounded-lg border-slate-200"></textarea>
                </div>

                <div v-if="preview" class="rounded-xl bg-secondary/5 border border-secondary/15 p-4 text-sm">
                    <div class="grid grid-cols-2 gap-2">
                        <div><span class="text-slate-500">Monthly installment:</span>
                             <div class="font-semibold text-brand-navy">{{ cedi(preview.monthly_installment) }}</div></div>
                        <div><span class="text-slate-500">Total interest:</span>
                             <div class="font-semibold">{{ cedi(preview.total_interest) }}</div></div>
                        <div><span class="text-slate-500">Total repayable:</span>
                             <div class="font-semibold">{{ cedi(preview.total_repayable) }}</div></div>
                        <div><span class="text-slate-500">Installments:</span>
                             <div class="font-semibold">{{ preview.schedule?.length ?? 0 }}</div></div>
                    </div>
                </div>

                <PrimaryButton type="submit" :disabled="form.processing" class="w-full justify-center">
                    Submit application
                </PrimaryButton>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>

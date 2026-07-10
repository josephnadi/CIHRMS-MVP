<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import GlossaryText from '@/Components/GlossaryText.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    plans: Object,
});

// One slide-panel + form for both create and edit; `editing` decides which
// route is hit on submit.
const showPanel = ref(false);
const editing   = ref(null);
const planForm = useForm({
    name: '', code: '', type: 'health_insurance', provider: '', description: '',
    monthly_cost: null, employee_contribution_percentage: 100,
    is_active: true, effective_from: new Date().toISOString().slice(0,10), effective_to: null,
    max_dependants: 4,
});

function openCreate() {
    editing.value = null;
    planForm.reset();
    planForm.clearErrors();
    showPanel.value = true;
}

function openEdit(plan) {
    editing.value = plan;
    planForm.code                              = plan.code ?? '';
    planForm.name                              = plan.name ?? '';
    planForm.type                              = plan.type ?? 'health_insurance';
    planForm.provider                          = plan.provider ?? '';
    planForm.description                       = plan.description ?? '';
    planForm.monthly_cost                      = plan.monthly_cost ?? null;
    planForm.employee_contribution_percentage  = plan.employee_contribution_percentage ?? 100;
    planForm.is_active                         = plan.is_active !== false;
    planForm.effective_from                    = plan.effective_from ?? new Date().toISOString().slice(0,10);
    planForm.effective_to                      = plan.effective_to ?? null;
    planForm.max_dependants                    = plan.max_dependants ?? 4;
    planForm.clearErrors();
    showPanel.value = true;
}

function submitPlan() {
    if (editing.value) {
        planForm.patch(route('benefits.plans.update', editing.value.id), {
            preserveScroll: true,
            onSuccess: () => { showPanel.value = false; editing.value = null; planForm.reset(); },
        });
    } else {
        planForm.post(route('benefits.plans.store'), {
            preserveScroll: true,
            onSuccess: () => { showPanel.value = false; planForm.reset(); },
        });
    }
}

function deletePlan(plan) {
    if (! window.confirm(`Delete benefit plan "${plan.name}"?\n\nActive enrolments will block deletion — close them first if so.`)) return;
    router.delete(route('benefits.plans.destroy', plan.id), { preserveScroll: true });
}

const typeLabel = {
    health_insurance: 'Health', provident_fund: 'Provident',
    life_insurance: 'Life', dental: 'Dental', vision: 'Vision',
    wellness: 'Wellness', other: 'Other',
};
</script>

<template>
<Head title="Benefit Plans" />
    <div data-page-root="true">
        <div class="p-6 space-y-6 animate-reveal-up">
            <header class="flex items-center justify-between">
                <div>
                    <Link :href="route('benefits.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← My Benefits</Link>
                    <div class="flex items-center gap-2 mt-1 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">card_membership</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80"><GlossaryText text="Benefits catalogue · HR administration" /></p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Benefit Plans</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Curated catalogue of plans employees can enrol in — premiums, dependants caps, eligibility windows.
                    </p>
                </div>
                <button @click="openCreate" type="button" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white shadow-glow-sm">+ New Plan</button>
            </header>

            <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
                <div v-if="props.plans.data?.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                        <th class="p-4">Code</th><th>Name</th><th>Type</th><th>Provider</th><th>Monthly</th><th>Cover %</th><th>Max Dep.</th><th>Status</th><th class="pr-4 text-right">Actions</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="p in props.plans.data" :key="p.id" class="border-t border-outline-variant/40 hover:bg-surface-container-low/30 transition-colors">
                            <td class="p-4 font-mono">{{ p.code }}</td>
                            <td>{{ p.name }} <span class="text-xs text-on-surface-variant">{{ p.effective_from }} → {{ p.effective_to ?? 'open' }}</span></td>
                            <td>{{ typeLabel[p.type] }}</td>
                            <td class="text-xs">{{ p.provider ?? '—' }}</td>
                            <td class="font-mono">GHS {{ Number(p.monthly_cost).toFixed(2) }}</td>
                            <td class="text-xs">{{ p.employee_contribution_percentage }}%</td>
                            <td class="text-xs">{{ p.max_dependants }}</td>
                            <td><span v-if="p.is_active" class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400">ACTIVE</span><span v-else class="text-[10px] font-bold text-on-surface-variant">inactive</span></td>
                            <td class="pr-4 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-1">
                                    <button type="button" @click="openEdit(p)"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant/70 hover:bg-secondary/10 hover:text-secondary transition-colors"
                                            title="Edit plan" aria-label="Edit plan">
                                        <span class="material-symbols-outlined text-[15px]">edit</span>
                                    </button>
                                    <button type="button" @click="deletePlan(p)"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant/70 hover:bg-rose-50 dark:hover:bg-rose-900/30 hover:text-rose-600 dark:hover:text-rose-400 transition-colors"
                                            title="Delete plan" aria-label="Delete plan">
                                        <span class="material-symbols-outlined text-[15px]">delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <EmptyState v-else title="No plans defined yet." class="py-12" />
            </section>
        </div>

        <SlidePanel :open="showPanel" @close="showPanel = false; editing = null"
                    :title="editing ? `Edit ${editing.name}` : 'Create Benefit Plan'" size="lg">
            <form @submit.prevent="submitPlan" class="space-y-3 p-4">
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Code</label><input v-model="planForm.code" aria-label="Plan code" maxlength="40" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 font-mono uppercase mt-1" /></div>
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Name</label><input v-model="planForm.name" aria-label="Plan name" maxlength="120" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Type</label><select v-model="planForm.type" aria-label="Plan type" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option v-for="(label, key) in typeLabel" :key="key" :value="key">{{ label }}</option></select></div>
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Provider</label><input v-model="planForm.provider" aria-label="Provider name" maxlength="120" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Monthly Cost (GHS)</label><input v-model.number="planForm.monthly_cost" aria-label="Monthly cost in GHS" type="number" step="0.01" min="0" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Employee Cover %</label><input v-model.number="planForm.employee_contribution_percentage" aria-label="Employee contribution percentage" type="number" step="0.01" min="0" max="100" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Effective From</label><input v-model="planForm.effective_from" aria-label="Effective-from date" type="date" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Effective To</label><input v-model="planForm.effective_to" aria-label="Effective-to date" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                    <div><label class="text-[11px] font-bold text-on-surface-variant">Max Dependants</label><input v-model.number="planForm.max_dependants" aria-label="Maximum number of dependants" type="number" min="0" max="50" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                    <div class="col-span-2 flex items-center gap-2">
                        <input id="plan-active" v-model="planForm.is_active" type="checkbox" class="rounded border-outline-variant" />
                        <label for="plan-active" class="text-[12px] font-semibold text-on-surface">Active (enrolable)</label>
                    </div>
                </div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Description</label><textarea v-model="planForm.description" aria-label="Plan description" rows="2" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
                <button type="submit" :disabled="planForm.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">
                    {{ editing ? 'Update Plan' : 'Create Plan' }}
                </button>
            </form>
        </SlidePanel>
    </div>
</template>

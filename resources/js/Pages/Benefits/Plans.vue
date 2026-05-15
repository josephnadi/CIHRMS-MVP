<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    plans: Object,
});

const showCreate = ref(false);
const newPlan = useForm({
    name: '', code: '', type: 'health_insurance', provider: '', description: '',
    monthly_cost: null, employee_contribution_percentage: 100,
    is_active: true, effective_from: new Date().toISOString().slice(0,10), effective_to: null,
    max_dependants: 4,
});

function createPlan() {
    newPlan.post(route('benefits.plans.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; newPlan.reset(); },
    });
}

const typeLabel = {
    health_insurance: 'Health', provident_fund: 'Provident',
    life_insurance: 'Life', dental: 'Dental', vision: 'Vision',
    wellness: 'Wellness', other: 'Other',
};
</script>

<template>
<Head title="Benefit Plans" />
<AuthenticatedLayout active-module="benefits">
    <div class="p-6 space-y-6 animate-reveal-up">
        <header class="flex items-center justify-between">
            <div>
                <Link :href="route('benefits.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← My Benefits</Link>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary mt-1">Benefit Plans</h1>
                <p class="text-sm text-on-surface-variant">HR-managed catalogue of plans employees can enrol in.</p>
            </div>
            <button @click="showCreate = true" type="button" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white shadow-glow-sm">+ New Plan</button>
        </header>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
            <table v-if="props.plans.data?.length" class="w-full text-sm">
                <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="p-4">Code</th><th>Name</th><th>Type</th><th>Provider</th><th>Monthly</th><th>Cover %</th><th>Max Dep.</th><th>Status</th>
                </tr></thead>
                <tbody>
                    <tr v-for="p in props.plans.data" :key="p.id" class="border-t border-outline-variant/40">
                        <td class="p-4 font-mono">{{ p.code }}</td>
                        <td>{{ p.name }} <span class="text-xs text-on-surface-variant">{{ p.effective_from }} → {{ p.effective_to ?? 'open' }}</span></td>
                        <td>{{ typeLabel[p.type] }}</td>
                        <td class="text-xs">{{ p.provider ?? '—' }}</td>
                        <td class="font-mono">GHS {{ p.monthly_cost.toFixed(2) }}</td>
                        <td class="text-xs">{{ p.employee_contribution_percentage }}%</td>
                        <td class="text-xs">{{ p.max_dependants }}</td>
                        <td><span v-if="p.is_active" class="text-[10px] font-bold text-emerald-700">ACTIVE</span><span v-else class="text-[10px] font-bold text-on-surface-variant">inactive</span></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="No plans defined yet." class="py-12" />
        </section>
    </div>

    <SlidePanel :open="showCreate" @close="showCreate = false" title="Create Benefit Plan" size="lg">
        <form @submit.prevent="createPlan" class="space-y-3 p-4">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Code</label><input v-model="newPlan.code" maxlength="40" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 font-mono uppercase mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Name</label><input v-model="newPlan.name" maxlength="120" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Type</label><select v-model="newPlan.type" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option v-for="(label, key) in typeLabel" :key="key" :value="key">{{ label }}</option></select></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Provider</label><input v-model="newPlan.provider" maxlength="120" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Monthly Cost (GHS)</label><input v-model.number="newPlan.monthly_cost" type="number" step="0.01" min="0" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Employee Cover %</label><input v-model.number="newPlan.employee_contribution_percentage" type="number" step="0.01" min="0" max="100" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Effective From</label><input v-model="newPlan.effective_from" type="date" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Effective To</label><input v-model="newPlan.effective_to" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Max Dependants</label><input v-model.number="newPlan.max_dependants" type="number" min="0" max="50" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            </div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Description</label><textarea v-model="newPlan.description" rows="2" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
            <button type="submit" :disabled="newPlan.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Create Plan</button>
        </form>
    </SlidePanel>
</AuthenticatedLayout>
</template>

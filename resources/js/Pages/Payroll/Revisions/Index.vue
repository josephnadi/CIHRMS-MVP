<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    revisions:    { type: Array, default: () => [] },
    grades:       { type: Array, default: () => [] },
    steps:        { type: Array, default: () => [] },
    activeModule: String,
});

const ghs = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmt = (d) => d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const form = useForm({
    percentage: 10,
    effective_from: new Date().toISOString().slice(0, 10),
    scope: 'institute',
    overrides: [],   // [{ grade_id, percentage }]
    notes: '',
});

const addOverride = () => form.overrides.push({ grade_id: props.grades[0]?.id ?? null, percentage: form.percentage });
const removeOverride = (i) => form.overrides.splice(i, 1);

// Client-side preview: old → new per grade-step.
const rateFor = (gradeId) => {
    if (form.scope === 'grade') {
        const o = form.overrides.find(x => Number(x.grade_id) === Number(gradeId));
        if (o) return Number(o.percentage) || 0;
    }
    return Number(form.percentage) || 0;
};
const preview = computed(() => props.steps.map(s => {
    const rate = rateFor(s.grade_id);
    return { ...s, rate, next: Math.round(s.base * (1 + rate / 100) * 100) / 100 };
}));
const previewTotal = computed(() => ({
    old: preview.value.reduce((a, r) => a + r.base, 0),
    next: preview.value.reduce((a, r) => a + r.next, 0),
}));

const submit = () => form.post(route('salary-revisions.store'), {
    preserveScroll: true,
    onSuccess: () => { form.reset('notes'); form.overrides = []; },
});
</script>

<template>
    <Head title="Salary Revisions" />
    <div class="p-6 max-w-5xl mx-auto space-y-6">
        <header>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Payroll</p>
            <h1 class="text-2xl font-black text-primary">Salary Revisions</h1>
            <p class="text-sm text-on-surface-variant mt-1">Apply an across-the-board % increase. New rates take effect from the chosen date; historical payroll is unchanged.</p>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <!-- Form -->
            <form @submit.prevent="submit" class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-4 self-start">
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1">Increase %</label>
                    <input v-model.number="form.percentage" type="number" step="0.01" aria-label="Increase percentage"
                           class="w-full rounded-lg border-outline-variant text-sm" />
                    <p v-if="form.errors.percentage" class="text-[11px] text-rose-600 mt-1">{{ form.errors.percentage }}</p>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1">Effective from</label>
                    <input v-model="form.effective_from" type="date" aria-label="Effective from"
                           class="w-full rounded-lg border-outline-variant text-sm" />
                    <p v-if="form.errors.effective_from" class="text-[11px] text-rose-600 mt-1">{{ form.errors.effective_from }}</p>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1">Scope</label>
                    <select v-model="form.scope" aria-label="Scope" class="w-full rounded-lg border-outline-variant text-sm">
                        <option value="institute">Whole institute</option>
                        <option value="grade">Per-grade overrides</option>
                    </select>
                </div>

                <div v-if="form.scope === 'grade'" class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Grade overrides</span>
                        <button type="button" @click="addOverride" class="text-[11px] font-bold text-secondary hover:underline">+ Add</button>
                    </div>
                    <div v-for="(o, i) in form.overrides" :key="i" class="flex items-center gap-2">
                        <select v-model="o.grade_id" aria-label="Grade" class="flex-1 rounded-lg border-outline-variant text-[12px]">
                            <option v-for="g in grades" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
                        </select>
                        <input v-model.number="o.percentage" type="number" step="0.01" aria-label="Grade percentage"
                               class="w-20 rounded-lg border-outline-variant text-[12px]" />
                        <button type="button" @click="removeOverride(i)" class="text-rose-500 text-lg leading-none">×</button>
                    </div>
                    <p class="text-[11px] text-on-surface-variant">Grades without an override use the institute %.</p>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1">Notes</label>
                    <textarea v-model="form.notes" rows="2" aria-label="Notes" class="w-full rounded-lg border-outline-variant text-sm"></textarea>
                </div>

                <PrimaryButton type="submit" :disabled="form.processing">Apply revision</PrimaryButton>
            </form>

            <!-- Live preview -->
            <div class="lg:col-span-3 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-black uppercase tracking-wide text-secondary/80">Preview</h2>
                    <span class="text-[11px] text-on-surface-variant">{{ preview.length }} grade-step rate{{ preview.length === 1 ? '' : 's' }}</span>
                </div>
                <div v-if="preview.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-on-surface-variant text-[10px] uppercase"><tr>
                            <th class="text-left p-2">Grade / Step</th><th class="text-right p-2">%</th>
                            <th class="text-right p-2">Current</th><th class="text-right p-2">New</th>
                        </tr></thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="r in preview" :key="r.grade_id + '-' + r.step">
                                <td class="p-2 text-primary">{{ r.grade_code }} / {{ r.step }}</td>
                                <td class="p-2 text-right">{{ r.rate }}%</td>
                                <td class="p-2 text-right text-on-surface-variant">{{ ghs(r.base) }}</td>
                                <td class="p-2 text-right font-bold text-primary">{{ ghs(r.next) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="font-black border-t border-outline-variant/50"><tr>
                            <td class="p-2" colspan="2">Total</td>
                            <td class="p-2 text-right">{{ ghs(previewTotal.old) }}</td>
                            <td class="p-2 text-right">{{ ghs(previewTotal.next) }}</td>
                        </tr></tfoot>
                    </table>
                </div>
                <p v-else class="text-sm text-on-surface-variant py-8 text-center">No grade-step rates defined yet.</p>
            </div>
        </div>

        <!-- History -->
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <div class="px-5 py-3 border-b border-outline-variant/50"><h2 class="text-sm font-black uppercase tracking-wide text-on-surface-variant">Revision history</h2></div>
            <table v-if="revisions.length" class="w-full text-sm">
                <thead class="text-on-surface-variant text-[10px] uppercase bg-surface-container-low/20"><tr>
                    <th class="text-left p-3">Reference</th><th class="text-right p-3">%</th><th class="text-left p-3">Effective</th>
                    <th class="text-left p-3">Scope</th><th class="text-right p-3">Steps</th><th class="text-left p-3">By</th>
                </tr></thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="r in revisions" :key="r.id">
                        <td class="p-3 font-mono text-primary">{{ r.reference }}</td>
                        <td class="p-3 text-right">{{ r.percentage }}%</td>
                        <td class="p-3">{{ fmt(r.effective_from) }}</td>
                        <td class="p-3 capitalize">{{ r.scope }}</td>
                        <td class="p-3 text-right">{{ r.affected_count }}</td>
                        <td class="p-3 text-on-surface-variant">{{ r.applied_by?.name ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="p-6 text-center text-sm text-on-surface-variant">No revisions applied yet.</p>
        </div>
    </div>
</template>

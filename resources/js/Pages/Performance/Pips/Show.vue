<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';

const props = defineProps({
    pip:          Object,
    activeModule: String,
});

const P = computed(() => props.pip.data ?? props.pip);

const checkinForm = useForm({ note: '', met_target: false });
const submitCheckin = () => checkinForm.post(route('performance.pips.checkin', P.value.id), {
    preserveScroll: true,
    onSuccess: () => checkinForm.reset(),
});

const extendForm = useForm({ additional_days: 30, reason: '' });
const submitExtend = () => extendForm.post(route('performance.pips.extend', P.value.id), {
    preserveScroll: true,
    onSuccess: () => extendForm.reset('reason'),
});

const closeForm = useForm({ outcome: 'succeeded', summary: '' });
const submitClose = () => closeForm.post(route('performance.pips.close', P.value.id), {
    preserveScroll: true,
});

const isClosed = computed(() => ['succeeded', 'failed_demoted', 'failed_terminated', 'cancelled'].includes(P.value.status));
</script>

<template>
    <Head :title="`PIP — ${P.employee?.name}`" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="route('performance.pips.index')" class="text-xs text-on-surface-variant/60 hover:underline">← All PIPs</Link>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ P.employee?.name }}</h1>
                    <p class="text-sm text-on-surface-variant/70">
                        Opened {{ P.opened_on }} · Target end {{ P.target_end_date }} ·
                        Mentor: {{ P.mentor?.name ?? '—' }}
                    </p>
                </div>
                <StatusBadge :status="P.status" :label="P.status_label" class="text-base" />
            </div>
        </template>

        <div class="py-6 space-y-6">
            <!-- Target metrics -->
            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40 p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-3">Target metrics</p>
                <ul class="space-y-2 text-sm">
                    <li v-for="(m, i) in P.target_metrics ?? []" :key="i" class="flex justify-between">
                        <span>{{ m.metric }}</span>
                        <span class="font-mono">Target: {{ m.target }}</span>
                    </li>
                </ul>
            </div>

            <!-- Check-in log -->
            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40">
                <div class="px-5 py-4 border-b border-outline-variant/40">
                    <h2 class="font-semibold">Check-in log ({{ P.checkins?.length ?? 0 }})</h2>
                </div>
                <div v-if="(P.checkins ?? []).length === 0" class="px-5 py-8 text-center text-sm text-on-surface-variant/60">
                    No check-ins logged yet.
                </div>
                <div v-else class="divide-y divide-outline-variant/30">
                    <div v-for="(c, i) in P.checkins" :key="i" class="px-5 py-3">
                        <div class="flex justify-between items-baseline text-xs text-on-surface-variant/70">
                            <span>{{ c.date }}</span>
                            <span :class="c.met_target ? 'text-emerald-700' : 'text-rose-700'" class="font-semibold">
                                {{ c.met_target ? 'Met target' : 'Did not meet' }}
                            </span>
                        </div>
                        <p class="text-sm mt-1 whitespace-pre-wrap">{{ c.note }}</p>
                    </div>
                </div>

                <form v-if="!isClosed" @submit.prevent="submitCheckin"
                      class="px-5 py-4 border-t border-outline-variant/40 space-y-3 bg-brand-navy/[0.02]">
                    <p class="text-xs font-semibold">Add check-in</p>
                    <textarea v-model="checkinForm.note" rows="3" required
                              class="w-full rounded-lg border-outline-variant text-sm"
                              placeholder="Notes on observed progress, blockers, and next steps."></textarea>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="checkinForm.met_target" aria-label="Targets met for this check-in" type="checkbox">
                        Targets met for this check-in period
                    </label>
                    <PrimaryButton type="submit" :disabled="checkinForm.processing">Record check-in</PrimaryButton>
                </form>
            </div>

            <!-- Extension / Close -->
            <div v-if="!isClosed" class="grid md:grid-cols-2 gap-5">
                <div class="rounded-2xl border border-outline-variant/40 p-5 space-y-3 bg-amber-50/30">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800">Extend PIP</p>
                    <p class="text-xs text-on-surface-variant/70">Extensions used: {{ P.extensions_used }} / {{ P.max_extensions }}</p>
                    <input v-model.number="extendForm.additional_days" type="number" min="14" max="90"
                           class="w-full rounded-lg border-outline-variant text-sm" placeholder="Additional days">
                    <textarea v-model="extendForm.reason" rows="2" required
                              class="w-full rounded-lg border-outline-variant text-sm"
                              placeholder="Reason for extension"></textarea>
                    <PrimaryButton @click="submitExtend" :disabled="extendForm.processing || P.extensions_used >= P.max_extensions">
                        Extend
                    </PrimaryButton>
                </div>

                <div class="rounded-2xl border border-outline-variant/40 p-5 space-y-3 bg-brand-navy/[0.03]">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Close PIP (2FA required)</p>
                    <select v-model="closeForm.outcome" aria-label="PIP close outcome" class="w-full rounded-lg border-outline-variant text-sm">
                        <option value="succeeded">Succeeded — return to normal cycle</option>
                        <option value="failed_demoted">Failed — Demote</option>
                        <option value="failed_terminated">Failed — Terminate (opens off-boarding)</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <textarea v-model="closeForm.summary" rows="3" required minlength="10"
                              class="w-full rounded-lg border-outline-variant text-sm"
                              placeholder="Outcome summary — included in employee's permanent record."></textarea>
                    <DangerButton v-if="closeForm.outcome.startsWith('failed')" @click="submitClose" :disabled="closeForm.processing">
                        Close as {{ closeForm.outcome.replace('_', ' ') }}
                    </DangerButton>
                    <PrimaryButton v-else @click="submitClose" :disabled="closeForm.processing">
                        Close as {{ closeForm.outcome }}
                    </PrimaryButton>
                </div>
            </div>

            <div v-else class="bg-surface-container-low/60 rounded-2xl border border-outline-variant/40 p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-1">Outcome</p>
                <p class="text-sm">{{ P.outcome_summary }}</p>
                <p class="text-xs text-on-surface-variant/60 mt-2">Closed {{ P.actual_end_date }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

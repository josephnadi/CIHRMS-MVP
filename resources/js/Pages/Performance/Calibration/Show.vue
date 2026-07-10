<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    session:               Object,
    reviews:               Array,
    actual_distribution:   Object,
    activeModule:          String,
});

const S = computed(() => props.session.data ?? props.session);

// Map adjustments by review_id for quick lookup
const adjustmentByReview = computed(() => {
    const m = {};
    for (const a of (S.value.adjustments ?? [])) m[a.review_id] = a;
    return m;
});

const adjustForm = useForm({ review_id: null, adjusted_rating: 3, reason: '' });
const startAdjust = (review) => {
    adjustForm.review_id       = review.id;
    adjustForm.adjusted_rating = review.overall_rating;
    adjustForm.reason          = '';
};
const submitAdjust = () => adjustForm.post(route('performance.calibration.adjust', S.value.id), {
    preserveScroll: true,
    onSuccess: () => { adjustForm.reset(); },
});

const lock  = () => router.post(route('performance.calibration.lock',  S.value.id), {}, { preserveScroll: true });
const apply = () => router.post(route('performance.calibration.apply', S.value.id), {}, { preserveScroll: true });
const reopen = () => {
    if (! window.confirm('Reopen this locked session?\n\nAdjustments will be editable again. The applier will need to re-apply after the new lock.')) return;
    router.post(route('performance.calibration.reopen', S.value.id), {}, { preserveScroll: true });
};

// Distribution comparison bands
const bands = ['5', '4', '3', '2', '1'];
const tgt = (b) => Math.round(((S.value.target_distribution ?? {})[b] ?? 0) * 100);
const act = (b) => Math.round(((props.actual_distribution     ?? {})[b] ?? 0) * 100);
</script>

<template>
    <Head :title="`Calibration — ${S.cycle?.name}`" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex items-center justify-between">
                    <div>
                        <Link :href="route('performance.calibration.index')" class="text-xs text-on-surface-variant/60 hover:underline">← All sessions</Link>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">{{ S.cycle?.name }} — Calibration</h1>
                        <p class="text-sm text-on-surface-variant/70">{{ S.department?.name ?? 'Org-wide' }}</p>
                    </div>
                    <StatusBadge :status="S.status" :label="S.status_label" class="text-base" />
                </div>
            </Teleport>

            <div class="space-y-6 animate-reveal-up py-6">
                <!-- Distribution comparison -->
                <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40 p-5">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60 mb-3">Distribution vs target</p>
                    <div class="space-y-2">
                        <div v-for="b in bands" :key="b" class="flex items-center gap-3 text-xs">
                            <span class="w-8 font-bold">{{ b }}★</span>
                            <div class="flex-1 grid grid-cols-2 gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="w-12 text-on-surface-variant/70">Target</span>
                                    <div class="h-2 flex-1 bg-outline-variant/30 rounded-full overflow-hidden">
                                        <div class="h-full bg-brand-navy/40" :style="{ width: `${tgt(b)}%` }"></div>
                                    </div>
                                    <span class="w-10 text-right">{{ tgt(b) }}%</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-12 text-on-surface-variant/70">Actual</span>
                                    <div class="h-2 flex-1 bg-outline-variant/30 rounded-full overflow-hidden">
                                        <div class="h-full" :class="act(b) > tgt(b) ? 'bg-amber-500' : 'bg-emerald-500'"
                                             :style="{ width: `${act(b)}%` }"></div>
                                    </div>
                                    <span class="w-10 text-right font-semibold">{{ act(b) }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reviews to calibrate -->
                <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40">
                    <div class="px-5 py-4 border-b border-outline-variant/40 flex justify-between">
                        <h2 class="font-semibold">Reviews in scope ({{ reviews.length }})</h2>
                        <div class="flex gap-3">
                            <PrimaryButton v-if="S.status === 'in_progress'" @click="lock">Lock session</PrimaryButton>
                            <button v-if="S.status === 'locked'"
                                    type="button"
                                    @click="reopen"
                                    class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-2 text-[13px] font-bold text-amber-700 hover:bg-amber-100 transition-colors">
                                <span class="material-symbols-outlined text-[16px] align-middle mr-1">lock_open</span>
                                Reopen
                            </button>
                            <PrimaryButton v-if="S.status === 'locked'" @click="apply">
                                Apply adjustments (2FA · dual control)
                            </PrimaryButton>
                        </div>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                            <tr>
                                <th class="px-5 py-3 text-left">Employee</th>
                                <th class="px-5 py-3 text-right">Original</th>
                                <th class="px-5 py-3 text-right">Adjusted</th>
                                <th class="px-5 py-3 text-left">Reason</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="r in reviews" :key="r.id"
                                :class="adjustmentByReview[r.id] ? 'bg-brand-navy/[0.03]' : ''">
                                <td class="px-5 py-3">
                                    <div class="font-medium">{{ r.employee?.name }}</div>
                                    <div class="text-xs text-on-surface-variant/60">{{ r.employee?.no }}</div>
                                </td>
                                <td class="px-5 py-3 text-right font-mono">{{ r.overall_rating.toFixed(2) }}</td>
                                <td class="px-5 py-3 text-right font-mono font-semibold">
                                    <span v-if="adjustmentByReview[r.id]"
                                          :class="adjustmentByReview[r.id].adjusted_rating > adjustmentByReview[r.id].original_rating ? 'text-emerald-700' : adjustmentByReview[r.id].adjusted_rating < adjustmentByReview[r.id].original_rating ? 'text-rose-700' : ''">
                                        {{ Number(adjustmentByReview[r.id].adjusted_rating).toFixed(2) }}
                                    </span>
                                    <span v-else class="text-on-surface-variant/40">—</span>
                                </td>
                                <td class="px-5 py-3 text-xs">{{ adjustmentByReview[r.id]?.reason ?? '' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <button v-if="S.status === 'in_progress'" @click="startAdjust(r)"
                                            class="text-secondary text-xs hover:underline">
                                        {{ adjustmentByReview[r.id] ? 'Re-adjust' : 'Adjust' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Inline adjust form (slides in when a review is selected) -->
                    <form v-if="adjustForm.review_id" @submit.prevent="submitAdjust"
                          class="p-5 border-t border-outline-variant/40 bg-amber-50/30 space-y-3">
                        <p class="text-xs font-semibold">Adjust rating for review #{{ adjustForm.review_id }}</p>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs text-on-surface-variant mb-1">New rating (1-5)</label>
                                <input aria-label="New rating (1-5)" v-model.number="adjustForm.adjusted_rating" type="number" step="0.25" min="1" max="5"
                                       class="w-full rounded-lg border-outline-variant text-sm"
                                       :class="{ 'border-red-400': adjustForm.errors.adjusted_rating }">
                                <p v-if="adjustForm.errors.adjusted_rating" class="mt-1 text-[11px] text-red-500">{{ adjustForm.errors.adjusted_rating }}</p>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs text-on-surface-variant mb-1">Reason</label>
                                <input v-model="adjustForm.reason" aria-label="Adjustment reason" class="w-full rounded-lg border-outline-variant text-sm" required
                                       :class="{ 'border-red-400': adjustForm.errors.reason }">
                                <p v-if="adjustForm.errors.reason" class="mt-1 text-[11px] text-red-500">{{ adjustForm.errors.reason }}</p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <PrimaryButton type="submit" :disabled="adjustForm.processing">Save adjustment</PrimaryButton>
                            <button type="button" @click="adjustForm.review_id = null" class="text-xs text-on-surface-variant/60 hover:underline">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
    </div>
</template>

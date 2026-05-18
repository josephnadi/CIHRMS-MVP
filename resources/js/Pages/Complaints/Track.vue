<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import StatusBadge from '@/Components/StatusBadge.vue';

const props = defineProps({
    complaint: Object,
    reference: String,
});

const trackRef = ref(props.reference ?? '');
const c = computed(() => props.complaint?.data ?? props.complaint);

const doTrack = () => {
    if (!trackRef.value.trim()) return;
    router.get(route('complaints.track'), { reference: trackRef.value.trim() });
};

const formatDate = (d) => {
    if (!d) return 'â€”';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};
</script>

<template>
    <Head title="Track Complaint" />
    <div class="mx-auto max-w-2xl px-6 py-8 space-y-6">
        <div>
            <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Track Complaint</h2>
            <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                Look up the status of a submitted complaint using its reference number.
            </p>
        </div>

        <div class="max-w-2xl space-y-5">

            <Link :href="route('complaints.index')" class="inline-flex items-center gap-1 text-[12px] font-semibold text-on-surface-variant/70 hover:text-secondary">
                <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                Back to governance
            </Link>

            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                <h3 class="text-[14px] font-bold text-on-surface mb-3">Reference Lookup</h3>
                <div class="flex gap-2">
                    <input
                        v-model="trackRef"
                        type="text"
                        placeholder="CMP-XXXXXXXX"
                        class="flex-1 rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] font-mono text-on-surface uppercase placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        @keyup.enter="doTrack"
                    />
                    <button
                        @click="doTrack"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">search</span>
                        Track
                    </button>
                </div>
            </div>

            <!-- Result -->
            <div v-if="c" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <p class="font-mono text-[16px] font-bold text-secondary">{{ c.reference }}</p>
                    <StatusBadge :status="c.status" type="complaint" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1">Submitted</p>
                        <p class="text-[13px] text-on-surface">{{ formatDate(c.created_at) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1">Last Updated</p>
                        <p class="text-[13px] text-on-surface">{{ formatDate(c.updated_at) }}</p>
                    </div>
                </div>
            </div>

            <div v-else-if="reference" class="rounded-2xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 p-5 flex items-start gap-3">
                <span class="material-symbols-outlined text-[24px] text-amber-600">search_off</span>
                <div>
                    <h3 class="text-[14px] font-bold text-amber-800 dark:text-amber-200">Reference Not Found</h3>
                    <p class="mt-1 text-[12px] text-amber-700 dark:text-amber-300">
                        We couldn't find a complaint with reference
                        <span class="font-mono font-bold">{{ reference }}</span>. Double-check the reference and try again.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

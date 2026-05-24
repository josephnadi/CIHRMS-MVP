<script setup>
import EmptyState from '@/Components/EmptyState.vue';

// The previous implementation rendered an elaborate Marketing operations
// console (active campaigns, content kanban, social-media metrics, budget
// tracker, team roster) built entirely from hardcoded sample arrays. Until
// the Marketing department metrics are wired through the dashboard
// controller, show an honest empty state so real users aren't misled by
// mock data.
defineProps({
    spark: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="space-y-8 animate-reveal-up">

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">campaign</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">BRAND &amp; COMMS</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Marketing &amp; Communications</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Campaign ROI, channel performance, brand sentiment — every cedi and every lead in one frame.
                </p>
            </div>
        </div>

        <!-- ─── Awaiting integrations ─────────────────────────────── -->
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
            <EmptyState
                title="Marketing operations console not yet connected"
                description="Real-time Marketing department metrics — active campaigns, content pipeline, social-media reach, and budget tracking — will appear here once the analytics integrations are wired in."
                icon="campaign"
            />
        </div>
    </div>
</template>

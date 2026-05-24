<script setup>
import EmptyState from '@/Components/EmptyState.vue';

// The previous implementation rendered an elaborate IT operations console
// (infrastructure status, on-call roster, team directory, tech-stack health)
// built entirely from hardcoded sample arrays. Until the IT department
// metrics are wired through the dashboard controller, show an honest empty
// state so real users aren't misled by mock data.
defineProps({
    spark:   { type: Object,  default: () => ({}) },
    tickets: { type: Array,   default: () => [] },
});
</script>

<template>
    <div class="space-y-8 animate-reveal-up">

        <!-- ─── Executive header ──────────────────────────────────── -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">dns</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">IT GOVERNANCE</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Infrastructure &amp; Service Desk</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Standing watch on infrastructure, service-desk velocity, and the security perimeter.
                </p>
            </div>
        </div>

        <!-- ─── Awaiting integrations ─────────────────────────────── -->
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
            <EmptyState
                title="IT operations console not yet connected"
                description="Real-time IT department metrics — infrastructure status, service-desk velocity, on-call roster, and security signals — will appear here once the monitoring integrations are wired in."
                icon="dns"
            />
        </div>
    </div>
</template>

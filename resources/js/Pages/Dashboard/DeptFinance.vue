<script setup>
import EmptyState from '@/Components/EmptyState.vue';

// The previous implementation rendered an elaborate Finance operations
// console (budget-vs-actuals chart, expense breakdown, pending approvals,
// payroll summary, compliance status, team roster) built entirely from
// hardcoded sample arrays. Until the Finance department metrics are wired
// through the dashboard controller, show an honest empty state so real
// users aren't misled by mock data. The Finance Hub
// (resources/js/Pages/Finance/*) already exposes the real ledger data.
defineProps({
    spark: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="space-y-8 animate-reveal-up">

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">account_balance</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">TREASURY</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Finance &amp; Treasury</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Treasury, payroll, audit and statutory compliance — the institute's financial posture in one ledger.
                </p>
            </div>
        </div>

        <!-- ─── Awaiting integrations ─────────────────────────────── -->
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
            <EmptyState
                title="Finance operations console not yet connected"
                description="Real-time Finance department metrics — budget-vs-actuals, expense breakdown, pending approvals, payroll status and statutory compliance — will appear here once the ledger integrations are wired in. The Finance Hub already exposes the real ledger data."
                icon="account_balance"
            />
        </div>
    </div>
</template>

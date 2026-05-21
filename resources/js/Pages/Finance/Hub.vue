<script setup>
// Stub — Task 10 replaces with the real finance hub page.
defineProps({
    cashPosition:        { type: Number, default: 0 },
    bankAccounts:        { type: Array,  default: () => [] },
    nextPayroll:         { type: [Object, null], default: null },
    outstandingLoans:    { type: Object, default: () => ({ count: 0, total_balance: 0 }) },
    pendingApprovals:    { type: Object, default: () => ({ payroll_runs: 0, loans: 0 }) },
    statutoryCompliance: { type: Array,  default: () => [] },
});
</script>

<template>
    <div>Finance Hub (stub)</div>
</template>

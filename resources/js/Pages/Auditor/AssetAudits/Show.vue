<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    audit: { type: Object, required: true },
    resultOptions: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const isOpen = props.audit.status.value === 'in_progress';

// per-line count form state keyed by line id
function countLine(line, result) {
    router.post(route('auditor.asset-audits.count', [props.audit.id, line.id]), {
        result,
        observed_location: line._observed_location ?? null,
        observed_note: line._observed_note ?? null,
    }, { preserveScroll: true });
}

function resolveLine(line, action) {
    router.post(route('auditor.asset-audits.resolve', [props.audit.id, line.id]), { action }, { preserveScroll: true });
}

const cancelForm = useForm({ reason: '' });

// maps a discrepancy result to its single valid resolution action + label
const RESOLUTION = {
    missing:        { action: 'marked_lost',        label: 'Mark lost' },
    wrong_location: { action: 'relocated',          label: 'Relocate' },
    damaged:        { action: 'maintenance_logged', label: 'Log maintenance' },
    wrong_holder:   { action: 'flagged',            label: 'Flag for reassignment' },
};
</script>

<template>
    <Head :title="audit.reference" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-primary">{{ audit.reference }}</h1>
                <span class="rounded-full bg-surface-container px-3 py-1 text-sm">{{ audit.status.label }}</span>
            </div>

            <div class="flex gap-6 text-sm text-on-surface-variant">
                <div>Scope: <span class="text-primary">{{ audit.scope_type }}<span v-if="audit.scope_value"> — {{ audit.scope_value }}</span></span></div>
                <div>Coverage: <span class="text-primary">{{ audit.counted_lines }} / {{ audit.total_lines }}</span></div>
                <div>Discrepancies: <span class="text-primary">{{ audit.discrepancy_lines }}</span></div>
            </div>

            <div v-if="can.manage && isOpen" class="flex gap-2">
                <button @click="router.post(route('auditor.asset-audits.complete', audit.id), {}, { preserveScroll: true })" class="rounded-lg bg-primary text-on-primary px-4 py-2">Complete audit</button>
                <input v-model="cancelForm.reason" aria-label="Cancel reason" placeholder="Cancel reason" class="rounded-lg border-outline-variant text-sm" />
                <button @click="cancelForm.post(route('auditor.asset-audits.cancel', audit.id), { preserveScroll: true })" class="rounded-lg bg-error text-on-error px-4 py-2">Cancel</button>
            </div>

            <table class="w-full text-sm">
                <thead class="text-left text-on-surface-variant border-b border-outline-variant/60">
                    <tr><th class="py-2">Asset</th><th>Expected</th><th>Result</th><th>Observed</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <tr v-for="line in audit.lines" :key="line.id" class="border-b border-outline-variant/40 align-top">
                        <td class="py-2">
                            <div class="text-primary">{{ line.asset?.asset_tag }}</div>
                            <div class="text-on-surface-variant">{{ line.asset?.name }}</div>
                        </td>
                        <td>
                            <div>{{ line.expected_status }}</div>
                            <div class="text-on-surface-variant">{{ line.expected_location }}</div>
                            <div v-if="line.expected_holder" class="text-on-surface-variant">{{ line.expected_holder }}</div>
                        </td>
                        <td>
                            <span :class="line.is_discrepancy ? 'text-error' : 'text-primary'">{{ line.result.label }}</span>
                            <div v-if="can.manage && isOpen" class="mt-1 flex flex-wrap gap-1">
                                <button v-for="opt in resultOptions" :key="opt.value" @click="countLine(line, opt.value)" class="rounded border border-outline-variant/60 px-1.5 py-0.5 text-xs hover:bg-surface-container">{{ opt.label }}</button>
                            </div>
                        </td>
                        <td>
                            <template v-if="can.manage && isOpen">
                                <input v-model="line._observed_location" aria-label="Observed location" placeholder="location" class="w-28 rounded border-outline-variant text-xs" />
                                <input v-model="line._observed_note" aria-label="Observed note" placeholder="note" class="w-28 rounded border-outline-variant text-xs mt-1" />
                            </template>
                            <template v-else>{{ line.observed_location }}</template>
                        </td>
                        <td>
                            <span v-if="line.resolution_action.value !== 'none'" class="text-on-surface-variant">{{ line.resolution_action.label }}</span>
                            <button v-else-if="can.manage && line.is_discrepancy && RESOLUTION[line.result.value]"
                                @click="resolveLine(line, RESOLUTION[line.result.value].action)"
                                class="rounded bg-primary text-on-primary px-2 py-0.5 text-xs">
                                {{ RESOLUTION[line.result.value].label }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>

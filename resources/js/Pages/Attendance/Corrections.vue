<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    corrections: Object,
});

const reviewing = ref(null);
const reviewForm = useForm({ decision: 'approve', decision_notes: '' });

function openReview(c, decision) {
    reviewing.value = c;
    reviewForm.decision = decision;
    reviewForm.decision_notes = '';
}

function submitReview() {
    reviewForm.patch(route('attendance.corrections.review', reviewing.value.id), {
        preserveScroll: true,
        onSuccess: () => { reviewing.value = null; },
    });
}
</script>

<template>
<Head title="Attendance Corrections" />
<AuthenticatedLayout active-module="attendance">
    <div class="space-y-6 animate-reveal-up p-6">
        <header>
            <h1 class="text-[1.6rem] font-black tracking-tight text-primary">Attendance Corrections</h1>
            <p class="text-sm text-on-surface-variant">Review and decide on employee-submitted attendance correction requests. Approved corrections become manual attendance records with reviewer attribution.</p>
        </header>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
            <table v-if="props.corrections.data?.length" class="w-full text-sm">
                <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="p-4">Submitted</th><th>Employee</th><th>Requested</th><th>Direction</th><th>Reason</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-for="c in props.corrections.data" :key="c.id" class="border-t border-outline-variant/40">
                        <td class="p-4 text-xs text-on-surface-variant">{{ new Date(c.created_at).toLocaleString() }}</td>
                        <td class="font-mono">{{ c.employee?.employee_no }} <span class="text-on-surface-variant">{{ c.employee?.position }}</span></td>
                        <td class="text-xs">{{ new Date(c.requested_event_at).toLocaleString() }}</td>
                        <td><span class="font-mono uppercase text-xs">{{ c.requested_direction }}</span></td>
                        <td class="max-w-xs truncate" :title="c.reason">{{ c.reason }}</td>
                        <td><StatusBadge :status="c.status" type="generic" /></td>
                        <td>
                            <div v-if="c.status === 'pending'" class="flex gap-2">
                                <button @click="openReview(c, 'approve')" type="button" class="rounded-lg bg-emerald-50 text-emerald-700 px-3 py-1 text-xs font-bold hover:bg-emerald-100">Approve</button>
                                <button @click="openReview(c, 'reject')" type="button" class="rounded-lg bg-rose-50 text-rose-700 px-3 py-1 text-xs font-bold hover:bg-rose-100">Reject</button>
                            </div>
                            <span v-else class="text-xs text-on-surface-variant">{{ c.reviewer?.name }} · {{ c.reviewed_at ? new Date(c.reviewed_at).toLocaleDateString() : '' }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="No correction requests yet." class="py-12" />
            <Pagination v-if="props.corrections.meta?.last_page > 1" :links="props.corrections.meta.links" class="p-4" />
        </section>
    </div>

    <div v-if="reviewing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="reviewing = null">
        <div class="bg-surface-container-lowest rounded-2xl p-6 max-w-md w-full shadow-2xl">
            <h3 class="text-lg font-black text-primary mb-2">{{ reviewForm.decision === 'approve' ? 'Approve' : 'Reject' }} Correction</h3>
            <p class="text-sm text-on-surface-variant mb-4">{{ reviewing.employee?.employee_no }} — {{ reviewing.requested_direction }} @ {{ new Date(reviewing.requested_event_at).toLocaleString() }}</p>
            <form @submit.prevent="submitReview" class="space-y-3">
                <div>
                    <label class="text-[11px] font-bold text-on-surface-variant">Decision notes{{ reviewForm.decision === 'reject' ? ' (required)' : '' }}</label>
                    <textarea v-model="reviewForm.decision_notes" :required="reviewForm.decision === 'reject'" rows="3" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" />
                </div>
                <div class="flex gap-2 justify-end">
                    <button type="button" @click="reviewing = null" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-on-surface-variant">Cancel</button>
                    <button type="submit" :disabled="reviewForm.processing" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">{{ reviewForm.decision === 'approve' ? 'Approve' : 'Reject' }}</button>
                </div>
            </form>
        </div>
    </div>
</AuthenticatedLayout>
</template>

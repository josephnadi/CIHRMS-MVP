<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ claims: Object });

const deciding = ref(null);
const decideForm = useForm({ status: 'approved', notes: '' });

function openDecide(c, status) {
    deciding.value = c;
    decideForm.status = status;
    decideForm.notes = '';
}

function submitDecide() {
    decideForm.patch(route('benefits.claims.decide', deciding.value.id), {
        preserveScroll: true,
        onSuccess: () => deciding.value = null,
    });
}

const statusTone = {
    submitted: 'bg-blue-100 text-blue-800',
    reviewing: 'bg-amber-100 text-amber-800',
    approved:  'bg-emerald-100 text-emerald-800',
    rejected:  'bg-rose-100 text-rose-800',
    paid:      'bg-sky-100 text-sky-800',
};
</script>

<template>
<Head title="Claims Queue" />
<AuthenticatedLayout active-module="benefits">
    <div class="p-6 space-y-6 animate-reveal-up">
        <header>
            <Link :href="route('benefits.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">â† My Benefits</Link>
            <div class="flex items-center gap-2 mt-1 mb-1">
                <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">request_quote</span>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Benefits administration · Approval queue</p>
            </div>
            <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Claims Queue</h1>
            <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                Review and decide submitted benefit claims org-wide — each decision is recorded in the audit chain.
            </p>
        </header>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
            <table v-if="props.claims.data?.length" class="w-full text-sm">
                <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="p-4">Reference</th><th>Employee</th><th>Plan</th><th>Amount</th><th>Submitted</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-for="c in props.claims.data" :key="c.id" class="border-t border-outline-variant/40">
                        <td class="p-4 font-mono">{{ c.claim_reference }}</td>
                        <td>{{ c.enrolment?.employee?.name }} <span class="text-on-surface-variant text-xs font-mono">({{ c.enrolment?.employee?.employee_no }})</span></td>
                        <td class="text-xs">{{ c.enrolment?.plan_name }}</td>
                        <td class="font-mono">{{ c.currency }} {{ c.amount.toFixed(2) }}</td>
                        <td class="text-xs">{{ new Date(c.submitted_at).toLocaleDateString() }}</td>
                        <td><span :class="['rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase', statusTone[c.status]]">{{ c.status }}</span></td>
                        <td>
                            <div v-if="['submitted','reviewing'].includes(c.status)" class="flex gap-1">
                                <button @click="openDecide(c, 'approved')" class="rounded-lg bg-emerald-50 text-emerald-700 px-2 py-0.5 text-[10px] font-bold hover:bg-emerald-100">Approve</button>
                                <button @click="openDecide(c, 'rejected')" class="rounded-lg bg-rose-50 text-rose-700 px-2 py-0.5 text-[10px] font-bold hover:bg-rose-100">Reject</button>
                            </div>
                            <button v-else-if="c.status === 'approved'" @click="openDecide(c, 'paid')" class="rounded-lg bg-sky-50 text-sky-700 px-2 py-0.5 text-[10px] font-bold hover:bg-sky-100">Mark Paid</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="No claims to review." class="py-12" />
            <Pagination v-if="props.claims.meta?.last_page > 1" :links="props.claims.meta.links" class="p-4" />
        </section>
    </div>

    <div v-if="deciding" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deciding = null">
        <div class="bg-surface-container-lowest rounded-2xl p-6 max-w-md w-full shadow-2xl">
            <h3 class="text-lg font-black text-primary mb-2">{{ decideForm.status === 'approved' ? 'Approve' : (decideForm.status === 'rejected' ? 'Reject' : 'Mark Paid') }} Claim</h3>
            <p class="text-sm text-on-surface-variant mb-4">{{ deciding.claim_reference }} Â· {{ deciding.currency }} {{ deciding.amount.toFixed(2) }}</p>
            <form @submit.prevent="submitDecide" class="space-y-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Notes{{ decideForm.status === 'rejected' ? ' (required)' : '' }}</label><textarea v-model="decideForm.notes" aria-label="Decision notes" :required="decideForm.status === 'rejected'" rows="3" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
                <div class="flex gap-2 justify-end">
                    <button type="button" @click="deciding = null" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-on-surface-variant">Cancel</button>
                    <button type="submit" :disabled="decideForm.processing" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</AuthenticatedLayout>
</template>

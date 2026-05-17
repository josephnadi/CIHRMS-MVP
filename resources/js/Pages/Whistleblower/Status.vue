<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    status:        Object,
    tracking_code: String,
});

const S = computed(() => props.status.data ?? props.status);
const messages = computed(() => S.value.messages ?? []);

const replyForm = useForm({
    tracking_code: props.tracking_code,
    body:          '',
});

const reply = () => replyForm.post(route('whistleblower.track.reply'), {
    onSuccess: () => replyForm.reset('body'),
});

const statusToneClass = (statusValue) => ({
    'submitted':                'bg-slate-100 text-slate-700',
    'triaged':                  'bg-blue-50 text-blue-700',
    'investigating':            'bg-amber-50 text-amber-800',
    'evidence_gathering':       'bg-amber-50 text-amber-800',
    'closed_substantiated':     'bg-emerald-50 text-emerald-800',
    'closed_unsubstantiated':   'bg-slate-100 text-slate-700',
    'closed_referred':          'bg-blue-50 text-blue-800',
    'withdrawn':                'bg-slate-100 text-slate-500',
}[statusValue] ?? 'bg-slate-100 text-slate-700');
</script>

<template>
    <Head :title="`Case ${S.case_number}`" />

    <div class="min-h-screen bg-background text-on-surface">
        <header class="border-b border-outline-variant/40">
            <div class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
                <h1 class="text-base font-bold tracking-tight">CIHRM Confidential Disclosure</h1>
                <Link :href="route('whistleblower.track')" class="text-sm text-secondary hover:underline">Different code â†’</Link>
            </div>
        </header>

        <main class="max-w-3xl mx-auto px-6 py-8 space-y-6">
            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/40 p-6">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/60">Case</p>
                        <p class="font-mono text-lg">{{ S.case_number }}</p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-wider"
                          :class="statusToneClass(S.status)">
                        {{ S.status_label }}
                    </span>
                </div>
                <dl class="grid grid-cols-3 gap-4 mt-5 text-sm">
                    <div>
                        <dt class="text-xs text-on-surface-variant/60">Category</dt>
                        <dd>{{ S.category_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-on-surface-variant/60">Received</dt>
                        <dd>{{ S.received_at }}</dd>
                    </div>
                    <div v-if="S.triaged_at">
                        <dt class="text-xs text-on-surface-variant/60">Triaged</dt>
                        <dd>{{ S.triaged_at }}</dd>
                    </div>
                    <div v-if="S.closed_at" class="col-span-3">
                        <dt class="text-xs text-on-surface-variant/60">Closed</dt>
                        <dd>{{ S.closed_at }}</dd>
                    </div>
                </dl>
                <div v-if="S.closure_summary" class="mt-4 rounded-xl bg-brand-navy/[0.03] p-4">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70 mb-1">Closure summary</p>
                    <p class="text-sm whitespace-pre-wrap">{{ S.closure_summary }}</p>
                </div>
            </div>

            <!-- Message thread -->
            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/40 overflow-hidden">
                <div class="px-5 py-3 border-b border-outline-variant/40 font-semibold text-sm">
                    Secure thread with the investigator
                </div>
                <div v-if="messages.length === 0" class="px-5 py-10 text-center text-sm text-on-surface-variant/60">
                    No messages yet. The investigator will reach out here if they need clarification.
                </div>
                <div v-else class="divide-y divide-outline-variant/30">
                    <div v-for="m in messages" :key="m.posted_at"
                         :class="m.direction === 'outbound' ? 'bg-brand-navy/[0.03]' : ''"
                         class="px-5 py-4">
                        <div class="flex justify-between text-xs text-on-surface-variant/70 mb-1">
                            <span>{{ m.direction === 'outbound' ? 'Investigator' : 'You' }}</span>
                            <span>{{ new Date(m.posted_at).toLocaleString('en-GH') }}</span>
                        </div>
                        <p class="text-sm whitespace-pre-wrap">{{ m.body }}</p>
                    </div>
                </div>

                <form v-if="['submitted','triaged','investigating','evidence_gathering'].includes(S.status)"
                      @submit.prevent="reply" class="border-t border-outline-variant/40 p-5 space-y-3">
                    <label class="block text-xs font-semibold text-on-surface-variant">Send a message to the investigator</label>
                    <textarea v-model="replyForm.body" rows="3" required
                              class="w-full rounded-lg border-outline-variant text-sm"
                              placeholder="Encrypted. Only the assigned investigator can read this."></textarea>
                    <div class="flex justify-end">
                        <PrimaryButton type="submit" :disabled="replyForm.processing">Send</PrimaryButton>
                    </div>
                </form>
            </div>
        </main>
    </div>
</template>

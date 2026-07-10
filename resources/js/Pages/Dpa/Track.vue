<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import GlossaryText from '@/Components/GlossaryText.vue';

const props = defineProps({
    result: { type: Object, default: null },
});

const form = useForm({
    reference: '',
    subject_email: '',
});

const submit = () => form.post(route('dpa.track.submit'), { preserveScroll: true });

const notFound = computed(() => props.result?.not_found === true);
const hit = computed(() => props.result && !notFound.value ? props.result : null);
</script>

<template>
    <Head title="Track DPA request" />
    <main class="min-h-screen bg-slate-50">
        <div class="mx-auto max-w-xl px-6 py-10">
            <header class="mb-6">
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-secondary"><GlossaryText text="DPA 2012 · Act 843" /></p>
                <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900">Track your request</h1>
                <p class="mt-2 text-[13px] text-slate-600">
                    Enter your reference number and the email you used to submit. We'll show the current status.
                </p>
            </header>

            <form @submit.prevent="submit" class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <label class="block">
                    <span class="text-[12px] font-bold text-slate-700">Reference</span>
                    <input aria-label="Reference" v-model="form.reference" required type="text" placeholder="DSR-2026-00001"
                           class="mt-1 w-full rounded-lg border-slate-300 text-[13px] font-mono" />
                    <p v-if="form.errors.reference" class="mt-1 text-[12px] text-rose-600">{{ form.errors.reference }}</p>
                </label>
                <label class="block">
                    <span class="text-[12px] font-bold text-slate-700">Email</span>
                    <input aria-label="Email" v-model="form.subject_email" required type="email"
                           class="mt-1 w-full rounded-lg border-slate-300 text-[13px]" />
                    <p v-if="form.errors.subject_email" class="mt-1 text-[12px] text-rose-600">{{ form.errors.subject_email }}</p>
                </label>
                <div class="flex items-center justify-end">
                    <button type="submit" :disabled="form.processing"
                            class="rounded-lg bg-slate-900 px-5 py-2.5 text-[13px] font-bold text-white hover:bg-slate-800 disabled:opacity-60">
                        {{ form.processing ? 'Looking up…' : 'Track' }}
                    </button>
                </div>
            </form>

            <section v-if="hit" class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                <p class="text-[10px] font-black uppercase tracking-widest text-emerald-700">Found</p>
                <h2 class="mt-1 text-lg font-black text-slate-900 font-mono">{{ hit.reference }}</h2>
                <dl class="mt-3 space-y-1.5 text-[13px] text-slate-700">
                    <div class="flex justify-between"><dt class="font-bold">Status</dt><dd>{{ hit.status_label }}</dd></div>
                    <div class="flex justify-between"><dt class="font-bold">Type</dt><dd>{{ hit.request_type }}</dd></div>
                    <div v-if="hit.submitted_at" class="flex justify-between"><dt class="font-bold">Submitted</dt><dd>{{ new Date(hit.submitted_at).toLocaleDateString('en-GB') }}</dd></div>
                    <div v-if="hit.target_completion_date" class="flex justify-between"><dt class="font-bold">Statutory deadline</dt><dd>{{ new Date(hit.target_completion_date).toLocaleDateString('en-GB') }}</dd></div>
                    <div v-if="hit.completed_at" class="flex justify-between"><dt class="font-bold">Completed</dt><dd>{{ new Date(hit.completed_at).toLocaleDateString('en-GB') }}</dd></div>
                </dl>
                <p v-if="hit.decision_summary" class="mt-3 rounded-lg bg-white p-3 text-[12px] text-slate-700">
                    {{ hit.decision_summary }}
                </p>
            </section>

            <section v-else-if="notFound" class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-5">
                <p class="text-[13px] font-bold text-rose-900">No match found</p>
                <p class="mt-1 text-[12px] text-rose-700">The reference and email don't match a request on file. Double-check the values and try again.</p>
            </section>
        </div>
    </main>
</template>

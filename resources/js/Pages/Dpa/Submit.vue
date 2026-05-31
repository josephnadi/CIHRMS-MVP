<script setup>
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    types: Array,
});

const form = useForm({
    subject_email: '',
    subject_full_name: '',
    request_type: 'access',
    subject_statement: '',
    rectification_details: '',
    objection_purpose: '',
});

const submit = () => form.post(route('dpa.submit'));
</script>

<template>
    <Head title="Data Protection request" />
    <main class="min-h-screen bg-slate-50">
        <div class="mx-auto max-w-2xl px-6 py-10">
            <header class="mb-6">
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-secondary">DPA 2012 · Act 843</p>
                <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900">Data-subject request</h1>
                <p class="mt-2 text-[13px] text-slate-600">
                    File a request for access, rectification, erasure, portability, or objection regarding personal data CIHRM Ghana holds about you. You'll receive a confirmation email — click the link inside to verify and start the statutory 30-day clock.
                </p>
            </header>

            <form @submit.prevent="submit" class="space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <label class="block">
                        <span class="text-[12px] font-bold text-slate-700">Full name</span>
                        <input aria-label="Subject full name" v-model="form.subject_full_name" required type="text"
                               class="mt-1 w-full rounded-lg border-slate-300 text-[13px]" />
                        <span v-if="form.errors.subject_full_name" class="text-[11px] text-rose-600">{{ form.errors.subject_full_name }}</span>
                    </label>
                    <label class="block">
                        <span class="text-[12px] font-bold text-slate-700">Email</span>
                        <input aria-label="Subject email" v-model="form.subject_email" required type="email"
                               class="mt-1 w-full rounded-lg border-slate-300 text-[13px]" />
                        <span v-if="form.errors.subject_email" class="text-[11px] text-rose-600">{{ form.errors.subject_email }}</span>
                    </label>
                </div>

                <label class="block">
                    <span class="text-[12px] font-bold text-slate-700">Request type</span>
                    <select v-model="form.request_type" required aria-label="Type of data-subject request"
                            class="mt-1 w-full rounded-lg border-slate-300 text-[13px]">
                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-[12px] font-bold text-slate-700">Your request</span>
                    <textarea aria-label="Subject statement" v-model="form.subject_statement" required rows="5" minlength="10"
                              class="mt-1 w-full rounded-lg border-slate-300 text-[13px]"
                              placeholder="Briefly describe what you want — e.g. 'A copy of all personal data you hold about me'."></textarea>
                    <span v-if="form.errors.subject_statement" class="text-[11px] text-rose-600">{{ form.errors.subject_statement }}</span>
                </label>

                <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                    <button type="submit" :disabled="form.processing"
                            class="rounded-lg bg-slate-900 px-5 py-2.5 text-[13px] font-bold text-white hover:bg-slate-800 disabled:opacity-60">
                        {{ form.processing ? 'Submitting…' : 'Submit request' }}
                    </button>
                </div>
            </form>

            <p class="mt-6 text-center text-[12px] text-slate-500">
                <a :href="route('dpa.track')" class="font-bold text-secondary hover:underline">Already submitted? Track your request →</a>
            </p>
        </div>
    </main>
</template>

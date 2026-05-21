<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({ reference: String });
</script>

<template>
    <Head title="Request received" />
    <main class="min-h-screen bg-slate-50">
        <div class="mx-auto max-w-xl px-6 py-16 text-center">
            <div class="mx-auto h-14 w-14 rounded-full bg-emerald-100 flex items-center justify-center">
                <span class="material-symbols-outlined text-emerald-700 text-[28px]">mark_email_read</span>
            </div>
            <h1 class="mt-5 text-2xl font-black tracking-tight text-slate-900">Check your inbox</h1>
            <p class="mt-3 text-[14px] text-slate-600">
                We've emailed a confirmation link to verify your address. Click it to start the statutory 30-day clock.
            </p>
            <p v-if="reference" class="mt-4 text-[12px] text-slate-500">
                Reference: <span class="font-mono font-bold text-slate-700">{{ reference }}</span>
            </p>
            <p class="mt-6 text-[12px] text-slate-500">
                <a :href="route('dpa.track') + '?reference=' + encodeURIComponent(reference || '')"
                   class="font-bold text-secondary hover:underline">Track this request</a>
            </p>
        </div>
    </main>
</template>

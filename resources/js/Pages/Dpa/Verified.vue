<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    ok: { type: Boolean, default: false },
    reference: { type: String, default: '' },
});
</script>

<template>
    <Head title="Verification result" />
    <main class="min-h-screen bg-slate-50">
        <div class="mx-auto max-w-xl px-6 py-16 text-center">
            <div v-if="ok"
                 class="mx-auto h-14 w-14 rounded-full bg-emerald-100 flex items-center justify-center">
                <span class="material-symbols-outlined text-emerald-700 text-[28px]">check_circle</span>
            </div>
            <div v-else
                 class="mx-auto h-14 w-14 rounded-full bg-rose-100 flex items-center justify-center">
                <span class="material-symbols-outlined text-rose-700 text-[28px]">link_off</span>
            </div>

            <h1 class="mt-5 text-2xl font-black tracking-tight text-slate-900">
                {{ ok ? 'Request verified' : 'Verification link not valid' }}
            </h1>
            <p class="mt-3 text-[14px] text-slate-600">
                <template v-if="ok">
                    Thank you. Your request is now in the Data Protection Officer's queue. The statutory 30-day clock under Act 843 §22 has started.
                </template>
                <template v-else>
                    This verification link is no longer valid. It may have already been used, or the request may have been withdrawn. If you didn't expect this message, you can safely ignore it.
                </template>
            </p>

            <p v-if="ok && reference" class="mt-5 text-[12px] text-slate-500">
                Reference: <span class="font-mono font-bold text-slate-700">{{ reference }}</span>
            </p>
            <p class="mt-6 text-[12px] text-slate-500">
                <a :href="route('dpa.track')" class="font-bold text-secondary hover:underline">Track your request →</a>
            </p>
        </div>
    </main>
</template>

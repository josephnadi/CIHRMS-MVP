<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
defineProps({
    case_number:   String,
    tracking_code: String,
});

const copied = ref(false);

const copy = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = true;
        setTimeout(() => copied.value = false, 2000);
    } catch (e) { /* clipboard blocked */ }
};
</script>

<template>
    <Head title="Disclosure received" />

    <div class="min-h-screen bg-background text-on-surface flex items-center">
        <main class="max-w-2xl mx-auto px-6 py-12 w-full">
            <div class="rounded-3xl bg-surface-container-lowest border border-outline-variant/40 shadow-lifted p-10 space-y-6">
                <div class="flex items-center gap-3">
                    <div class="h-12 w-12 rounded-2xl bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center">
                        <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 text-[28px]" style="font-variation-settings:'FILL' 1">verified</span>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold tracking-tight">Your disclosure has been received</h1>
                        <p class="text-sm text-on-surface-variant">Thank you for coming forward.</p>
                    </div>
                </div>

                <div class="rounded-xl bg-brand-navy/[0.04] border border-brand-navy/15 p-5 space-y-3">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Case number</p>
                        <p class="font-mono text-base">{{ case_number }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Tracking code</p>
                        <div class="flex items-center gap-3 mt-1">
                            <code class="flex-1 font-mono text-lg tracking-widest bg-surface-container-lowest border border-outline-variant rounded-lg px-3 py-2">
                                {{ tracking_code }}
                            </code>
                            <button @click="copy(tracking_code)"
                                    class="text-secondary text-sm font-semibold hover:underline">
                                {{ copied ? 'Copied!' : 'Copy' }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl bg-amber-50/60 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/40 p-4 text-sm text-amber-900 dark:text-amber-300">
                    <p class="font-semibold mb-1">⚠ Save your tracking code now.</p>
                    <p>
                        We do not store this code in a recoverable form. If you lose it, you will not be able to check
                        the status of your case or send follow-up messages. <strong>Save it somewhere safe</strong> —
                        password manager, printed note, or trusted contact.
                    </p>
                </div>

                <div class="text-sm space-y-2">
                    <p>An investigator will triage your case shortly. To check progress at any time:</p>
                    <Link :href="route('whistleblower.track')" class="text-secondary font-semibold hover:underline">
                        Track this case →
                    </Link>
                </div>
            </div>
        </main>
    </div>
</template>

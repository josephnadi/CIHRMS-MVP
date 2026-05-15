<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const form = useForm({ tracking_code: '' });
const submit = () => form.post(route('whistleblower.track.submit'));
</script>

<template>
    <Head title="Track a disclosure" />

    <div class="min-h-screen bg-background text-on-surface flex items-center">
        <main class="max-w-md mx-auto px-6 py-12 w-full">
            <div class="rounded-3xl bg-surface-container-lowest border border-outline-variant/40 shadow-lifted p-8 space-y-6">
                <div>
                    <h1 class="text-xl font-bold tracking-tight">Track your disclosure</h1>
                    <p class="text-sm text-on-surface-variant mt-1">Enter the tracking code you received at submission.</p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Tracking code</label>
                        <input v-model="form.tracking_code" placeholder="XXXX-XXXX-XXXX" autofocus
                               class="w-full rounded-lg border-outline-variant font-mono tracking-widest text-center uppercase">
                        <p v-if="form.errors.tracking_code" class="text-rose-600 text-xs mt-1">{{ form.errors.tracking_code }}</p>
                    </div>
                    <PrimaryButton type="submit" :disabled="form.processing" class="w-full justify-center">
                        Check status
                    </PrimaryButton>
                </form>

                <Link :href="route('whistleblower.form')" class="block text-center text-secondary text-sm hover:underline">
                    ← Make a new disclosure
                </Link>
            </div>
        </main>
    </div>
</template>

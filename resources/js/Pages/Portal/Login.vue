<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
});

function submit() {
    form.post(route('portal.login.store'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
<Head title="Member Login — CIHRM" />
<div class="min-h-screen bg-surface flex items-center justify-center p-6">
    <div class="w-full max-w-md rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8 shadow-card">
        <div class="text-center mb-6">
            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-primary text-white font-black text-lg">CI</span>
            <h1 class="text-xl font-black text-primary mt-3">CIHRM Member Portal</h1>
            <p class="text-xs text-on-surface-variant mt-1">Sign in to view and pay your fees.</p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label for="email" class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-1">Email</label>
                <input id="email" v-model="form.email" type="email" required autofocus
                       class="w-full rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest" />
                <p v-if="form.errors.email" class="mt-1 text-xs text-error">{{ form.errors.email }}</p>
            </div>
            <div>
                <label for="password" class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-1">Password</label>
                <input id="password" v-model="form.password" type="password" required autocomplete="current-password"
                       class="w-full rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest" />
                <p v-if="form.errors.password" class="mt-1 text-xs text-error">{{ form.errors.password }}</p>
            </div>
            <button type="submit" :disabled="form.processing"
                    class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2.5 text-sm font-black text-white shadow-glow-sm disabled:opacity-50">
                Sign in
            </button>
        </form>

        <p class="mt-6 text-center text-[11px] text-on-surface-variant">
            Trouble signing in? Contact the institute office.
        </p>
    </div>
</div>
</template>

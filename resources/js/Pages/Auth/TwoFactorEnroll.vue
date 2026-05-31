<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    secret: String,
    provisioning_uri: String,
    already_enrolled: Boolean,
});

const form = useForm({ code: '' });
const submit = () => form.post(route('two-factor.confirm'));

// QR code URL via Google Charts API (free) — replace with self-hosted bacon/bacon-qr-code in prod.
const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=${encodeURIComponent(props.provisioning_uri)}`;
</script>

<template>
    <Head title="Two-Factor Authentication" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <h1 class="text-2xl font-semibold tracking-tight">Enable Two-Factor Authentication</h1>
            </Teleport>

            <div class="py-8 max-w-2xl mx-auto">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-6">
                    <p v-if="already_enrolled" class="text-emerald-700 text-sm">
                        Two-factor authentication is already enabled on this account.
                    </p>

                    <ol class="text-sm text-slate-700 space-y-2 list-decimal list-inside">
                        <li>Install Google Authenticator, Authy, or 1Password on your phone.</li>
                        <li>Scan the QR code below or paste the secret manually.</li>
                        <li>Enter the 6-digit code your app shows to confirm enrolment.</li>
                    </ol>

                    <div class="flex flex-col items-center gap-3">
                        <img :src="qrUrl" alt="2FA QR code" class="rounded-lg border border-slate-200" />
                        <code class="font-mono text-xs bg-slate-100 px-3 py-1 rounded">{{ secret }}</code>
                    </div>

                    <form @submit.prevent="submit" class="flex gap-3 items-end">
                        <div class="flex-1">
                            <label class="block text-xs font-medium text-slate-600 mb-1">6-digit code</label>
                            <input aria-label="6-digit code" v-model="form.code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6"
                                   class="w-full rounded-lg border-slate-200 font-mono text-lg tracking-widest text-center"
                                   autofocus required>
                            <p v-if="form.errors.code" class="text-rose-600 text-xs mt-1">{{ form.errors.code }}</p>
                        </div>
                        <PrimaryButton type="submit" :disabled="form.processing">Confirm</PrimaryButton>
                    </form>
                </div>
            </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ intended: String });

const form = useForm({
    code: '',
    recovery: '',
    intended: props.intended,
});

const useRecovery = ref(false);
const submit = () => form.post(route('two-factor.challenge.submit'));
</script>

<template>
    <Head title="Two-Factor Challenge" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <h1 class="text-2xl font-semibold tracking-tight">Verify it's you</h1>
            </Teleport>

            <div class="py-8 max-w-md mx-auto">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
                    <p class="text-sm text-slate-600">This sensitive action requires a fresh two-factor confirmation.</p>

                    <form @submit.prevent="submit" class="space-y-3">
                        <template v-if="!useRecovery">
                            <label class="block text-xs font-medium text-slate-600 mb-1">6-digit code</label>
                            <input v-model="form.code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6"
                                   class="w-full rounded-lg border-slate-200 font-mono text-lg tracking-widest text-center"
                                   autofocus>
                        </template>
                        <template v-else>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Recovery code</label>
                            <input v-model="form.recovery" type="text"
                                   placeholder="xxxx-xxxx"
                                   class="w-full rounded-lg border-slate-200 font-mono">
                        </template>

                        <p v-if="form.errors.code" class="text-rose-600 text-xs">{{ form.errors.code }}</p>

                        <PrimaryButton type="submit" :disabled="form.processing" class="w-full justify-center">
                            Verify
                        </PrimaryButton>

                        <button type="button" @click="useRecovery = !useRecovery"
                                class="text-xs text-blue-600 hover:underline">
                            {{ useRecovery ? 'Use authenticator app instead' : 'Use a recovery code instead' }}
                        </button>
                    </form>
                </div>
            </div>
    </div>
</template>

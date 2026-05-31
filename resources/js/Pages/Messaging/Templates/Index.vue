<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import VariablesPanel from '@/Components/VariablesPanel.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    templates:     { type: Object, required: true },
    audienceTypes: { type: Array, required: true },
});

const showForm = ref(false);
const form = useForm({
    name: '', audience_type: 'all_active_members',
    sms_body: '', mail_subject: '', mail_body: '',
    is_active: true,
});

function submit() {
    form.post(route('messaging.templates.store'), {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); },
    });
}

const allowedVarsFor = (v) =>
    props.audienceTypes.find(t => t.value === v)?.allowedVars ?? [];

const rows = props.templates.data ?? props.templates ?? [];
</script>

<template>
<Head title="Broadcast templates" />
<div class="p-6 max-w-6xl mx-auto">
    <header class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-primary">Broadcast templates</h1>
            <p class="text-sm text-on-surface-variant">Reusable SMS + email bodies, audience-typed for variable safety.</p>
        </div>
        <PrimaryButton @click="showForm = true">New template</PrimaryButton>
    </header>

    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-surface-container">
                <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Audience</th>
                    <th class="px-4 py-3">Channels</th>
                    <th class="px-4 py-3">Active</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in rows" :key="t.id" class="border-t border-outline-variant/40">
                    <td class="px-4 py-2 font-semibold">{{ t.name }}</td>
                    <td class="px-4 py-2 capitalize">{{ t.audience_type.replaceAll('_', ' ') }}</td>
                    <td class="px-4 py-2 font-mono text-xs">{{ [t.sms_body && 'sms', t.mail_body && 'mail'].filter(Boolean).join(' + ') }}</td>
                    <td class="px-4 py-2">{{ t.is_active ? 'Yes' : 'No' }}</td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td colspan="4" class="px-4 py-6 text-center text-on-surface-variant">No templates yet.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <SlidePanel :open="showForm" @close="showForm = false" title="New template" size="lg">
        <form @submit.prevent="submit" class="grid grid-cols-3 gap-4">
            <div class="col-span-2 space-y-3">
                <div>
                    <InputLabel for="name" value="Template name" />
                    <TextInput id="name" v-model="form.name" required class="mt-1 w-full" />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="audience_type" value="Audience type" />
                    <select aria-label="Audience type" id="audience_type" v-model="form.audience_type" required
                            class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                        <option v-for="t in audienceTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel for="sms_body" value="SMS body (optional)" />
                    <textarea aria-label="Sms body" id="sms_body" v-model="form.sms_body" rows="3"
                              class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm font-mono"></textarea>
                    <InputError :message="form.errors.sms_body" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mail_subject" value="Mail subject (optional)" />
                    <TextInput id="mail_subject" v-model="form.mail_subject" class="mt-1 w-full" />
                    <InputError :message="form.errors.mail_subject" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mail_body" value="Mail body (optional)" />
                    <textarea aria-label="Mail body" id="mail_body" v-model="form.mail_body" rows="6"
                              class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm"></textarea>
                    <InputError :message="form.errors.mail_body" class="mt-1" />
                </div>
                <div class="flex justify-end pt-2">
                    <PrimaryButton type="submit" :disabled="form.processing">Create</PrimaryButton>
                </div>
            </div>
            <div>
                <VariablesPanel :variables="allowedVarsFor(form.audience_type)" />
            </div>
        </form>
    </SlidePanel>
</div>
</template>

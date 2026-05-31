<script setup>
import { ref, computed, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import VariablesPanel from '@/Components/VariablesPanel.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    audienceTypes:      { type: Array, required: true },
    templates:          { type: Array, default: () => [] },
    canBypassThrottle:  { type: Boolean, default: false },
});

const form = useForm({
    title:                    '',
    audience_type:            'all_active_members',
    audience_params:          {},
    channels:                 ['sms', 'mail'],
    template_id:              null,
    sms_body:                 '',
    mail_subject:             '',
    mail_body:                '',
    scheduled_at:             '',
    throttle_overridden:      false,
    throttle_override_reason: '',
});

const audienceType = computed(() =>
    props.audienceTypes.find(t => t.value === form.audience_type) ?? props.audienceTypes[0]
);

const allowedVars = computed(() => audienceType.value?.allowedVars ?? []);

const compatibleTemplates = computed(() =>
    props.templates.filter(t => t.audience_type === form.audience_type)
);

watch(() => form.template_id, (id) => {
    if (! id) return;
    const t = props.templates.find(x => x.id === id);
    if (! t) return;
    form.sms_body     = t.sms_body ?? '';
    form.mail_subject = t.mail_subject ?? '';
    form.mail_body    = t.mail_body ?? '';
});

const audienceCount = ref(null);
const audienceSample = ref([]);

async function previewAudience() {
    const r = await axios.post(route('messaging.broadcasts.preview'), {
        audience_type:   form.audience_type,
        audience_params: form.audience_params,
    });
    audienceCount.value  = r.data.count;
    audienceSample.value = r.data.sample;
}

function submit() {
    form.post(route('messaging.broadcasts.store'));
}
</script>

<template>
<Head title="New broadcast" />
<div class="p-6 max-w-5xl mx-auto">
    <header class="mb-6">
        <h1 class="text-2xl font-black text-primary">New broadcast</h1>
        <p class="text-sm text-on-surface-variant">Compose an SMS + email broadcast to a pre-defined audience.</p>
    </header>

    <form @submit.prevent="submit" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-4">
            <div>
                <InputLabel for="title" value="Title (internal only)" />
                <TextInput id="title" v-model="form.title" required class="mt-1 w-full" />
                <InputError :message="form.errors.title" class="mt-1" />
            </div>

            <div>
                <InputLabel for="audience_type" value="Audience" />
                <select id="audience_type" v-model="form.audience_type" required
                        class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                    <option v-for="t in audienceTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <button type="button" @click="previewAudience"
                        class="mt-2 text-xs text-primary hover:underline">Preview audience…</button>
                <div v-if="audienceCount !== null" class="mt-2 text-xs text-on-surface-variant">
                    <strong>{{ audienceCount }}</strong> recipients
                    <span v-if="audienceSample.length">— sample: {{ audienceSample.slice(0, 5).map(s => s.name).join(', ') }}</span>
                </div>
                <InputError :message="form.errors.audience_type" class="mt-1" />
            </div>

            <div>
                <InputLabel value="Channels" />
                <div class="mt-1 flex gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :value="'sms'" v-model="form.channels" aria-label="Channel: SMS" /> SMS
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :value="'mail'" v-model="form.channels" aria-label="Channel: Email" /> Email
                    </label>
                </div>
                <InputError :message="form.errors.channels" class="mt-1" />
            </div>

            <div v-if="compatibleTemplates.length">
                <InputLabel for="template_id" value="Use saved template (optional)" />
                <select id="template_id" v-model.number="form.template_id"
                        class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                    <option :value="null">— None —</option>
                    <option v-for="t in compatibleTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </div>

            <div v-if="form.channels.includes('sms')">
                <InputLabel for="sms_body" value="SMS body" />
                <textarea id="sms_body" v-model="form.sms_body" rows="3"
                          class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm font-mono"></textarea>
                <InputError :message="form.errors.sms_body" class="mt-1" />
            </div>

            <div v-if="form.channels.includes('mail')">
                <InputLabel for="mail_subject" value="Email subject" />
                <TextInput id="mail_subject" v-model="form.mail_subject" class="mt-1 w-full" />
                <InputError :message="form.errors.mail_subject" class="mt-1" />

                <InputLabel for="mail_body" value="Email body" class="mt-3" />
                <textarea id="mail_body" v-model="form.mail_body" rows="8"
                          class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm"></textarea>
                <InputError :message="form.errors.mail_body" class="mt-1" />
            </div>

            <div>
                <InputLabel for="scheduled_at" value="Schedule for (optional — leave blank to send now)" />
                <TextInput id="scheduled_at" type="datetime-local" v-model="form.scheduled_at" class="mt-1 w-full" />
                <InputError :message="form.errors.scheduled_at" class="mt-1" />
            </div>

            <div v-if="canBypassThrottle" class="rounded-xl border border-warning/40 bg-warning/10 p-4">
                <label class="flex items-center gap-2 text-sm font-semibold text-warning-on-container">
                    <input type="checkbox" v-model="form.throttle_overridden" aria-label="Bypass per-phone SMS throttle" />
                    Bypass per-phone SMS throttle (logged in audit)
                </label>
                <TextInput v-if="form.throttle_overridden" v-model="form.throttle_override_reason"
                           placeholder="Reason for override (e.g. AGM tomorrow)"
                           class="mt-2 w-full" />
                <InputError :message="form.errors.throttle_override_reason" class="mt-1" />
            </div>

            <div class="pt-2 flex justify-end gap-2">
                <button type="button" @click="router.visit(route('messaging.broadcasts.index'))"
                        class="rounded-xl px-4 py-2 text-sm">Cancel</button>
                <PrimaryButton type="submit" :disabled="form.processing">
                    {{ form.scheduled_at ? 'Schedule' : 'Send now' }}
                </PrimaryButton>
            </div>
        </div>

        <div>
            <VariablesPanel :variables="allowedVars" />
        </div>
    </form>
</div>
</template>

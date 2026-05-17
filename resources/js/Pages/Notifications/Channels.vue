<script setup>
import { computed, reactive } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    preferences:  Object,
    profile:      Object,
    available:    Object,
    activeModule: String,
});

const form = useForm({
    preferences: { ...props.preferences },
    whatsapp_phone:   props.profile.whatsapp_phone   ?? '',
    whatsapp_consent: !!props.profile.whatsapp_consent_at,
    slack_user_id:    props.profile.slack_user_id    ?? '',
});

const channels = reactive([
    { key: 'email',    label: 'Email',         desc: 'Receive HR notifications in your inbox.',                  icon: 'mail',     color: '#205295' },
    { key: 'in_app',   label: 'In-app',        desc: 'Show notifications inside CIHRMS.',                        icon: 'campaign', color: '#205295' },
    { key: 'whatsapp', label: 'WhatsApp',      desc: 'Get template messages on WhatsApp Business.',              icon: 'sms',      color: '#22c55e' },
    { key: 'slack',    label: 'Slack',         desc: 'Direct messages from the CIHRMS Slack bot.',               icon: 'chat',     color: '#4a154b' },
    { key: 'teams',    label: 'Microsoft Teams',desc: 'Adaptive cards posted to the configured HR channel.',     icon: 'forum',    color: '#5059c9' },
]);

const submit = () => {
    form.patch(route('notifications.channels.update'), { preserveScroll: true });
};

const phoneOk = computed(() => /^\+?\d{8,15}$/.test(form.whatsapp_phone || ''));
const whatsappBlocked = computed(() => form.preferences.whatsapp && (!form.whatsapp_consent || !phoneOk.value));
</script>

<template>
    <Head title="Notification Channels" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div>
                <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70 mb-1">
                    <span>Settings</span>
                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                    <span>Notification Channels</span>
                </div>
                <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Notification Channels</h2>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Pick where CIHRMS contacts you. WhatsApp is opt-in only â€” Meta requires explicit consent before we can send template messages.
                </p>
            </div>
        </template>

        <form @submit.prevent="submit" class="space-y-6 max-w-3xl">

            <!-- Channel toggles -->
            <section class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest divide-y divide-outline-variant/30 overflow-hidden">
                <div
                    v-for="ch in channels"
                    :key="ch.key"
                    class="flex items-start gap-4 px-5 py-4"
                >
                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl"
                         :style="`background:${ch.color}1a`">
                        <span class="material-symbols-outlined text-[20px]" :style="`color:${ch.color}`">{{ ch.icon }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="text-[14px] font-black text-on-surface tracking-tight">{{ ch.label }}</h3>
                            <span
                                v-if="!available[ch.key]"
                                class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.10em] text-amber-600"
                            >Driver not connected</span>
                        </div>
                        <p class="mt-0.5 text-[12px] text-on-surface-variant leading-snug">{{ ch.desc }}</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                        <input
                            type="checkbox"
                            v-model="form.preferences[ch.key]"
                            :disabled="!available[ch.key]"
                            class="sr-only peer"
                        />
                        <div class="w-11 h-6 bg-on-surface-variant/20 rounded-full peer peer-checked:bg-secondary peer-disabled:opacity-40 peer-disabled:cursor-not-allowed transition-colors"></div>
                        <div class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white transition-transform peer-checked:translate-x-5 shadow-sm"></div>
                    </label>
                </div>
            </section>

            <!-- WhatsApp consent + phone -->
            <section v-if="available.whatsapp" class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-5 space-y-4">
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-[22px]" style="color:#22c55e">verified_user</span>
                    <div>
                        <h3 class="text-[14px] font-black text-on-surface">WhatsApp consent</h3>
                        <p class="text-[12px] text-on-surface-variant">
                            Required before we can send you any WhatsApp message. Revoke any time by clearing the box.
                        </p>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">WhatsApp phone</label>
                    <input
                        v-model="form.whatsapp_phone"
                        placeholder="+233244123456"
                        class="w-full max-w-xs rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px] font-mono"
                    />
                    <p v-if="form.whatsapp_phone && !phoneOk" class="mt-1 text-[11px] text-amber-600">
                        Use international format with country code (8â€“15 digits, optional leading +).
                    </p>
                </div>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" v-model="form.whatsapp_consent" class="mt-0.5 h-4 w-4 rounded border-outline-variant" />
                    <span class="text-[12px] text-on-surface-variant">
                        I consent to receive HR notifications from CIHRMS on WhatsApp at the number above.
                    </span>
                </label>

                <p v-if="whatsappBlocked" class="rounded-xl bg-amber-500/10 px-3 py-2 text-[11.5px] text-amber-600 leading-snug">
                    WhatsApp delivery will stay disabled until both consent and a valid phone are provided. Saving now will keep WhatsApp off.
                </p>
            </section>

            <!-- Slack mapping -->
            <section v-if="available.slack" class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-5 space-y-3">
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-[22px]" style="color:#4a154b">tag</span>
                    <div>
                        <h3 class="text-[14px] font-black text-on-surface">Slack workspace identity</h3>
                        <p class="text-[12px] text-on-surface-variant">
                            Paste your Slack member id (starts with <code class="font-mono">U</code>). Find it in your Slack profile menu â†’ "Copy member ID". Without it, Slack notifications fall back to the default HR channel.
                        </p>
                    </div>
                </div>
                <input
                    v-model="form.slack_user_id"
                    placeholder="U01ABC234"
                    class="w-full max-w-xs rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px] font-mono"
                />
            </section>

            <div class="flex items-center justify-end gap-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow hover:-translate-y-px transition-all disabled:opacity-60"
                    style="background:linear-gradient(135deg,#0a2647,#205295)"
                >
                    <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">save</span>
                    {{ form.processing ? 'Saving…' : 'Save preferences' }}
                </button>
            </div>
        </form>

    </AuthenticatedLayout>
</template>

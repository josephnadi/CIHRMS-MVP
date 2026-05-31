<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    broadcast:  { type: Object, required: true },
    recipients: { type: Object, required: true },
});

const cancelForm = useForm({});

function cancel() {
    if (! confirm('Cancel this scheduled broadcast?')) return;
    cancelForm.post(route('messaging.broadcasts.cancel', props.broadcast.id));
}

const rows = props.recipients.data ?? props.recipients ?? [];
</script>

<template>
<Head :title="`${broadcast.title} — Broadcast`" />
<div class="p-6 max-w-6xl mx-auto space-y-6">
    <header>
        <Link :href="route('messaging.broadcasts.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← All broadcasts</Link>
        <h1 class="text-2xl font-black text-primary mt-1">{{ broadcast.title }}</h1>
        <p class="text-sm text-on-surface-variant">
            <span class="capitalize">{{ broadcast.audience_type.replaceAll('_', ' ') }}</span>
            · <span class="font-mono text-xs">{{ (broadcast.channels ?? []).join(' + ') }}</span>
            · <span class="capitalize">{{ broadcast.status }}</span>
        </p>
    </header>

    <button v-if="['scheduled','queued'].includes(broadcast.status)"
            @click="cancel"
            class="rounded-xl border border-red-500/40 text-red-600 px-4 py-2 text-sm hover:bg-red-50">
        Cancel broadcast
    </button>

    <section class="grid grid-cols-2 md:grid-cols-6 gap-3">
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Recipients</p>
            <p class="text-2xl font-black tabular-nums">{{ broadcast.recipient_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">SMS sent</p>
            <p class="text-2xl font-black tabular-nums text-green-700">{{ broadcast.sms_sent_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">SMS throttled</p>
            <p class="text-2xl font-black tabular-nums text-amber-700">{{ broadcast.sms_throttled_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">SMS failed</p>
            <p class="text-2xl font-black tabular-nums text-red-700">{{ broadcast.sms_failed_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Mail sent</p>
            <p class="text-2xl font-black tabular-nums text-green-700">{{ broadcast.mail_sent_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Mail failed</p>
            <p class="text-2xl font-black tabular-nums text-red-700">{{ broadcast.mail_failed_count }}</p>
        </div>
    </section>

    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
        <header class="px-6 py-4 border-b border-outline-variant/60">
            <h2 class="text-sm font-black text-primary">Recipients ({{ rows.length }} on this page)</h2>
        </header>
        <table v-if="rows.length" class="w-full text-sm">
            <thead class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                <tr>
                    <th class="px-6 py-2">Recipient</th>
                    <th class="px-6 py-2">SMS</th>
                    <th class="px-6 py-2">Mail</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.id" class="border-t border-outline-variant/40">
                    <td class="px-6 py-2 font-mono text-xs">{{ r.recipient_type.split('\\').pop() }} #{{ r.recipient_id }}</td>
                    <td class="px-6 py-2">{{ r.sms_status ?? '—' }}</td>
                    <td class="px-6 py-2">{{ r.mail_status ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
        <div v-else class="px-6 py-8 text-center text-on-surface-variant text-sm">No recipients yet.</div>
    </section>
</div>
</template>

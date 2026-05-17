<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    subscriptions:    Object,
    activeModule:     String,
    flash_secret:     String,
    available_events: Array,
});

const showPanel = ref(false);
const copied = ref(false);

const form = useForm({
    partner_name:       '',
    callback_url:       '',
    subscribed_events:  ['*'],
});

const submit = () => form.post(route('webhooks.store'), {
    preserveScroll: true,
    onSuccess: () => { showPanel.value = false; form.reset(); form.subscribed_events = ['*']; },
});

const toggleActive = (s) => router.patch(route('webhooks.update', s.id), {
    is_active: !s.is_active,
    subscribed_events: s.subscribed_events,
}, { preserveScroll: true });

const remove = (id) => {
    if (confirm('Delete this webhook subscription? The partner will stop receiving events immediately.')) {
        router.delete(route('webhooks.destroy', id), { preserveScroll: true });
    }
};

const copy = async () => {
    try { await navigator.clipboard.writeText(props.flash_secret); copied.value = true; setTimeout(() => copied.value = false, 2500); } catch (e) {}
};
</script>

<template>
    <Head title="Webhook Subscriptions" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-on-surface-variant/60">HMAC-SHA256 signed event delivery to partner systems</p>
                    <h1 class="text-2xl font-semibold tracking-tight">Webhook Subscriptions</h1>
                </div>
                <PrimaryButton @click="showPanel = true">+ Register partner</PrimaryButton>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <!-- One-time flash of the signing secret -->
            <div v-if="flash_secret" class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800 mb-2">⚠ Save the signing secret now — it cannot be retrieved later</p>
                <div class="flex items-center gap-3">
                    <code class="flex-1 font-mono text-sm bg-white border border-amber-200 rounded-lg px-3 py-2 break-all">{{ flash_secret }}</code>
                    <PrimaryButton @click="copy">{{ copied ? 'Copied!' : 'Copy' }}</PrimaryButton>
                </div>
                <p class="text-xs text-on-surface-variant mt-3">
                    Partner verifies each delivery: <code class="bg-white px-1.5 py-0.5 rounded">sha256(timestamp + '.' + body)</code>
                    matches the <code class="bg-white px-1.5 py-0.5 rounded">X-CIHRMS-Signature</code> header.
                </p>
            </div>

            <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                <table class="w-full text-sm">
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Partner</th>
                            <th class="px-5 py-3 text-left">Callback URL</th>
                            <th class="px-5 py-3 text-left">Events</th>
                            <th class="px-5 py-3 text-left">Last delivery</th>
                            <th class="px-5 py-3 text-right">Failures</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="s in subscriptions.data" :key="s.id" class="hover:bg-surface-container-low/60">
                            <td class="px-5 py-3 font-medium">{{ s.partner_name }}</td>
                            <td class="px-5 py-3 text-xs font-mono truncate max-w-xs">{{ s.callback_url }}</td>
                            <td class="px-5 py-3 text-xs">
                                <span v-for="e in (s.subscribed_events ?? []).slice(0, 3)" :key="e"
                                      class="inline-block text-[10px] px-1.5 py-0.5 rounded bg-brand-navy/10 text-brand-navy mr-1 mb-0.5">{{ e }}</span>
                                <span v-if="(s.subscribed_events ?? []).length > 3" class="text-on-surface-variant/60 text-[10px]">+{{ s.subscribed_events.length - 3 }}</span>
                            </td>
                            <td class="px-5 py-3 text-xs">{{ s.last_delivery_at ? new Date(s.last_delivery_at).toLocaleString('en-GH') : 'never' }}</td>
                            <td class="px-5 py-3 text-right">
                                <span :class="s.failure_count > 0 ? 'text-rose-700 font-semibold' : 'text-on-surface-variant/40'">{{ s.failure_count }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <StatusBadge v-if="s.is_active" status="active" label="Active" />
                                <StatusBadge v-else status="inactive" label="Inactive" />
                            </td>
                            <td class="px-5 py-3 text-right space-x-3">
                                <button @click="toggleActive(s)" class="text-secondary text-xs hover:underline">
                                    {{ s.is_active ? 'Pause' : 'Resume' }}
                                </button>
                                <button @click="remove(s.id)" class="text-rose-600 text-xs hover:underline">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-outline-variant/40">
                    <Pagination :links="subscriptions?.meta?.links ?? []" />
                </div>
            </div>
        </div>

        <SlidePanel v-model="showPanel" title="Register webhook subscription">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Partner name</label>
                    <input v-model="form.partner_name" required maxlength="120" placeholder="GIFMIS / IPPD / etc."
                           class="w-full rounded-lg border-outline-variant text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Callback URL (https)</label>
                    <input v-model="form.callback_url" type="url" required placeholder="https://partner.gov.gh/webhooks/cihrms"
                           class="w-full rounded-lg border-outline-variant text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Subscribed events</label>
                    <div class="space-y-1 max-h-56 overflow-y-auto rounded-lg border border-outline-variant/40 p-3 bg-surface-container-low/40">
                        <label v-for="e in available_events" :key="e" class="flex items-center gap-2 text-xs cursor-pointer">
                            <input type="checkbox" :value="e" v-model="form.subscribed_events" :aria-label="`Subscribe to ${e} event`">
                            <code class="font-mono">{{ e }}</code>
                        </label>
                    </div>
                </div>
                <PrimaryButton type="submit" :disabled="form.processing || !form.partner_name || !form.callback_url || form.subscribed_events.length === 0">
                    Register
                </PrimaryButton>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    broadcasts: { type: Object, required: true },
    filters:    { type: Object, default: () => ({}) },
});

const status = ref(props.filters.status ?? '');

watch(status, () => {
    router.get(route('messaging.broadcasts.index'), {
        status: status.value || undefined,
    }, { preserveState: true, replace: true });
});

const rows = props.broadcasts.data ?? props.broadcasts ?? [];
</script>

<template>
<Head title="Broadcasts — Messaging" />
<div class="p-6 max-w-7xl mx-auto">
    <header class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-primary">Broadcasts</h1>
            <p class="text-sm text-on-surface-variant">Admin-initiated SMS + email to pre-defined audiences.</p>
        </div>
        <PrimaryButton @click="router.visit(route('messaging.broadcasts.create'))">New broadcast</PrimaryButton>
    </header>

    <div class="mb-4">
        <select v-model="status" aria-label="Filter by status"
                class="rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest">
            <option value="">All statuses</option>
            <option value="scheduled">Scheduled</option>
            <option value="queued">Queued</option>
            <option value="sending">Sending</option>
            <option value="completed">Completed</option>
            <option value="failed">Failed</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-surface-container">
                <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    <th class="px-4 py-3">Title</th>
                    <th class="px-4 py-3">Audience</th>
                    <th class="px-4 py-3">Channels</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Recipients</th>
                    <th class="px-4 py-3">Created</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="b in rows" :key="b.id" class="border-t border-outline-variant/40 hover:bg-surface-container/50">
                    <td class="px-4 py-2 font-semibold">
                        <Link :href="route('messaging.broadcasts.show', b.id)" class="text-primary hover:underline">
                            {{ b.title }}
                        </Link>
                    </td>
                    <td class="px-4 py-2">{{ b.audience_type }}</td>
                    <td class="px-4 py-2 font-mono text-xs">{{ (b.channels ?? []).join(' + ') }}</td>
                    <td class="px-4 py-2 capitalize">{{ b.status }}</td>
                    <td class="px-4 py-2 tabular-nums">{{ b.recipient_count }}</td>
                    <td class="px-4 py-2 text-xs">{{ new Date(b.created_at).toLocaleString() }}</td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="rows.length === 0" title="No broadcasts yet" subtitle="Click 'New broadcast' to send your first." />
    </div>
</div>
</template>

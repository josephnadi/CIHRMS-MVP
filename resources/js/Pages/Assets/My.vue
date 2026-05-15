<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    assignments: Object,
});
</script>

<template>
<Head title="My Assets" />
<AuthenticatedLayout active-module="assets">
    <div class="p-6 space-y-6 animate-reveal-up">
        <header>
            <Link :href="route('assets.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← All Assets</Link>
            <h1 class="text-[1.6rem] font-black tracking-tight text-primary mt-1">My Assets</h1>
            <p class="text-sm text-on-surface-variant">Equipment currently assigned to you.</p>
        </header>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
            <table v-if="props.assignments.data?.length" class="w-full text-sm">
                <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="p-4">Tag</th><th>Asset</th><th>Assigned</th><th>Due Back</th><th>Assigned By</th>
                </tr></thead>
                <tbody>
                    <tr v-for="a in props.assignments.data" :key="a.id" class="border-t border-outline-variant/40">
                        <td class="p-4 font-mono">{{ a.asset?.asset_tag ?? '—' }}</td>
                        <td>{{ a.asset?.name ?? '' }}</td>
                        <td class="text-xs">{{ new Date(a.assigned_at).toLocaleDateString() }}</td>
                        <td class="text-xs">{{ a.due_back_at ?? 'open-ended' }}</td>
                        <td class="text-xs text-on-surface-variant">{{ a.assigned_by ?? '' }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="You have no assigned assets." class="py-12" />
        </section>
    </div>
</AuthenticatedLayout>
</template>

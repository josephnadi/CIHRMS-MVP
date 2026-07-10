<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    assignments: Object,
});
</script>

<template>
<Head title="My Assets" />
    <div data-page-root="true">
        <div class="p-6 space-y-6 animate-reveal-up">
            <header>
                <Link :href="route('assets.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← All Assets</Link>
                <div class="flex items-center gap-2 mt-1 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">devices</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Self-service · Assigned equipment</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">My Assets</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Equipment currently issued to you — sign the return form here when handing back.
                </p>
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
                            <td class="text-xs">{{ a.due_back_at ? new Date(a.due_back_at).toLocaleDateString() : 'open-ended' }}</td>
                            <td class="text-xs text-on-surface-variant">{{ a.assigned_by ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else title="You have no assigned assets." class="py-12" />
            </section>
        </div>
    </div>
</template>

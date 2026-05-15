<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    policies:        Object,
    pending_ack_ids: Array,
});

const categoryLabel = {
    hr: 'HR', finance: 'Finance', it: 'IT', compliance: 'Compliance',
    safety: 'Safety', conduct: 'Conduct', other: 'Other',
};

const categoryTone = {
    hr:'bg-violet-100 text-violet-800', finance:'bg-amber-100 text-amber-800',
    it:'bg-sky-100 text-sky-800', compliance:'bg-rose-100 text-rose-800',
    safety:'bg-emerald-100 text-emerald-800', conduct:'bg-indigo-100 text-indigo-800',
    other:'bg-slate-100 text-slate-700',
};
</script>

<template>
<Head title="Governance" />
<AuthenticatedLayout active-module="governance">
    <div class="p-6 space-y-6 animate-reveal-up">
        <header class="flex items-center justify-between">
            <div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary">Governance</h1>
                <p class="text-sm text-on-surface-variant">Policies you must read and acknowledge. Compliance certifications under tracking.</p>
            </div>
            <div class="flex gap-2">
                <Link v-if="$page.props.auth.permissions?.includes('governance.manage')" :href="route('governance.manage')" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Manage Policies</Link>
                <Link :href="route('governance.certifications.index')" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Certifications</Link>
            </div>
        </header>

        <section v-if="props.pending_ack_ids?.length" class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-5">
            <p class="text-[10px] font-black uppercase tracking-widest text-amber-700">Action Required</p>
            <p class="text-sm font-bold text-amber-900 mt-1">You have {{ props.pending_ack_ids.length }} {{ props.pending_ack_ids.length === 1 ? 'policy' : 'policies' }} pending acknowledgement.</p>
        </section>

        <section>
            <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">All Policies</h2>
            <div v-if="props.policies.data?.length" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <Link v-for="p in props.policies.data" :key="p.id" :href="route('governance.policies.show', p.id)"
                    class="block rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 card-lift hover:border-primary/40 transition-colors">
                    <div class="flex items-start justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span :class="['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', categoryTone[p.category]]">{{ categoryLabel[p.category] }}</span>
                                <span v-if="p.my_ack_status === 'pending'" class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase bg-amber-100 text-amber-800">ACK REQUIRED</span>
                                <span v-else-if="p.my_ack_status === 'acknowledged'" class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800">ACK'D</span>
                            </div>
                            <h3 class="text-lg font-black text-primary mt-2 truncate">{{ p.title }}</h3>
                            <p v-if="p.summary" class="text-xs text-on-surface-variant mt-1 line-clamp-2">{{ p.summary }}</p>
                            <p v-if="p.current_version" class="text-[10px] text-on-surface-variant/70 mt-2 font-mono">v{{ p.current_version.version_number }} · effective {{ p.current_version.effective_from ?? '—' }}</p>
                            <p v-else class="text-[10px] text-on-surface-variant/70 mt-2 italic">No published version</p>
                        </div>
                    </div>
                </Link>
            </div>
            <EmptyState v-else title="No policies published yet." class="py-12" />
        </section>
    </div>
</AuthenticatedLayout>
</template>

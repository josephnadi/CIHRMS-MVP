<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

defineProps({
    member: { type: Object, required: true },
    assignments: { type: Array, default: () => [] },
});
</script>

<template>
<Head :title="`${member.name} — Member`" />
<div class="p-6 max-w-6xl mx-auto space-y-6">
    <header>
        <Link :href="route('billing.members.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← All members</Link>
        <h1 class="text-2xl font-black text-primary mt-1">{{ member.name }}</h1>
        <p class="text-sm text-on-surface-variant">
            <span class="font-mono">{{ member.member_no }}</span>
            · <span class="capitalize">{{ member.class }}</span>
            · <span class="capitalize">{{ member.status }}</span>
        </p>
    </header>

    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Email</p>
            <p>{{ member.email ?? '—' }}</p>
        </div>
        <div>
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Phone</p>
            <p>{{ member.phone ?? '—' }}</p>
        </div>
        <div>
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Date of birth</p>
            <p>{{ member.date_of_birth ?? '—' }}</p>
        </div>
        <div>
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Chartered</p>
            <p>{{ member.chartered_at ? new Date(member.chartered_at).toLocaleDateString() : '—' }}</p>
        </div>
    </section>

    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
        <header class="px-6 py-4 border-b border-outline-variant/60">
            <h2 class="text-sm font-black text-primary">Fee assignments</h2>
        </header>
        <table v-if="assignments.length" class="w-full text-sm">
            <thead class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                <tr>
                    <th class="px-6 py-2">Period</th>
                    <th class="px-6 py-2">Fee</th>
                    <th class="px-6 py-2">Status</th>
                    <th class="px-6 py-2">AR invoice</th>
                    <th class="px-6 py-2">Due</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in assignments" :key="a.id" class="border-t border-outline-variant/40">
                    <td class="px-6 py-2 font-mono">{{ a.period_label }}</td>
                    <td class="px-6 py-2">{{ a.fee_product.code }} — {{ a.fee_product.name }}</td>
                    <td class="px-6 py-2 capitalize">{{ a.status }}</td>
                    <td class="px-6 py-2 font-mono">{{ a.ar_invoice_ref ?? '—' }}</td>
                    <td class="px-6 py-2">{{ a.due_date ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
        <div v-else class="px-6 py-8 text-center text-on-surface-variant text-sm">
            No fees assigned to this member yet.
        </div>
    </section>
</div>
</template>

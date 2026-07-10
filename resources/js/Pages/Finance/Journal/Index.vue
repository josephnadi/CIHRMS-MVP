<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    entries:    { type: Object, default: () => ({ data: [] }) },
    filters:    { type: Object, default: () => ({}) },
    focusEntry: { type: [Object, null], default: null },
});

const rows = computed(() => props.entries.data ?? []);
const sourceFilter = ref(props.filters.source_type ?? '');
const statusFilter = ref(props.filters.status ?? '');

const apply = () => router.get(route('finance.journal.index'), {
    source_type: sourceFilter.value || undefined,
    status:      statusFilter.value || undefined,
}, { preserveState: true, replace: true });

const cedi = (v) => Number(v || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const statusColor = (val) => ({
    draft:    'text-on-surface-variant bg-surface-container border-outline-variant',
    posted:   'text-emerald-700 bg-emerald-50 border-emerald-100',
    reversed: 'text-rose-700 bg-rose-50 border-rose-100',
}[val] ?? 'text-on-surface-variant');
</script>

<template>
    <Head title="Journal Entries" />

    <div class="space-y-6 animate-reveal-up">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — AUDIT</p>
            <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Journal Entries</h1>
            <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} entries · every business event posts a balanced JE.</p>
        </div>

        <div class="flex flex-wrap gap-2 items-center">
            <select v-model="sourceFilter" @change="apply" aria-label="Source type" class="rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] bg-surface-container-lowest">
                <option value="">All sources</option>
                <option value="manual">Manual</option>
                <option value="vendor_invoice">Vendor Invoice</option>
                <option value="ap_payment">AP Payment</option>
            </select>
            <select v-model="statusFilter" @change="apply" aria-label="Status" class="rounded-xl border border-outline-variant px-3 py-1.5 text-[12px] bg-surface-container-lowest">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="posted">Posted</option>
                <option value="reversed">Reversed</option>
            </select>
        </div>

        <div v-if="focusEntry" class="rounded-2xl border border-secondary/30 bg-secondary/5 p-5 space-y-3">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-[15px] font-black text-primary">{{ focusEntry.reference }}</h3>
                    <p class="text-[11px] text-on-surface-variant">{{ focusEntry.source_type.label }} · {{ focusEntry.entry_date }}</p>
                </div>
                <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(focusEntry.status.value)">{{ focusEntry.status.label }}</span>
            </div>
            <p v-if="focusEntry.narration" class="text-[12px] text-on-surface">{{ focusEntry.narration }}</p>
            <table class="w-full text-[11px]">
                <thead class="border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="py-1.5 font-black uppercase text-[9px] tracking-wider text-on-surface-variant">#</th>
                        <th class="py-1.5 font-black uppercase text-[9px] tracking-wider text-on-surface-variant">GL Account</th>
                        <th class="py-1.5 font-black uppercase text-[9px] tracking-wider text-on-surface-variant text-right">Debit</th>
                        <th class="py-1.5 font-black uppercase text-[9px] tracking-wider text-on-surface-variant text-right">Credit</th>
                        <th class="py-1.5 font-black uppercase text-[9px] tracking-wider text-on-surface-variant">Narration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in focusEntry.lines" :key="l.id" class="border-b border-outline-variant/20">
                        <td class="py-1 font-mono">{{ l.line_no }}</td>
                        <td class="py-1 font-mono">{{ l.gl_account?.code }} — {{ l.gl_account?.name }}</td>
                        <td class="py-1 text-right font-mono">{{ l.debit_amount > 0 ? cedi(l.debit_amount) : '—' }}</td>
                        <td class="py-1 text-right font-mono">{{ l.credit_amount > 0 ? cedi(l.credit_amount) : '—' }}</td>
                        <td class="py-1 text-on-surface-variant">{{ l.narration ?? '' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-x-auto">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reference</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Date</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Source</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Status</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Narration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in rows" :key="e.id" class="border-t border-outline-variant/30 hover:bg-surface-container/40">
                        <td class="px-4 py-2 font-mono font-bold text-primary">
                            <Link :href="route('finance.journal.show', e.id)" class="hover:underline">{{ e.reference }}</Link>
                        </td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ e.entry_date }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ e.source_type.label }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(e.status.value)">{{ e.status.label }}</span>
                        </td>
                        <td class="px-4 py-2 text-on-surface-variant truncate max-w-md">{{ e.narration ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

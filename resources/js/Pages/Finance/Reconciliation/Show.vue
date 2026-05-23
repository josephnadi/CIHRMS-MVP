<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    statement:      { type: Object, required: true },
    lines:          { type: Array, default: () => [] },
    unreconciledAp: { type: Array, default: () => [] },
    unreconciledAr: { type: Array, default: () => [] },
});

const page = usePage();
const can = (perm) => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes(perm);
};
const canMatch = computed(() => can('reconciliation.match'));
const canAdjust = computed(() => can('reconciliation.adjust'));

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const selectedLine = ref(null);
const selectLine = (l) => { selectedLine.value = l; };

const linkTarget = (target, type) => {
    if (!selectedLine.value) return;
    router.post(route('finance.reconciliation.link', selectedLine.value.id), {
        target_type: type, target_id: target.id,
    }, { preserveScroll: true, onSuccess: () => { selectedLine.value = null; } });
};

const unlinkLine = (line) => {
    const reason = window.prompt('Reason for unlinking?');
    if (!reason) return;
    router.post(route('finance.reconciliation.unlink', line.id), { reason }, { preserveScroll: true });
};

const adjustModal = ref(null);
const adjForm = useForm({ gl_account_id: null, narration: '' });
const openAdjust = (line) => { adjustModal.value = line; adjForm.reset(); };
const submitAdjust = () => {
    if (!adjustModal.value) return;
    adjForm.post(route('finance.reconciliation.adjust', adjustModal.value.id), {
        preserveScroll: true,
        onSuccess: () => { adjustModal.value = null; },
    });
};

const confidenceColor = (c) => ({
    high:   'text-emerald-700 bg-emerald-50 border-emerald-100',
    medium: 'text-blue-700 bg-blue-50 border-blue-100',
    low:    'text-amber-700 bg-amber-50 border-amber-100',
    manual: 'text-violet-700 bg-violet-50 border-violet-100',
}[c] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');

const unmatched = computed(() => props.lines.filter(l => !l.reconciled_at));
const reconciled = computed(() => props.lines.filter(l => l.reconciled_at));
</script>

<template>
    <Head :title="`Statement ${statement.statement_date}`" />

    <div class="space-y-6 animate-reveal-up">
        <div>
            <Link :href="route('finance.reconciliation.index')" class="text-[11px] font-bold text-secondary hover:underline">← Back to statements</Link>
            <div class="mt-2 flex items-center justify-between">
                <div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary">{{ statement.org_bank_account?.bank_name }} · {{ statement.statement_date }}</h1>
                    <p class="text-[13px] text-on-surface-variant mt-0.5">Closing {{ cedi(statement.closing_balance) }} · {{ statement.reconciled_lines }}/{{ statement.total_lines }} lines reconciled ({{ statement.reconciled_pct }}%)</p>
                </div>
                <div class="flex items-center gap-2">
                    <a :href="route('finance.reconciliation.print', statement.id)" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                        <span class="material-symbols-outlined text-[16px]">print</span>
                        View / Print
                    </a>
                    <a :href="route('finance.reconciliation.print', { bankStatement: statement.id, download: 1 })"
                       class="inline-flex items-center gap-1.5 rounded-xl bg-primary text-on-primary px-3 py-2 text-[12px] font-bold hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-[16px]">download</span>
                        Download PDF
                    </a>
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 space-y-2">
                <h4 class="text-[13px] font-black text-primary">Unmatched statement lines ({{ unmatched.length }})</h4>
                <div v-if="unmatched.length" class="space-y-1.5">
                    <button v-for="l in unmatched" :key="l.id" @click="selectLine(l)"
                            :class="['w-full text-left rounded-xl border p-3 text-[12px] transition-colors',
                                selectedLine?.id === l.id ? 'border-secondary bg-secondary/5' : 'border-outline-variant/40 hover:border-secondary/40']">
                        <div class="flex items-center justify-between">
                            <span class="font-mono">{{ l.transaction_date }}</span>
                            <span class="font-mono font-bold" :class="l.amount < 0 ? 'text-rose-700' : 'text-emerald-700'">{{ cedi(Math.abs(l.amount)) }}{{ l.amount < 0 ? ' Dr' : ' Cr' }}</span>
                        </div>
                        <p class="text-on-surface mt-0.5">{{ l.description }}</p>
                        <p class="text-[10px] text-on-surface-variant mt-0.5">{{ l.reference ?? '—' }}</p>
                        <p v-if="l.confidence" class="mt-1">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="confidenceColor(l.confidence)">{{ l.confidence }} confidence</span>
                        </p>
                        <p v-if="canAdjust" class="mt-1 flex justify-end">
                            <button @click.stop="openAdjust(l)" class="text-[10px] font-bold text-secondary hover:underline">Post adjustment</button>
                        </p>
                    </button>
                </div>
                <p v-else class="text-[12px] text-on-surface-variant">All lines reconciled.</p>
            </section>

            <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 space-y-2">
                <h4 class="text-[13px] font-black text-primary">
                    <span v-if="selectedLine">Pair with…</span>
                    <span v-else class="text-on-surface-variant">Select a line on the left</span>
                </h4>
                <div v-if="selectedLine && selectedLine.amount < 0 && unreconciledAp.length" class="space-y-1.5">
                    <button v-for="p in unreconciledAp" :key="p.id" :disabled="!canMatch" @click="linkTarget(p, 'ap_payment')"
                            class="w-full text-left rounded-xl border border-outline-variant/40 p-3 text-[12px] hover:border-secondary/40 transition-colors">
                        <div class="flex items-center justify-between">
                            <span class="font-mono">{{ p.reference }}</span>
                            <span class="font-mono">{{ cedi(p.amount) }}</span>
                        </div>
                        <p class="text-[10px] text-on-surface-variant mt-0.5">{{ p.payment_date }} · ext: {{ p.external_ref ?? '—' }}</p>
                    </button>
                </div>
                <div v-else-if="selectedLine && selectedLine.amount > 0 && unreconciledAr.length" class="space-y-1.5">
                    <button v-for="r in unreconciledAr" :key="r.id" :disabled="!canMatch" @click="linkTarget(r, 'ar_receipt')"
                            class="w-full text-left rounded-xl border border-outline-variant/40 p-3 text-[12px] hover:border-secondary/40 transition-colors">
                        <div class="flex items-center justify-between">
                            <span class="font-mono">{{ r.reference }}</span>
                            <span class="font-mono">{{ cedi(r.amount) }}</span>
                        </div>
                        <p class="text-[10px] text-on-surface-variant mt-0.5">{{ r.receipt_date }} · ext: {{ r.external_ref ?? '—' }}</p>
                    </button>
                </div>
                <p v-else-if="selectedLine" class="text-[12px] text-on-surface-variant">No unreconciled candidates on this side.</p>
            </section>
        </div>

        <section v-if="reconciled.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 space-y-2">
            <h4 class="text-[13px] font-black text-primary">Reconciled ({{ reconciled.length }})</h4>
            <div class="space-y-1.5">
                <div v-for="l in reconciled" :key="l.id" class="flex items-center justify-between rounded-xl border border-outline-variant/30 p-2.5 text-[12px]">
                    <div>
                        <span class="font-mono mr-2">{{ l.transaction_date }}</span>
                        <span>{{ l.description }}</span>
                        <span class="ml-2 rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="confidenceColor(l.confidence)">{{ l.confidence }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="font-mono font-bold" :class="l.amount < 0 ? 'text-rose-700' : 'text-emerald-700'">{{ cedi(Math.abs(l.amount)) }}</span>
                        <button v-if="canMatch" @click="unlinkLine(l)" class="text-[10px] font-bold text-rose-700 hover:underline">Unlink</button>
                    </div>
                </div>
            </div>
        </section>

        <div v-if="adjustModal" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center">
            <div class="bg-surface-container-lowest rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-[14px] font-black text-primary mb-3">Post Bank Adjustment</h3>
                <p class="text-[11px] text-on-surface-variant mb-4">{{ adjustModal.description }} · {{ cedi(Math.abs(adjustModal.amount)) }}</p>
                <form @submit.prevent="submitAdjust" class="space-y-3">
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Offset GL Account ID</label>
                        <input v-model.number="adjForm.gl_account_id" type="number" min="1"
                               class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Narration</label>
                        <input v-model="adjForm.narration" type="text"
                               class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="adjustModal = null" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                        <button type="submit" :disabled="adjForm.processing" class="rounded-xl bg-primary text-on-primary px-3 py-2 text-[12px] font-bold">Post</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

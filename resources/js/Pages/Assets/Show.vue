<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    asset:        Object,
    assignments:  Object,
    maintenance:  Object,
    depreciation: Array,
});

const tab = ref('assignments');

const showReturn = ref(false);
const showMaint = ref(false);
const showRetire = ref(false);
const showLost = ref(false);

const returnForm = useForm({ condition_on_return: 'good', notes: '' });
const maintForm = useForm({ type: 'repair', cost: null, vendor: '', notes: '' });
const retireForm = useForm({ reason: '' });
const lostForm = useForm({ reason: '' });

function submitReturn() {
    returnForm.post(route('assets.return', props.asset.current_assignment.id), {
        preserveScroll: true, onSuccess: () => showReturn.value = false,
    });
}
function submitMaint() {
    maintForm.post(route('assets.maintenance.store', props.asset.id), {
        preserveScroll: true, onSuccess: () => showMaint.value = false,
    });
}
function completeMaint(m) {
    if (! confirm('Mark this maintenance as completed?')) return;
    router.patch(route('assets.maintenance.complete', m.id), {}, { preserveScroll: true });
}
function submitRetire() {
    retireForm.patch(route('assets.retire', props.asset.id), {
        preserveScroll: true, onSuccess: () => showRetire.value = false,
    });
}
function submitLost() {
    lostForm.patch(route('assets.lost', props.asset.id), {
        preserveScroll: true, onSuccess: () => showLost.value = false,
    });
}

const statusCls = {
    in_stock: 'bg-blue-100 text-blue-800',
    assigned: 'bg-emerald-100 text-emerald-800',
    maintenance: 'bg-amber-100 text-amber-800',
    retired: 'bg-slate-100 text-slate-700',
    lost: 'bg-rose-100 text-rose-800',
};
</script>

<template>
<Head :title="`${asset.asset_tag} — ${asset.name}`" />
    <div data-page-root="true">
        <div class="p-6 space-y-6 animate-reveal-up">
            <header class="flex items-center justify-between">
                <div>
                    <Link :href="route('assets.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← All Assets</Link>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary mt-1">{{ asset.asset_tag }} — {{ asset.name }}</h1>
                    <p class="text-sm text-on-surface-variant">{{ asset.brand }} {{ asset.model }} · {{ asset.category }} · S/N {{ asset.serial_number ?? '—' }}</p>
                </div>
                <span :class="['rounded-full px-3 py-1 text-xs font-bold uppercase', statusCls[asset.current_status]]">{{ asset.current_status?.replace('_',' ') }}</span>
            </header>

            <div class="grid grid-cols-12 gap-6">
                <aside class="col-span-12 lg:col-span-4 space-y-4">
                    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 card-lift">
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-3">Details</h2>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between"><dt class="text-on-surface-variant">Purchase</dt><dd>{{ asset.purchase_date ?? '—' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-on-surface-variant">Cost</dt><dd>{{ asset.purchase_cost !== null ? `${asset.currency} ${asset.purchase_cost.toFixed(2)}` : '—' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-on-surface-variant">Supplier</dt><dd>{{ asset.supplier ?? '—' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-on-surface-variant">Warranty</dt><dd>{{ asset.warranty_expires_at ?? '—' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-on-surface-variant">Location</dt><dd>{{ asset.location ?? '—' }}</dd></div>
                        </dl>
                    </section>

                    <section v-if="asset.current_assignment" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 card-lift">
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-3">Current Assignment</h2>
                        <p class="text-sm font-bold">{{ asset.current_assignment.employee_name }}</p>
                        <p class="text-xs text-on-surface-variant">{{ asset.current_assignment.employee_no }} · since {{ new Date(asset.current_assignment.assigned_at).toLocaleDateString() }}</p>
                        <p v-if="asset.current_assignment.due_back_at" class="mt-1 text-xs">Due back: <span class="font-bold">{{ asset.current_assignment.due_back_at }}</span></p>
                    </section>

                    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 card-lift space-y-2">
                        <h2 class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-2">Actions</h2>
                        <button v-if="asset.current_assignment" @click="showReturn = true" class="w-full rounded-xl bg-emerald-50 text-emerald-800 py-2 text-xs font-bold hover:bg-emerald-100">Return Asset</button>
                        <button v-if="$page.props.auth.permissions?.includes('assets.manage')" @click="showMaint = true" class="w-full rounded-xl bg-amber-50 text-amber-800 py-2 text-xs font-bold hover:bg-amber-100">Log Maintenance</button>
                        <button v-if="$page.props.auth.permissions?.includes('assets.manage')" @click="showRetire = true" class="w-full rounded-xl bg-slate-100 text-slate-800 py-2 text-xs font-bold hover:bg-slate-200">Retire</button>
                        <button v-if="$page.props.auth.permissions?.includes('assets.manage')" @click="showLost = true" class="w-full rounded-xl bg-rose-50 text-rose-800 py-2 text-xs font-bold hover:bg-rose-100">Mark Lost</button>
                    </section>
                </aside>

                <main class="col-span-12 lg:col-span-8">
                    <div class="flex gap-2 mb-4">
                        <button @click="tab = 'assignments'" :class="['rounded-xl px-3 py-1.5 text-xs font-bold border', tab === 'assignments' ? 'border-primary bg-primary/5 text-primary' : 'border-outline-variant text-on-surface-variant']">Assignment history</button>
                        <button @click="tab = 'maintenance'" :class="['rounded-xl px-3 py-1.5 text-xs font-bold border', tab === 'maintenance' ? 'border-primary bg-primary/5 text-primary' : 'border-outline-variant text-on-surface-variant']">Maintenance</button>
                        <button @click="tab = 'depreciation'" :class="['rounded-xl px-3 py-1.5 text-xs font-bold border', tab === 'depreciation' ? 'border-primary bg-primary/5 text-primary' : 'border-outline-variant text-on-surface-variant']">Depreciation</button>
                    </div>

                    <section v-if="tab === 'assignments'" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                        <table v-if="assignments.data?.length" class="w-full text-sm">
                            <thead><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                                <th class="p-4">Employee</th><th>Assigned</th><th>Returned</th><th>Condition</th><th>By</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="a in assignments.data" :key="a.id" class="border-t border-outline-variant/40">
                                    <td class="p-4">{{ a.employee_name }} <span class="font-mono text-xs text-on-surface-variant">({{ a.employee_no }})</span></td>
                                    <td class="text-xs">{{ new Date(a.assigned_at).toLocaleDateString() }}</td>
                                    <td class="text-xs">{{ a.returned_at ? new Date(a.returned_at).toLocaleDateString() : '—' }}</td>
                                    <td class="text-xs">{{ a.condition_on_return ?? '—' }}</td>
                                    <td class="text-xs text-on-surface-variant">{{ a.assigned_by }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="p-8 text-center text-on-surface-variant text-sm">No assignments yet.</p>
                    </section>

                    <section v-if="tab === 'maintenance'" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                        <table v-if="maintenance.data?.length" class="w-full text-sm">
                            <thead><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                                <th class="p-4">Type</th><th>Status</th><th>Started</th><th>Completed</th><th>Vendor</th><th>Cost</th><th></th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="m in maintenance.data" :key="m.id" class="border-t border-outline-variant/40">
                                    <td class="p-4">{{ m.type }}</td>
                                    <td>{{ m.status?.replace('_',' ') }}</td>
                                    <td class="text-xs">{{ new Date(m.started_at).toLocaleDateString() }}</td>
                                    <td class="text-xs">{{ m.completed_at ? new Date(m.completed_at).toLocaleDateString() : '—' }}</td>
                                    <td class="text-xs">{{ m.vendor ?? '—' }}</td>
                                    <td class="text-xs">{{ m.cost !== null ? m.cost.toFixed(2) : '—' }}</td>
                                    <td>
                                        <button v-if="m.status === 'open' && $page.props.auth.permissions?.includes('assets.manage')" @click="completeMaint(m)" class="rounded-lg bg-emerald-50 text-emerald-700 px-3 py-1 text-xs font-bold hover:bg-emerald-100">Complete</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="p-8 text-center text-on-surface-variant text-sm">No maintenance recorded.</p>
                    </section>

                    <section v-if="tab === 'depreciation'" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                        <table v-if="depreciation?.length" class="w-full text-sm">
                            <thead><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                                <th class="p-4">As of</th><th>Book Value</th><th>Method</th><th>Useful Life</th><th>Salvage</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="d in depreciation" :key="d.id" class="border-t border-outline-variant/40">
                                    <td class="p-4">{{ d.as_of_date }}</td>
                                    <td class="font-mono">{{ asset.currency }} {{ Number(d.book_value).toFixed(2) }}</td>
                                    <td>{{ d.method }}</td>
                                    <td>{{ d.useful_life_years }} yr</td>
                                    <td class="font-mono">{{ asset.currency }} {{ Number(d.salvage_value).toFixed(2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="p-8 text-center text-on-surface-variant text-sm">No depreciation snapshots yet. Monthly cron generates these.</p>
                    </section>
                </main>
            </div>
        </div>

        <SlidePanel :open="showReturn" @close="showReturn = false" title="Return Asset">
            <form @submit.prevent="submitReturn" class="space-y-3 p-4">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Condition on return</label><select v-model="returnForm.condition_on_return" aria-label="Condition on return" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option value="good">Good</option><option value="fair">Fair</option><option value="poor">Poor</option><option value="damaged">Damaged (auto-opens maintenance)</option></select></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Notes</label><textarea v-model="returnForm.notes" aria-label="Return notes" rows="2" maxlength="500" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
                <button type="submit" :disabled="returnForm.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Confirm Return</button>
            </form>
        </SlidePanel>

        <SlidePanel :open="showMaint" @close="showMaint = false" title="Log Maintenance">
            <form @submit.prevent="submitMaint" class="space-y-3 p-4">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Type</label><select v-model="maintForm.type" aria-label="Maintenance type" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option value="repair">Repair</option><option value="service">Service</option><option value="upgrade">Upgrade</option></select></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Vendor</label><input v-model="maintForm.vendor" aria-label="Maintenance vendor" maxlength="120" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Cost ({{ asset.currency }})</label><input v-model.number="maintForm.cost" aria-label="Maintenance cost" type="number" step="0.01" min="0" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Notes</label><textarea v-model="maintForm.notes" aria-label="Maintenance notes" rows="3" maxlength="1000" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
                <button type="submit" :disabled="maintForm.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Log Maintenance</button>
            </form>
        </SlidePanel>

        <SlidePanel :open="showRetire" @close="showRetire = false" title="Retire Asset">
            <form @submit.prevent="submitRetire" class="space-y-3 p-4">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Reason (5+ chars)</label><textarea v-model="retireForm.reason" aria-label="Retirement reason" required minlength="5" maxlength="500" rows="3" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
                <button type="submit" :disabled="retireForm.processing" class="w-full rounded-xl bg-slate-700 px-4 py-2 text-sm font-bold text-white">Retire Asset</button>
            </form>
        </SlidePanel>

        <SlidePanel :open="showLost" @close="showLost = false" title="Mark Asset Lost">
            <form @submit.prevent="submitLost" class="space-y-3 p-4">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Reason (5+ chars)</label><textarea v-model="lostForm.reason" aria-label="Loss-circumstances reason" required minlength="5" maxlength="500" rows="3" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
                <button type="submit" :disabled="lostForm.processing" class="w-full rounded-xl bg-rose-700 px-4 py-2 text-sm font-bold text-white">Mark Lost</button>
            </form>
        </SlidePanel>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';

const props = defineProps({
    assets:       Object,
    stats:        Object,
    employees:    Array,
    departments:  Array,
    filters:      Object,
});

// Total Assets gets gold — institutional inventory headline
const statCards = computed(() => [
    { label: 'Total Assets', val: props.stats?.total ?? 0,       rgb: '255,215,0',  icon: 'inventory_2' },
    { label: 'Assigned',     val: props.stats?.assigned ?? 0,    rgb: '5,150,105',  icon: 'check_circle' },
    { label: 'Maintenance',  val: props.stats?.maintenance ?? 0, rgb: '217,119,6',  icon: 'build' },
    { label: 'Available',    val: props.stats?.in_stock ?? 0,    rgb: '26, 35, 126',  icon: 'archive' },
]);

// Editorial Sovereign masthead — date, volume, and issue number for the
// asset-register broadsheet. Volume counts from CIHRM-GH inception (2023),
// issue is the ordinal day-of-year.
const editionLabel = computed(() => {
    const d   = new Date();
    const day = Math.floor((d - new Date(d.getFullYear(), 0, 0)) / 86_400_000);
    const vol = d.getFullYear() - 2023;
    const roman = (n) => {
        const map = [['M',1000],['CM',900],['D',500],['CD',400],['C',100],['XC',90],['L',50],['XL',40],['X',10],['IX',9],['V',5],['IV',4],['I',1]];
        let s = '';
        for (const [r, v] of map) while (n >= v) { s += r; n -= v; }
        return s;
    };
    return {
        date: d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
    };
});

const localFilters = ref({
    category: props.filters?.category ?? '',
    status:   props.filters?.status ?? '',
    search:   props.filters?.search ?? '',
});

function applyFilters() {
    router.get(route('assets.index'), localFilters.value, { preserveScroll: true, preserveState: true });
}

const showCreate = ref(false);
const showAssign = ref(false);
const assignTarget = ref(null);

const newAsset = useForm({
    asset_tag: '', name: '',
    category: 'laptop', serial_number: '',
    brand: '', model: '',
    purchase_date: '', purchase_cost: '', currency: 'GHS',
    supplier: '', warranty_expires_at: '',
    location: '', notes: '',
});

const assignForm = useForm({ employee_id: '', due_back_at: '', notes: '' });

function createAsset() {
    newAsset.post(route('assets.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; newAsset.reset(); },
    });
}

function openAssign(asset) {
    assignTarget.value = asset;
    assignForm.reset();
    showAssign.value = true;
}

function submitAssign() {
    assignForm.post(route('assets.assign', assignTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => { showAssign.value = false; assignTarget.value = null; },
    });
}

// ── Un-assign (return) ─────────────────────────────────────────────
// Closes the current assignment row, putting the asset back into stock.
// The backend records who returned it and the reason on the assignment.
function unassignAsset(asset) {
    if (! asset.current_assignment?.id) return;
    const condition = window.prompt(
        `Return ${asset.asset_tag} — ${asset.name}?\n\nCondition on return (good / fair / damaged / lost):`,
        'good',
    );
    if (! condition) return;
    const notes = window.prompt('Optional notes (scratches, missing accessories, etc.):', '') ?? '';
    router.post(
        route('assets.return', asset.current_assignment.id),
        { condition_on_return: condition.trim().toLowerCase(), notes },
        { preserveScroll: true },
    );
}

// ── Retire ─────────────────────────────────────────────────────────
// End-of-life — disposal, sale, donation. Requires a reason for the
// asset ledger.
function retireAsset(asset) {
    const reason = window.prompt(
        `Retire ${asset.asset_tag} — ${asset.name}?\n\nThis closes the asset's lifecycle. Reason (sold / donated / disposed / end-of-life):`,
        'end-of-life',
    );
    if (! reason || ! reason.trim()) return;
    router.patch(route('assets.retire', asset.id), { reason }, { preserveScroll: true });
}

// ── Mark lost ──────────────────────────────────────────────────────
// Asset can't be located. Triggers the loss-investigation workflow on
// the back-end (separate from retire — retired assets are accounted
// for, lost ones are not).
function markAssetLost(asset) {
    const reason = window.prompt(
        `Mark ${asset.asset_tag} — ${asset.name} as LOST?\n\nThis triggers a loss investigation. Brief circumstances:`,
        '',
    );
    if (! reason || ! reason.trim()) return;
    router.patch(route('assets.lost', asset.id), { reason }, { preserveScroll: true });
}


const statusTone = {
    in_stock:    { label: 'Available',    cls: 'bg-blue-100 text-blue-800' },
    assigned:    { label: 'Assigned',     cls: 'bg-emerald-100 text-emerald-800' },
    maintenance: { label: 'Maintenance',  cls: 'bg-amber-100 text-amber-800' },
    retired:     { label: 'Retired',      cls: 'bg-slate-100 text-slate-700' },
    lost:        { label: 'Lost',         cls: 'bg-rose-100 text-rose-800' },
};

const categoryLabel = {
    laptop: 'Laptop', monitor: 'Monitor', phone: 'Phone',
    vehicle: 'Vehicle', furniture: 'Furniture', other: 'Other',
};
</script>

<template>
<Head title="Assets" />
<AuthenticatedLayout :active-module="'assets'">
    <div class="p-6 space-y-6 animate-reveal-up">
        <!-- ─── Editorial Sovereign · Asset Register masthead ──────── -->
        <div class="es-masthead">
            <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">ASSET REGISTER</span></span>
            <span class="es-masthead-spacer"></span>
            <span>{{ editionLabel.date }}</span>
            <span class="es-masthead-spacer"></span>
            <span>{{ editionLabel.edition }}</span>
            <span class="es-masthead-spacer"></span>
            <span class="es-masthead-live">
                <span class="es-dot" aria-hidden="true"></span>
                Live · ledger synced
            </span>
        </div>

        <!-- ─── Broadsheet hero ────────────────────────────────────── -->
        <div class="es-broadsheet rounded-none">
            <!-- LEAD column -->
            <div class="es-broadsheet-lead">
                <p class="es-eyebrow mb-6">Phase 3 · Inventory &amp; lifecycle</p>
                <h2 class="es-display text-[clamp(2.4rem,5.5vw,4.6rem)]">
                    The asset
                    <span class="es-display-italic block">register.</span>
                </h2>
                <p class="es-display-sub">
                    Institutional inventory of record — laptops, monitors, vehicles, and furniture tracked from
                    procurement through assignment, depreciation snapshots, and end-of-life. Every movement
                    is filed for the Auditor-General lifecycle pack.
                </p>

                <!-- Quick-action chips — typographic, not gradient buttons -->
                <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                    <button v-if="$page.props.auth.permissions?.includes('assets.manage')" @click="showCreate = true" type="button" class="es-chip">
                        <span class="material-symbols-outlined text-[15px]">add_box</span>
                        Register asset
                    </button>
                    <span class="text-on-surface-variant/30">·</span>
                    <Link :href="route('assets.my')" class="es-chip">
                        <span class="material-symbols-outlined text-[15px]">badge</span>
                        My assets
                    </Link>
                    <span class="text-on-surface-variant/30">·</span>
                    <button @click="localFilters.status = 'maintenance'; applyFilters()" type="button" class="es-chip">
                        <span class="material-symbols-outlined text-[15px]">fact_check</span>
                        Lifecycle audit
                    </button>
                </div>
            </div>

            <!-- SIDEBAR column: feature KPI as magazine drop-cap stat -->
            <div class="es-broadsheet-sidebar">
                <div class="es-stat-hero">
                    <p class="es-stat-hero-label">Assets on Register</p>
                    <p class="es-stat-hero-value">{{ (props.stats?.total ?? 0).toLocaleString() }}</p>
                    <p class="es-stat-hero-caption">
                        Institutional inventory · {{ (props.stats?.assigned ?? 0).toLocaleString() }} in active custody
                    </p>
                    <span class="es-stat-hero-delta">
                        <span class="material-symbols-outlined text-[13px]">history_edu</span>
                        Depreciation snapshot · Auditor-General pack
                    </span>
                </div>
            </div>
        </div>

        <!-- ─── Supporting metrics strip (broadsheet sub-numbers) ─── -->
        <div class="es-stat-strip rounded-none">
            <div v-for="c in statCards" :key="c.label" class="es-stat-cell">
                <p class="es-stat-cell-label">{{ c.label }}</p>
                <p class="es-stat-cell-value">{{ c.val.toLocaleString() }}</p>
                <p class="es-stat-cell-caption">
                    <span v-if="c.label === 'Total Assets'">Across all categories</span>
                    <span v-else-if="c.label === 'Assigned'">In employee custody</span>
                    <span v-else-if="c.label === 'Maintenance'">Out for service</span>
                    <span v-else>Ready to deploy</span>
                </p>
            </div>
        </div>

        <div class="flex flex-wrap gap-3 items-center">
            <SearchInput v-model="localFilters.search" placeholder="Search by tag, name, serialâ€¦" @update:modelValue="applyFilters" class="flex-1 max-w-md" />
            <select v-model="localFilters.category" @change="applyFilters" aria-label="Filter by category" class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm">
                <option value="">All Categories</option>
                <option v-for="(label, key) in categoryLabel" :key="key" :value="key">{{ label }}</option>
            </select>
            <select v-model="localFilters.status" @change="applyFilters" aria-label="Filter by status" class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm">
                <option value="">All Statuses</option>
                <option v-for="(t, key) in statusTone" :key="key" :value="key">{{ t.label }}</option>
            </select>
        </div>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
            <table v-if="props.assets.data?.length" class="w-full text-sm">
                <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="p-4">Tag</th><th>Name</th><th>Category</th><th>Status</th><th>Assigned To</th><th>Location</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-for="a in props.assets.data" :key="a.id" class="border-t border-outline-variant/40 hover:bg-surface-container-low/30 transition-colors">
                        <td class="p-4 font-mono"><Link :href="route('assets.show', a.id)" class="text-secondary hover:underline">{{ a.asset_tag }}</Link></td>
                        <td>{{ a.name }} <span class="text-on-surface-variant text-xs">{{ a.brand }} {{ a.model }}</span></td>
                        <td>{{ categoryLabel[a.category] }}</td>
                        <td><span :class="['rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider', statusTone[a.current_status]?.cls]">{{ statusTone[a.current_status]?.label }}</span></td>
                        <td>
                            <span v-if="a.current_assignment" class="text-xs">{{ a.current_assignment.employee_name }} <span class="text-on-surface-variant font-mono">({{ a.current_assignment.employee_no }})</span></span>
                            <span v-else class="text-xs text-on-surface-variant">â€”</span>
                        </td>
                        <td class="text-xs">{{ a.location ?? 'â€”' }}</td>
                        <td class="whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <!-- Assign (only when in stock) -->
                                <button v-if="a.current_status === 'in_stock' && ($page.props.auth.permissions?.includes('assets.manage') || $page.props.auth.permissions?.includes('assets.assign'))"
                                        @click="openAssign(a)" type="button"
                                        class="inline-flex h-7 items-center gap-1 rounded-lg bg-emerald-50 text-emerald-700 px-2 text-[11px] font-bold hover:bg-emerald-100"
                                        title="Assign to employee">
                                    <span class="material-symbols-outlined text-[14px]">person_add</span>
                                    Assign
                                </button>

                                <!-- Un-assign / return (only when currently assigned) -->
                                <button v-if="a.current_status === 'assigned' && a.current_assignment?.id && ($page.props.auth.permissions?.includes('assets.manage') || $page.props.auth.permissions?.includes('assets.assign'))"
                                        @click="unassignAsset(a)" type="button"
                                        class="inline-flex h-7 items-center gap-1 rounded-lg bg-amber-50 text-amber-700 px-2 text-[11px] font-bold hover:bg-amber-100"
                                        title="Return / un-assign">
                                    <span class="material-symbols-outlined text-[14px]">assignment_return</span>
                                    Return
                                </button>

                                <!-- Retire (lifecycle-end, requires assets.manage) -->
                                <button v-if="!['retired','lost'].includes(a.current_status) && $page.props.auth.permissions?.includes('assets.manage')"
                                        @click="retireAsset(a)" type="button"
                                        class="inline-flex h-7 items-center gap-1 rounded-lg bg-slate-50 text-slate-700 px-2 text-[11px] font-bold hover:bg-slate-100"
                                        title="Retire (end-of-life)">
                                    <span class="material-symbols-outlined text-[14px]">archive</span>
                                    Retire
                                </button>

                                <!-- Mark lost (requires assets.manage) -->
                                <button v-if="!['retired','lost'].includes(a.current_status) && $page.props.auth.permissions?.includes('assets.manage')"
                                        @click="markAssetLost(a)" type="button"
                                        class="inline-flex h-7 items-center gap-1 rounded-lg bg-rose-50 text-rose-700 px-2 text-[11px] font-bold hover:bg-rose-100"
                                        title="Mark lost (loss investigation)">
                                    <span class="material-symbols-outlined text-[14px]">report</span>
                                    Lost
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="No assets registered yet." />
            <Pagination v-if="props.assets.meta?.last_page > 1" :links="props.assets.meta.links" class="p-4" />
        </section>
    </div>

    <SlidePanel :open="showCreate" @close="showCreate = false" title="Register Asset" size="lg">
        <form @submit.prevent="createAsset" class="space-y-3 p-4">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Asset Tag</label><input v-model="newAsset.asset_tag" aria-label="Asset tag" maxlength="40" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 font-mono uppercase mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Name</label><input v-model="newAsset.name" aria-label="Asset name" maxlength="120" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Category</label><select v-model="newAsset.category" aria-label="Asset category" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option v-for="(label, key) in categoryLabel" :key="key" :value="key">{{ label }}</option></select></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Serial Number</label><input v-model="newAsset.serial_number" aria-label="Serial number" maxlength="80" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Brand</label><input v-model="newAsset.brand" aria-label="Brand" maxlength="80" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Model</label><input v-model="newAsset.model" aria-label="Model" maxlength="80" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Purchase Date</label><input v-model="newAsset.purchase_date" aria-label="Purchase date" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Purchase Cost ({{ newAsset.currency }})</label><input v-model.number="newAsset.purchase_cost" aria-label="Purchase cost" type="number" step="0.01" min="0" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Supplier</label><input v-model="newAsset.supplier" aria-label="Supplier" maxlength="120" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Warranty Expires</label><input v-model="newAsset.warranty_expires_at" aria-label="Warranty expiry date" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            </div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Location</label><input v-model="newAsset.location" aria-label="Asset location" maxlength="120" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Notes</label><textarea v-model="newAsset.notes" aria-label="Notes" rows="2" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
            <button type="submit" :disabled="newAsset.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Register Asset</button>
        </form>
    </SlidePanel>

    <SlidePanel :open="showAssign" @close="showAssign = false" :title="`Assign ${assignTarget?.asset_tag ?? ''}`">
        <form @submit.prevent="submitAssign" class="space-y-3 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Employee</label><select v-model="assignForm.employee_id" aria-label="Assignee employee" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option value="" disabled>Select…</option><option v-for="e in props.employees" :key="e.id" :value="e.id">{{ e.employee_no }} — {{ e.position }}</option></select></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Due Back (optional)</label><input v-model="assignForm.due_back_at" aria-label="Due-back date" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Notes</label><textarea v-model="assignForm.notes" aria-label="Assignment notes" rows="2" maxlength="500" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
            <button type="submit" :disabled="assignForm.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Assign</button>
        </form>
    </SlidePanel>
</AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useToast } from '@/composables/useToast';
import EmptyState from '@/Components/EmptyState.vue';

defineProps({
    activeModule: { type: String, default: 'assets' },
});

const { comingSoon } = useToast();

const stats = [
    { label: 'Total Assets', val: '4,120', sub: 'institutional',  rgb: '0,81,213',   icon: 'inventory_2' },
    { label: 'Assigned',     val: '3,842', sub: 'in-use',         rgb: '5,150,105',  icon: 'check_circle' },
    { label: 'Maintenance',  val: '12',    sub: 'pending repair', rgb: '217,119,6',  icon: 'build' },
    { label: 'Available',    val: '266',   sub: 'in stock',       rgb: '124,92,255', icon: 'archive' },
];

const inventory = [
    { id: 'AST-2026-001', cat: 'Workstation',  desc: 'MacBook Pro 16" M3 Max',  status: 'assigned',    user: 'Akua Mensah',     since: '2025-03-12' },
    { id: 'AST-2026-002', cat: 'Mobile',       desc: 'iPhone 16 Pro',            status: 'assigned',    user: 'Kofi Asante',     since: '2025-08-04' },
    { id: 'AST-2026-042', cat: 'Mobile',       desc: 'iPad Pro 12.9"',           status: 'maintenance', user: 'Esi Darko',       since: '2025-01-18' },
    { id: 'AST-2026-088', cat: 'Workstation',  desc: 'Dell XPS 15',              status: 'available',   user: '—',                since: '—' },
    { id: 'AST-2026-099', cat: 'Audio',        desc: 'AirPods Pro 2',            status: 'assigned',    user: 'Yaw Boateng',     since: '2025-11-02' },
    { id: 'AST-2026-150', cat: 'Display',      desc: 'LG 32" UltraFine 4K',      status: 'assigned',    user: 'Ama Owusu',       since: '2025-06-21' },
    { id: 'AST-2026-201', cat: 'Vehicle',      desc: 'Toyota Hilux GR-2456-25',  status: 'maintenance', user: 'Fleet Pool',      since: '—' },
];

const filterStatus = ref('');
const search = ref('');

const filtered = computed(() =>
    inventory.filter(a => {
        if (filterStatus.value && a.status !== filterStatus.value) return false;
        if (search.value) {
            const q = search.value.toLowerCase();
            return (a.id + a.desc + a.user + a.cat).toLowerCase().includes(q);
        }
        return true;
    })
);

const STATUS_META = {
    assigned:    { color: '#059669', bg: 'bg-green-50  dark:bg-green-900/20',  text: 'text-green-700  dark:text-green-400'  },
    maintenance: { color: '#d97706', bg: 'bg-amber-50  dark:bg-amber-900/20',  text: 'text-amber-700  dark:text-amber-400'  },
    available:   { color: '#0051d5', bg: 'bg-blue-50   dark:bg-blue-900/20',   text: 'text-blue-700   dark:text-blue-400'   },
};
</script>

<template>
    <Head title="Asset Management — CIHRMS" />

    <AuthenticatedLayout :activeModule="activeModule">

        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-[22px] font-black tracking-tight text-on-surface">Asset Management</h1>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">Inventory of institutional equipment, fleet, and licences.</p>
            </div>
            <button @click="comingSoon('Asset registry editor')" type="button"
                    class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow hover:-translate-y-px active:scale-[0.97] transition-all"
                    style="background:linear-gradient(135deg,#0051d5,#316bf3)">
                <span class="material-symbols-outlined text-[17px]">add_circle</span>
                Add Asset
            </button>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div v-for="s in stats" :key="s.label"
                class="card-lift relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
                <div class="flex items-start justify-between">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl"
                         :style="`background:rgba(${s.rgb},0.12);border:1px solid rgba(${s.rgb},0.2)`">
                        <span class="material-symbols-outlined text-[18px]"
                              :style="`color:rgb(${s.rgb});font-variation-settings:'FILL' 1`">{{ s.icon }}</span>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/40">{{ s.sub }}</span>
                </div>
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">{{ s.label }}</p>
                <p class="mt-0.5 text-[26px] font-black tracking-tight text-on-surface">{{ s.val }}</p>
            </div>
        </div>

        <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-outline-variant/40 px-4 py-3">
                <input v-model="search"
                       placeholder="Search assets, IDs, owners…"
                       class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2 text-[13px] focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 flex-1 min-w-[220px] max-w-md" />
                <select v-model="filterStatus" class="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] font-semibold text-on-surface-variant focus:outline-none focus:border-secondary/50">
                    <option value="">All statuses</option>
                    <option value="assigned">Assigned</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="available">Available</option>
                </select>
                <button v-if="search || filterStatus" @click="search = ''; filterStatus = ''" type="button"
                        class="text-[12px] font-bold text-on-surface-variant hover:text-secondary">Clear</button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="bg-surface-container-low border-b border-outline-variant/40">
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Asset ID</th>
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Description</th>
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Category</th>
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60">Status</th>
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60 hidden md:table-cell">Assigned To</th>
                            <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-wider text-on-surface-variant/60 hidden lg:table-cell">Since</th>
                            <th class="px-5 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in filtered" :key="a.id" class="border-b border-outline-variant/25 hover:bg-surface-container/40 transition-colors">
                            <td class="px-5 py-3.5 font-mono text-[12px] font-bold text-on-surface-variant">{{ a.id }}</td>
                            <td class="px-5 py-3.5 font-semibold text-on-surface">{{ a.desc }}</td>
                            <td class="px-5 py-3.5 text-on-surface-variant/80">{{ a.cat }}</td>
                            <td class="px-5 py-3.5">
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                      :class="`${STATUS_META[a.status].bg} ${STATUS_META[a.status].text}`">
                                    {{ a.status }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 hidden md:table-cell text-on-surface-variant">{{ a.user }}</td>
                            <td class="px-5 py-3.5 hidden lg:table-cell font-mono text-[11.5px] text-on-surface-variant/70">{{ a.since }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <button @click="comingSoon('Asset history viewer')" type="button"
                                        class="rounded-lg bg-surface-container px-2.5 py-1 text-[11px] font-bold text-on-surface-variant hover:bg-surface-container-high">
                                    <span class="material-symbols-outlined text-[13px] align-middle">history</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <EmptyState v-if="!filtered.length"
                            title="No assets match"
                            description="Try clearing filters or search."
                            icon="inventory_2" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>

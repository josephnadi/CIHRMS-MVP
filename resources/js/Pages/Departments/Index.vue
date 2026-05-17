<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    departments:  Object,
    activeModule: String,
});

const list = computed(() => props.departments?.data ?? []);

// ── Create + edit share one slide-panel via an `editing` mode flag ──
//   editing.value === null → create flow (POST /departments)
//   editing.value !== null → edit flow   (PATCH /departments/{id})
const showPanel = ref(false);
const editing   = ref(null);

const form = useForm({
    name:        '',
    code:        '',
    description: '',
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showPanel.value = true;
};

const openEdit = (dept) => {
    editing.value = dept;
    form.name        = dept.name        ?? '';
    form.code        = dept.code        ?? '';
    form.description = dept.description ?? '';
    form.clearErrors();
    showPanel.value = true;
};

const submit = () => {
    if (editing.value) {
        form.patch(route('departments.update', editing.value.id), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); showPanel.value = false; editing.value = null; },
        });
    } else {
        form.post(route('departments.store'), {
            onSuccess: () => { form.reset(); showPanel.value = false; },
        });
    }
};

const confirmDelete = (dept) => {
    const headcount = dept.active_employee_count ?? 0;
    const msg = headcount > 0
        ? `${dept.name} has ${headcount} active employee(s). Re-assign them first; the deletion will be refused otherwise.\n\nContinue anyway?`
        : `Delete department "${dept.name}"? This cannot be undone from the UI.`;
    if (! window.confirm(msg)) return;
    router.delete(route('departments.destroy', dept.id), { preserveScroll: true });
};

const totalActive = computed(() => list.value.reduce((sum, d) => sum + (d.active_employee_count ?? 0), 0));

// ── Editorial-Sovereign masthead ──────────────────────────────────
// Volume = year offset from CIHRM-GH platform inception (2023).
// Issue  = day-of-year. Mirrors Dashboard.vue masthead convention.
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

// Department card gradient pool — disciplined cool family (matches Employees avatar pool)
const gradients = [
    'linear-gradient(135deg,#0d1452,#1a237e)',
    'linear-gradient(135deg,#1a237e,#7986cb)',
    'linear-gradient(135deg,#070b3a,#0d1452)',
    'linear-gradient(135deg,#1a237e,#3949ab)',
    'linear-gradient(135deg,#0d1452,#1a237e,#d912e3)',
    'linear-gradient(135deg,#1a237e,#12d9e3)',
];
const cardGradient = (id) => gradients[id % gradients.length];

const goToEmployees = (deptId) => {
    router.get(route('employees.index'), { department_id: deptId });
};
</script>

<template>
    <Head title="Departments" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="space-y-8">
                <!-- ─── Masthead strip ────────────────────────────────────── -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">DEPARTMENTAL STRUCTURE</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Live · Establishment
                    </span>
                </div>

                <!-- ─── Broadsheet hero ───────────────────────────────────── -->
                <div class="es-broadsheet rounded-none">
                    <!-- LEAD column -->
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Establishment · Departmental ledger</p>
                        <h2 class="es-display text-[clamp(2.4rem,5.5vw,4.6rem)]">
                            The departmental
                            <span class="es-display-italic block">register.</span>
                        </h2>
                        <p class="es-display-sub">
                            Institutional organogram of record — the units through which the Institute discharges its mandate,
                            with headcount distribution observed across the establishment.
                        </p>

                        <!-- Typographic quick-actions, not gradient buttons -->
                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <button @click="openCreate" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]" style="font-variation-settings:'FILL' 1">corporate_fare</span>
                                Add Department
                            </button>
                            <span class="text-on-surface-variant/30">·</span>
                            <Link :href="route('employees.index')" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">groups</span>
                                Employee directory
                            </Link>
                        </div>
                    </div>

                    <!-- SIDEBAR column: feature KPI as magazine drop-cap stat -->
                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Departments on register</p>
                            <p class="es-stat-hero-value">{{ list.length.toLocaleString() }}</p>
                            <p class="es-stat-hero-caption">
                                Operating unit{{ list.length === 1 ? '' : 's' }} of record · establishment chart
                            </p>
                            <span class="es-stat-hero-delta">
                                <span class="material-symbols-outlined text-[13px]">account_tree</span>
                                Institutional organogram
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- ─── Supporting metrics strip (broadsheet sub-numbers) ── -->
            <div class="es-stat-strip rounded-none">
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">Total Departments</p>
                    <p class="es-stat-cell-value">{{ list.length.toLocaleString() }}</p>
                    <p class="es-stat-cell-caption">Units on the establishment</p>
                </div>
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">Active Staff</p>
                    <p class="es-stat-cell-value">{{ totalActive.toLocaleString() }}</p>
                    <p class="es-stat-cell-caption">Distributed across the register</p>
                </div>
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">Avg. Headcount</p>
                    <p class="es-stat-cell-value">{{ list.length > 0 ? Math.round(totalActive / list.length).toLocaleString() : 0 }}</p>
                    <p class="es-stat-cell-caption">Mean staff per unit</p>
                </div>
            </div>

            <!-- Department cards -->
            <div v-if="list.length === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                <EmptyState
                    title="No departments yet"
                    description="Create your first department to start organizing employees."
                    icon="corporate_fare"
                >
                    <template #action>
                        <button
                            @click="openCreate"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            Add Department
                        </button>
                    </template>
                </EmptyState>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="dept in list"
                    :key="dept.id"
                    class="group relative rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card hover:shadow-lifted hover:-translate-y-0.5 transition-all overflow-hidden"
                >
                    <!-- Per-row actions (visible on hover, keyboard-focusable). Stop
                         propagation so they don't trigger the card-level "View" click. -->
                    <div class="absolute top-3 right-3 z-10 flex items-center gap-1 opacity-0 focus-within:opacity-100 group-hover:opacity-100 transition-opacity">
                        <button
                            type="button"
                            @click.stop="openEdit(dept)"
                            aria-label="Edit department"
                            class="flex h-8 w-8 items-center justify-center rounded-lg bg-surface-container-lowest/80 backdrop-blur border border-outline-variant/60 text-on-surface-variant hover:text-secondary hover:border-secondary/40 hover:bg-secondary/5 transition-all shadow-card"
                        >
                            <span class="material-symbols-outlined text-[16px]">edit</span>
                        </button>
                        <button
                            type="button"
                            @click.stop="confirmDelete(dept)"
                            aria-label="Delete department"
                            class="flex h-8 w-8 items-center justify-center rounded-lg bg-surface-container-lowest/80 backdrop-blur border border-outline-variant/60 text-on-surface-variant hover:text-rose-600 hover:border-rose-300 hover:bg-rose-50 transition-all shadow-card"
                        >
                            <span class="material-symbols-outlined text-[16px]">delete</span>
                        </button>
                    </div>

                    <!-- Main card body — clickable to navigate to employees in this dept -->
                    <button
                        type="button"
                        @click="goToEmployees(dept.id)"
                        class="text-left w-full"
                        :aria-label="`View employees in ${dept.name}`"
                    >
                        <div class="h-1.5 w-full" :style="`background:${cardGradient(dept.id)}`"></div>
                        <div class="p-5">
                            <div class="flex items-start justify-between mb-3">
                                <div
                                    class="flex h-11 w-11 items-center justify-center rounded-xl"
                                    :style="`background:${cardGradient(dept.id)};color:#fff`"
                                >
                                    <span class="material-symbols-outlined text-[22px]">corporate_fare</span>
                                </div>
                                <span class="font-mono text-[11px] font-bold text-on-surface-variant/60 mr-20">{{ dept.code }}</span>
                            </div>

                            <h3 class="text-[15px] font-bold text-on-surface leading-snug mb-1 group-hover:text-secondary transition-colors">
                                {{ dept.name }}
                            </h3>
                            <p v-if="dept.description" class="text-[12px] text-on-surface-variant line-clamp-2 mb-3 leading-relaxed">
                                {{ dept.description }}
                            </p>

                            <div class="flex items-center gap-2 pt-3 border-t border-outline-variant/40">
                                <span class="material-symbols-outlined text-[16px] text-on-surface-variant/60">people</span>
                                <span class="text-[12px] font-bold text-on-surface">{{ dept.active_employee_count ?? 0 }}</span>
                                <span class="text-[11px] text-on-surface-variant/60">active staff</span>
                                <span class="ml-auto text-[11px] font-semibold text-secondary opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-0.5">
                                    View
                                    <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                </span>
                            </div>
                        </div>
                    </button>
                </div>
            </div>
        </div>

        <!-- Create / Edit Department (shared panel, mode determined by `editing`) -->
        <SlidePanel
            :open="showPanel"
            :title="editing ? `Edit ${editing.name}` : 'Add Department'"
            size="md"
            @close="showPanel = false; editing = null"
        >
            <form @submit.prevent="submit" class="space-y-5 p-6">
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Department Name <span class="text-red-500">*</span></label>
                    <input
                        v-model="form.name"
                        type="text"
                        placeholder="e.g. Information Technology"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="mt-1 text-[11px] text-red-500">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Department Code <span class="text-red-500">*</span>
                        <span class="ml-1 font-normal text-on-surface-variant/60">(2â€“10 chars)</span>
                    </label>
                    <input
                        v-model="form.code"
                        type="text"
                        placeholder="e.g. IT"
                        maxlength="10"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all uppercase"
                        :class="{ 'border-red-400': form.errors.code }"
                    />
                    <p v-if="form.errors.code" class="mt-1 text-[11px] text-red-500">{{ form.errors.code }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Description</label>
                    <textarea
                        v-model="form.description"
                        rows="3"
                        placeholder="Brief description of this department's purposeâ€¦"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                    />
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showPanel = false; editing = null"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submit"
                        :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                    >
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span>{{ editing ? 'Update Department' : 'Save Department' }}</span>
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>

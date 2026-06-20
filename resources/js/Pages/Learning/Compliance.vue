<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    requirements:  { type: Array, default: () => [] },
    overduePeople: { type: Array, default: () => [] },
    courses:       { type: Array, default: () => [] },
    activeModule:  String,
});

const form = useForm({
    course_id:    '',
    name:         '',
    target_type:  'all_staff',
    target_value: '',
    due_in_days:  30,
});

const totalOverdue = computed(() => props.requirements.reduce((sum, r) => sum + (r.overdue ?? 0), 0));
const totalAssigned = computed(() => props.requirements.reduce((sum, r) => sum + (r.assigned ?? 0), 0));

const submit = () => {
    form.transform((data) => ({
        ...data,
        target_value: data.target_type === 'all_staff' ? null : data.target_value,
    })).post(route('learning.compliance.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Compliance" />
    <div data-page-root="true">
        <!-- ── Header ─────────────────────────────────────────────────────── -->
        <Teleport to="#page-header-mount" defer>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70 mb-1">
                        <span>Learning</span>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>Compliance</span>
                    </div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Compliance</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Mandatory training requirements, assignment coverage, and overdue staff.
                    </p>
                </div>
            </div>
        </Teleport>

        <div class="space-y-6 animate-reveal-up">

            <!-- ── Stat cards ─────────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div
                    v-for="(s, i) in [
                        { label: 'Requirements', value: requirements.length, icon: 'gavel',     color: '26, 35, 126' },
                        { label: 'Assignments',  value: totalAssigned,        icon: 'how_to_reg', color: '5,150,105' },
                        { label: 'Overdue',      value: totalOverdue,         icon: 'warning',    color: '220,38,38' },
                        { label: 'Courses',      value: courses.length,       icon: 'menu_book',  color: '124,92,255' },
                    ]"
                    :key="s.label"
                    class="rounded-2xl border bg-surface-container-lowest p-5 shadow-card card-lift"
                    :style="`border-color:rgba(${s.color},0.25);animation-delay:${i * 0.06}s`"
                >
                    <div class="h-10 w-10 rounded-xl flex items-center justify-center" :style="`background:rgba(${s.color},0.12)`">
                        <span class="material-symbols-outlined text-[20px]" :style="`color:rgb(${s.color})`" style="font-variation-settings:'FILL' 1">{{ s.icon }}</span>
                    </div>
                    <p class="mt-4 text-[28px] font-black text-on-surface tracking-tight leading-none">{{ s.value }}</p>
                    <p class="mt-1.5 text-[12px] font-semibold text-on-surface-variant">{{ s.label }}</p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">

                <!-- ── Requirements table ─────────────────────────────────── -->
                <section class="lg:col-span-2">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Requirements</h3>
                        <span class="rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">{{ requirements.length }}</span>
                    </div>

                    <div v-if="requirements.length" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-[12px]">
                                <thead>
                                    <tr class="border-b border-outline-variant/40 text-[10px] font-black uppercase tracking-[0.08em] text-on-surface-variant/60">
                                        <th class="px-4 py-3">Requirement</th>
                                        <th class="px-4 py-3">Course</th>
                                        <th class="px-4 py-3">Target</th>
                                        <th class="px-4 py-3 text-center">Due (days)</th>
                                        <th class="px-4 py-3 text-center">Assigned</th>
                                        <th class="px-4 py-3 text-center">Completed</th>
                                        <th class="px-4 py-3 text-center">Overdue</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-outline-variant/30">
                                    <tr v-for="r in requirements" :key="r.id" class="hover:bg-surface-container/40 transition-colors">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-2">
                                                <span class="font-bold text-on-surface">{{ r.name }}</span>
                                                <span
                                                    v-if="!r.is_active"
                                                    class="rounded-full bg-outline-variant/20 px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.08em] text-on-surface-variant/60"
                                                >Inactive</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-on-surface-variant">{{ r.course ?? '—' }}</td>
                                        <td class="px-4 py-3">
                                            <span class="rounded-full bg-secondary/10 px-2 py-0.5 text-[10px] font-bold text-secondary">{{ r.target }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-center font-mono text-on-surface-variant">{{ r.due_in_days }}</td>
                                        <td class="px-4 py-3 text-center font-bold text-on-surface">{{ r.assigned }}</td>
                                        <td class="px-4 py-3 text-center font-bold text-emerald-600">{{ r.completed }}</td>
                                        <td class="px-4 py-3 text-center">
                                            <span
                                                class="inline-flex items-center justify-center rounded-full px-2 py-0.5 text-[11px] font-black"
                                                :class="r.overdue > 0 ? 'bg-rose-500/10 text-rose-600' : 'bg-emerald-500/10 text-emerald-700'"
                                            >{{ r.overdue }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-else class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-10">
                        <EmptyState
                            title="No requirements yet"
                            description="Create a mandatory-training requirement to auto-assign a course to matching staff."
                            icon="gavel"
                        />
                    </div>

                    <!-- ── Overdue people panel ───────────────────────────── -->
                    <div class="mt-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">Overdue Staff</h3>
                            <span class="rounded-full bg-rose-500/10 px-2.5 py-0.5 text-[11px] font-bold text-rose-600">{{ overduePeople.length }}</span>
                        </div>

                        <div v-if="overduePeople.length" class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest overflow-hidden">
                            <div class="divide-y divide-outline-variant/30">
                                <div
                                    v-for="(p, i) in overduePeople"
                                    :key="i"
                                    class="flex items-center gap-4 px-5 py-3"
                                >
                                    <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-rose-500/10">
                                        <span class="material-symbols-outlined text-[18px] text-rose-600" style="font-variation-settings:'FILL' 1">warning</span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-[13px] font-bold text-on-surface truncate">{{ p.employee ?? '—' }}</h4>
                                        <p class="text-[11px] text-on-surface-variant/60 truncate">{{ p.course ?? '—' }}</p>
                                    </div>
                                    <span class="rounded-full bg-rose-500/10 px-2.5 py-1 text-[10.5px] font-bold text-rose-600 flex-shrink-0">
                                        Due {{ p.due_at ?? '—' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div v-else class="rounded-2xl border border-outline-variant/40 bg-surface-container/40 border-dashed p-6 text-center">
                            <span class="material-symbols-outlined text-[28px] text-emerald-500/60" style="font-variation-settings:'FILL' 1">task_alt</span>
                            <p class="mt-2 text-[13px] font-semibold text-on-surface-variant">No overdue mandatory training</p>
                        </div>
                    </div>
                </section>

                <!-- ── New requirement form ───────────────────────────────── -->
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70">New Requirement</h3>
                    </div>

                    <form
                        @submit.prevent="submit"
                        class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 space-y-4"
                    >
                        <!-- Course -->
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Course <span class="text-red-500">*</span></label>
                            <select aria-label="Course"
                                v-model="form.course_id"
                                required
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.course_id }"
                            >
                                <option value="" disabled>Select a course…</option>
                                <option v-for="c in courses" :key="c.id" :value="c.id">{{ c.title }}</option>
                            </select>
                            <p v-if="form.errors.course_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.course_id }}</p>
                        </div>

                        <!-- Name -->
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Requirement Name <span class="text-red-500">*</span></label>
                            <input aria-label="Requirement Name"
                                v-model="form.name"
                                type="text"
                                required
                                maxlength="160"
                                placeholder="e.g. Annual Data Protection"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.name }"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-[11px] text-red-500">{{ form.errors.name }}</p>
                        </div>

                        <!-- Target type -->
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Target</label>
                            <select aria-label="Target type"
                                v-model="form.target_type"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            >
                                <option value="all_staff">All staff</option>
                                <option value="role">Role</option>
                                <option value="department">Department</option>
                            </select>
                        </div>

                        <!-- Conditional target value -->
                        <div v-if="form.target_type !== 'all_staff'">
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                                {{ form.target_type === 'role' ? 'Role slug' : 'Department ID' }}
                            </label>
                            <input :aria-label="form.target_type === 'role' ? 'Role slug' : 'Department ID'"
                                v-model="form.target_value"
                                type="text"
                                maxlength="64"
                                :placeholder="form.target_type === 'role' ? 'e.g. hr_admin' : 'e.g. 3'"
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.target_value }"
                            />
                            <p v-if="form.errors.target_value" class="mt-1 text-[11px] text-red-500">{{ form.errors.target_value }}</p>
                        </div>

                        <!-- Due in days -->
                        <div>
                            <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Due within (days) <span class="text-red-500">*</span></label>
                            <input aria-label="Due in days"
                                v-model.number="form.due_in_days"
                                type="number"
                                min="1"
                                max="365"
                                required
                                class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                :class="{ 'border-red-400': form.errors.due_in_days }"
                            />
                            <p v-if="form.errors.due_in_days" class="mt-1 text-[11px] text-red-500">{{ form.errors.due_in_days }}</p>
                        </div>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="btn-shimmer w-full flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px active:scale-[0.97] disabled:opacity-60"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)"
                        >
                            <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            <span v-else class="material-symbols-outlined text-[16px]">add</span>
                            Create &amp; Assign
                        </button>
                        <p class="text-[11px] text-on-surface-variant/60">
                            Saving auto-enrols matching active staff with a due date. Re-running never duplicates.
                        </p>
                    </form>
                </section>

            </div>
        </div>
    </div>
</template>

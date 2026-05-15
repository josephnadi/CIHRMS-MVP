<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    matrix:       Object,
    activeModule: String,
});

const employees = computed(() => props.matrix?.employees ?? []);
const skills    = computed(() => props.matrix?.skills    ?? []);
const matrix    = computed(() => props.matrix?.matrix    ?? {});

const search = ref('');
const filteredEmployees = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return employees.value;
    return employees.value.filter(e =>
        (e.name ?? '').toLowerCase().includes(q)
        || (e.position ?? '').toLowerCase().includes(q)
        || (e.department ?? '').toLowerCase().includes(q));
});

const showOnlyTopSkills = ref(true);
const visibleSkills = computed(() => showOnlyTopSkills.value ? skills.value.slice(0, 12) : skills.value);

// Coverage = employees holding at least one skill / total employees
const coveragePct = computed(() => {
    if (!employees.value.length) return 0;
    const covered = employees.value.filter(e => e.skill_count > 0).length;
    return Math.round((covered / employees.value.length) * 100);
});

const levelCell = (level) => {
    const map = {
        beginner:     { bg: 'rgba(217,119,6,0.20)',   fg: '#92400e',   abbr: 'B' },
        intermediate: { bg: 'rgba(0,81,213,0.20)',    fg: '#1e3a8a',   abbr: 'I' },
        advanced:     { bg: 'rgba(124,58,237,0.20)',  fg: '#5b21b6',   abbr: 'A' },
        expert:       { bg: 'rgba(5,150,105,0.25)',   fg: '#064e3b',   abbr: 'E' },
    };
    return map[level] ?? null;
};
</script>

<template>
    <Head title="Skills Matrix" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70 mb-1">
                        <Link :href="route('learning.catalog')" class="hover:text-secondary">Learning</Link>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>Skills Matrix</span>
                    </div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Skills Matrix</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Workforce skill coverage. Cells show level: <strong>B</strong>eginner / <strong>I</strong>ntermediate / <strong>A</strong>dvanced / <strong>E</strong>xpert.
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Stats -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">Employees tracked</p>
                    <p class="text-[22px] font-black text-on-surface leading-none mt-1">{{ employees.length }}</p>
                </div>
                <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">Distinct skills</p>
                    <p class="text-[22px] font-black text-on-surface leading-none mt-1">{{ skills.length }}</p>
                </div>
                <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">Skill coverage</p>
                    <p class="text-[22px] font-black text-on-surface leading-none mt-1">{{ coveragePct }}%</p>
                </div>
                <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">Top skill</p>
                    <p class="text-[16px] font-black text-on-surface leading-tight mt-1 truncate">{{ skills[0]?.name ?? '—' }}</p>
                    <p v-if="skills[0]" class="text-[11px] text-on-surface-variant">{{ skills[0].count }} employee{{ skills[0].count === 1 ? '' : 's' }}</p>
                </div>
            </div>

            <!-- Controls -->
            <div class="flex flex-wrap items-center gap-3">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Filter employees by name, role, department…"
                    class="rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12.5px] flex-1 min-w-[240px] max-w-md"
                />
                <label class="flex items-center gap-2 text-[12px] text-on-surface-variant cursor-pointer">
                    <input type="checkbox" v-model="showOnlyTopSkills" class="h-4 w-4 rounded border-outline-variant" />
                    Show only top 12 skills
                </label>
            </div>

            <!-- Matrix -->
            <div v-if="employees.length && skills.length" class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-[12px]">
                        <thead class="bg-surface-container-low/60 sticky top-0">
                            <tr>
                                <th class="text-left px-4 py-3 font-bold text-[11px] uppercase tracking-[0.10em] text-on-surface-variant/70 sticky left-0 bg-surface-container-low/60 min-w-[220px]">
                                    Employee
                                </th>
                                <th v-for="s in visibleSkills" :key="s.name"
                                    class="px-2 py-3 text-center font-bold text-[10px] uppercase tracking-[0.08em] text-on-surface-variant/70 whitespace-nowrap">
                                    <div class="rotate-[-30deg] origin-bottom-left inline-block">{{ s.name }}</div>
                                    <div class="text-[9px] text-on-surface-variant/50 mt-0.5 normal-case">{{ s.count }}</div>
                                </th>
                                <th class="px-3 py-3 text-right font-bold text-[10px] uppercase tracking-[0.08em] text-on-surface-variant/70">#</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="emp in filteredEmployees" :key="emp.id" class="hover:bg-surface-container-low/40">
                                <td class="px-4 py-2 sticky left-0 bg-surface-container-lowest min-w-[220px]">
                                    <div class="font-bold text-on-surface text-[12.5px] leading-tight">{{ emp.name }}</div>
                                    <div class="text-[10.5px] text-on-surface-variant/70 truncate">{{ emp.position }} · {{ emp.department ?? '—' }}</div>
                                </td>
                                <td v-for="s in visibleSkills" :key="s.name" class="px-2 py-2 text-center">
                                    <span
                                        v-if="matrix[emp.id]?.[s.name]"
                                        class="inline-flex h-6 w-6 items-center justify-center rounded-md text-[11px] font-black"
                                        :style="`background:${levelCell(matrix[emp.id][s.name])?.bg};color:${levelCell(matrix[emp.id][s.name])?.fg}`"
                                        :title="matrix[emp.id][s.name]"
                                    >{{ levelCell(matrix[emp.id][s.name])?.abbr }}</span>
                                    <span v-else class="text-on-surface-variant/20">·</span>
                                </td>
                                <td class="px-3 py-2 text-right font-bold text-on-surface-variant">{{ emp.skill_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-else class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-10 text-center">
                <span class="material-symbols-outlined text-[42px] text-on-surface-variant/30">grid_on</span>
                <p class="mt-2 text-[14px] font-semibold text-on-surface">No skills tracked yet.</p>
                <p class="text-[12px] text-on-surface-variant/70">Skills are populated when employees record them on their profile or complete tagged courses.</p>
            </div>

            <!-- Legend -->
            <div class="flex flex-wrap items-center gap-2 text-[11px] text-on-surface-variant">
                <span class="font-bold uppercase tracking-[0.10em]">Legend:</span>
                <span v-for="(label, level) in { beginner: 'Beginner', intermediate: 'Intermediate', advanced: 'Advanced', expert: 'Expert' }" :key="level"
                      class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5"
                      :style="`background:${levelCell(level).bg};color:${levelCell(level).fg}`">
                    <span class="font-black">{{ levelCell(level).abbr }}</span>
                    {{ label }}
                </span>
            </div>

        </div>
    </AuthenticatedLayout>
</template>

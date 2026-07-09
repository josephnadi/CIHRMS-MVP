<script setup>
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Sparkline from '@/Components/charts/Sparkline.vue';
import GlossaryText from '@/Components/GlossaryText.vue';

const props = defineProps({
    spark:     { type: Object,  required: true },    // deptSparkData.hr slice
    employees: { type: Array,   default: () => [] },
    stats:     { type: Object,  default: () => ({}) },
});

const editionDate = computed(() => new Date().toLocaleDateString('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
}));
const headcountNow = computed(() => Math.round(props.spark.headcount[props.spark.headcount.length - 1]));
const turnoverNow = computed(() => props.spark.turnover[props.spark.turnover.length - 1].toFixed(1));
const openPositionsNow = computed(() => Math.round(props.spark.openPositions[props.spark.openPositions.length - 1]));
const trainingNow = computed(() => props.spark.training[props.spark.training.length - 1].toFixed(0));
</script>

<template>
    <div class="space-y-8 animate-reveal-up">

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">people</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">PEOPLE DESK</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">People &amp; Workforce</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    Talent acquisition, retention, and compliance — a daily ledger of the people who make the institute.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="router.visit(route('employees.index', { new: 1 }))"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                    <span class="material-symbols-outlined text-[17px]">person_add</span>
                    Add Employee
                </button>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="grid gap-6 lg:grid-cols-12">

            <div class="lg:col-span-8 space-y-6">

                <!-- Recruitment Pipeline -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                        <h3 class="text-[15px] font-black text-primary">Recruitment Pipeline</h3>
                        <button @click="router.visit(route('jobs.index', { new: 1 }))" class="btn-shimmer flex items-center gap-1.5 rounded-xl px-4 py-2 text-[12px] font-black text-white" style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                            <span class="material-symbols-outlined text-[15px]">add</span> Post Job
                        </button>
                    </div>
                    <div class="p-6 space-y-4">
                        <div v-for="stage in [
                            { name: 'Applications Received', count: 245, total: 245, color: 'bg-blue-500',  pct: 100 },
                            { name: 'Shortlisted',           count: 82,  total: 245, color: 'bg-secondary', pct: 33  },
                            { name: 'First Interview',       count: 34,  total: 245, color: 'bg-blue-500',  pct: 14  },
                            { name: 'Second Interview',      count: 12,  total: 245, color: 'bg-amber-500', pct: 5   },
                            { name: 'Offer Extended',        count: 4,   total: 245, color: 'bg-green-500', pct: 1.6 },
                        ]" :key="stage.name"
                             class="flex items-center gap-4">
                            <span class="w-44 text-[12px] font-bold text-on-surface-variant flex-shrink-0">{{ stage.name }}</span>
                            <div class="flex-1 h-6 rounded-full bg-surface-container-low overflow-hidden relative">
                                <div class="h-full rounded-full transition-all duration-700 flex items-center justify-end pr-3" :class="stage.color" :style="`width:${stage.pct}%`">
                                    <span class="text-[9px] font-black text-white">{{ stage.count }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Hires Table -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden flex flex-col">
                    <div class="px-6 py-4 border-b border-outline-variant/50 flex-shrink-0">
                        <h3 class="text-[15px] font-black text-primary">Recent Hires</h3>
                    </div>
                    <div class="canvas-scroll max-h-[340px] overflow-auto">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10 bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                            <tr>
                                <th class="px-6 py-3">Employee</th>
                                <th class="px-6 py-3">Department</th>
                                <th class="px-6 py-3">Start Date</th>
                                <th class="px-6 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/40">
                            <tr v-for="emp in employees" :key="emp.id" class="hover:bg-surface-container-low/30 transition-colors">
                                <td class="px-6 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="h-8 w-8 rounded-full bg-secondary/10 flex items-center justify-center text-[11px] font-black text-secondary flex-shrink-0">{{ (emp.employee_no || 'E').charAt(0) }}</div>
                                        <div>
                                            <p class="text-[12.5px] font-bold text-primary">{{ emp.user?.name || emp.name || 'New Employee' }}</p>
                                            <p class="text-[10px] text-on-surface-variant">{{ emp.position }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3.5 text-[12.5px] font-bold text-on-surface-variant">{{ emp.department?.name || 'General' }}</td>
                                <td class="px-6 py-3.5 text-[12px] font-medium text-on-surface-variant">{{ emp.hire_date || 'May 2026' }}</td>
                                <td class="px-6 py-3.5"><span class="rounded-full px-2.5 py-1 text-[9px] font-black uppercase bg-green-50 text-green-700 border border-green-100">Active</span></td>
                            </tr>
                            <tr v-if="!employees.length"><td colspan="4" class="px-6 py-8 text-center text-sm italic text-on-surface-variant">No recent hires recorded.</td></tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="lg:col-span-4 space-y-6">

                <!-- Dept Breakdown -->
                <div class="rounded-2xl p-6 text-white relative overflow-hidden" style="background:linear-gradient(135deg,#1a237e,#3949ab);border:1px solid rgba(255,255,255,0.06)">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] mb-5" style="color:rgba(255,255,255,0.3)">Workforce by Department</p>
                    <div class="space-y-3">
                        <div v-for="dept in [
                            { name: 'Technology', count: 285, pct: 22, color: '#3949ab' },
                            { name: 'Operations', count: 412, pct: 32, color: '#059669' },
                            { name: 'Finance',    count: 156, pct: 12, color: '#d97706' },
                            { name: 'Marketing',  count: 98,  pct: 8,  color: '#1a237e' },
                            { name: 'HR & Admin', count: 72,  pct: 6,  color: '#0891b2' },
                            { name: 'Other',      count: 261, pct: 20, color: '#6b7280' },
                        ]" :key="dept.name" class="space-y-1">
                            <div class="flex items-center justify-between text-[11px] font-bold">
                                <span style="color:rgba(255,255,255,0.7)">{{ dept.name }}</span>
                                <span style="color:rgba(255,255,255,0.4)">{{ dept.count }} ({{ dept.pct }}%)</span>
                            </div>
                            <div class="h-1.5 w-full rounded-full overflow-hidden" style="background:rgba(255,255,255,0.06)">
                                <div class="h-full rounded-full transition-all duration-700" :style="`width:${dept.pct}%;background:${dept.color}`"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Leave Summary -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4">Leave Overview · This Month</h4>
                    <div class="grid grid-cols-2 gap-3">
                        <div v-for="ls in [
                            { label: 'On Leave',    val: Math.round(spark.openPositions[11] * 3.4), color: 'text-amber-600', bg: 'bg-amber-50' },
                            { label: 'Pending',     val: stats.pendingLeave ?? 0,                    color: 'text-blue-600',  bg: 'bg-blue-50' },
                            { label: 'Approved',    val: 38,                                         color: 'text-green-600', bg: 'bg-green-50' },
                            { label: 'Annual Left', val: '12d',                                      color: 'text-secondary', bg: 'bg-secondary/10' },
                        ]" :key="ls.label"
                             class="rounded-xl p-3 text-center" :class="ls.bg">
                            <p class="text-xl font-black" :class="ls.color">{{ ls.val }}</p>
                            <p class="text-[9px] font-black uppercase mt-0.5 text-on-surface-variant">{{ ls.label }}</p>
                        </div>
                    </div>
                </div>

                <!-- Compliance -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5">
                    <h4 class="text-[13px] font-black text-primary mb-4"><GlossaryText text="HR Compliance" /></h4>
                    <div class="space-y-3">
                        <div v-for="item in [
                            { label: 'Labor Act 2003',   pct: 100, pass: true  },
                            { label: 'Data Protection',  pct: 97,  pass: true  },
                            { label: 'Policy Review',    pct: 82,  pass: false },
                            { label: 'Anti-Harassment',  pct: 100, pass: true  },
                        ]" :key="item.label" class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[18px]" :class="item.pass ? 'text-green-500' : 'text-amber-500'">{{ item.pass ? 'check_circle' : 'warning' }}</span>
                            <span class="flex-1 text-[12px] font-bold text-on-surface-variant">{{ item.label }}</span>
                            <span class="text-[11px] font-black" :class="item.pass ? 'text-green-600' : 'text-amber-600'">{{ item.pct }}%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    shifts:      Object,
    departments: Array,
    employees:   Array,
    assignments: Array,
});

const showCreate = ref(false);
const showAssign = ref(false);

const newShift = useForm({
    code: '', name: '',
    start_time: '08:00', end_time: '17:00',
    grace_period_minutes: 15,
    full_day_hours: 8.0, half_day_hours: 4.0,
    working_days: ['mon','tue','wed','thu','fri'],
    department_id: null,
    is_active: true,
});

const newAssignment = useForm({
    employee_id: '', shift_id: '',
    effective_from: new Date().toISOString().slice(0,10),
    effective_to: null,
});

function createShift() {
    newShift.post(route('attendance.shifts.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; newShift.reset(); },
    });
}

function assignShift() {
    newAssignment.post(route('attendance.shifts.assign'), {
        preserveScroll: true,
        onSuccess: () => { showAssign.value = false; newAssignment.reset(); },
    });
}

const dayLabels = { mon:'Mon', tue:'Tue', wed:'Wed', thu:'Thu', fri:'Fri', sat:'Sat', sun:'Sun' };
const allDays = ['mon','tue','wed','thu','fri','sat','sun'];
function toggleDay(d) {
    const i = newShift.working_days.indexOf(d);
    if (i >= 0) newShift.working_days.splice(i, 1);
    else newShift.working_days.push(d);
}
</script>

<template>
<Head title="Shifts" />
<AuthenticatedLayout active-module="attendance">
    <div class="space-y-8 animate-reveal-up p-6">
        <header class="flex items-center justify-between">
            <div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary">Shift Schedules</h1>
                <p class="text-sm text-on-surface-variant">Define shift patterns and assign them to employees. Default Ghana public-service schedule (Mon–Fri 08:00–17:00, 15-min grace) applies when no assignment is active.</p>
            </div>
            <div class="flex gap-2">
                <button @click="showAssign = true" type="button" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Assign</button>
                <button @click="showCreate = true" type="button" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white shadow-glow-sm btn-shimmer">+ New Shift</button>
            </div>
        </header>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 card-lift">
            <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">Shifts</h2>
            <table v-if="props.shifts.data?.length" class="w-full text-sm">
                <thead><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="pb-2">Code</th><th>Name</th><th>Window</th><th>Grace</th><th>Days</th><th>Dept</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-for="s in props.shifts.data" :key="s.id" class="border-t border-outline-variant/40">
                        <td class="py-2 font-mono">{{ s.code }}</td>
                        <td>{{ s.name }}</td>
                        <td>{{ s.start_time }} – {{ s.end_time }}</td>
                        <td>{{ s.grace_period_minutes }}m</td>
                        <td><span v-for="d in (s.working_days || [])" :key="d" class="px-1.5 py-0.5 mr-1 text-[10px] font-bold rounded bg-surface-container-low text-on-surface-variant">{{ dayLabels[d] }}</span></td>
                        <td>{{ s.department?.name ?? '—' }}</td>
                        <td><span v-if="s.is_active" class="text-[10px] font-bold text-emerald-700">ACTIVE</span><span v-else class="text-[10px] font-bold text-on-surface-variant">archived</span></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else message="No shifts defined. Default Ghana public-service schedule applies to all employees." class="py-8" />
        </section>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 card-lift">
            <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">Active Assignments</h2>
            <table v-if="props.assignments?.length" class="w-full text-sm">
                <thead><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="pb-2">Employee #</th><th>Shift</th><th>Effective From</th><th>Effective To</th>
                </tr></thead>
                <tbody>
                    <tr v-for="a in props.assignments" :key="a.id" class="border-t border-outline-variant/40">
                        <td class="py-2 font-mono">{{ a.employee?.employee_no }}</td>
                        <td>{{ a.shift?.name }} <span class="text-on-surface-variant">({{ a.shift?.code }})</span></td>
                        <td>{{ a.effective_from }}</td>
                        <td>{{ a.effective_to ?? 'open-ended' }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else message="No active assignments. All employees on default schedule." class="py-8" />
        </section>
    </div>

    <SlidePanel :show="showCreate" @close="showCreate = false" title="Create Shift">
        <form @submit.prevent="createShift" class="space-y-4 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Code</label><input v-model="newShift.code" maxlength="20" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 font-mono uppercase" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Name</label><input v-model="newShift.name" maxlength="80" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Start</label><input v-model="newShift.start_time" type="time" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">End</label><input v-model="newShift.end_time" type="time" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Grace (min)</label><input v-model.number="newShift.grace_period_minutes" type="number" min="0" max="120" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Full day h</label><input v-model.number="newShift.full_day_hours" type="number" step="0.25" min="1" max="24" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Half day h</label><input v-model.number="newShift.half_day_hours" type="number" step="0.25" min="0.5" max="12" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
            </div>
            <div>
                <label class="text-[11px] font-bold text-on-surface-variant">Working days</label>
                <div class="flex gap-1.5 mt-1">
                    <button v-for="d in allDays" :key="d" type="button" @click="toggleDay(d)"
                        class="rounded-lg px-2.5 py-1 text-[11px] font-bold border transition-colors"
                        :class="newShift.working_days.includes(d) ? 'border-primary bg-primary text-white' : 'border-outline-variant bg-surface-container-low text-on-surface-variant hover:border-primary/40'">
                        {{ dayLabels[d] }}
                    </button>
                </div>
            </div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Department (optional)</label><select v-model="newShift.department_id" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2"><option :value="null">— Any —</option><option v-for="d in props.departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></div>
            <button type="submit" :disabled="newShift.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Create Shift</button>
        </form>
    </SlidePanel>

    <SlidePanel :show="showAssign" @close="showAssign = false" title="Assign Shift to Employee">
        <form @submit.prevent="assignShift" class="space-y-4 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Employee</label><select v-model="newAssignment.employee_id" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2"><option value="" disabled>Select…</option><option v-for="e in props.employees" :key="e.id" :value="e.id">{{ e.employee_no }} — {{ e.position }}</option></select></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Shift</label><select v-model="newAssignment.shift_id" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2"><option value="" disabled>Select…</option><option v-for="s in props.shifts.data" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option></select></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Effective From</label><input v-model="newAssignment.effective_from" type="date" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Effective To</label><input v-model="newAssignment.effective_to" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
            </div>
            <button type="submit" :disabled="newAssignment.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Assign</button>
        </form>
    </SlidePanel>
</AuthenticatedLayout>
</template>

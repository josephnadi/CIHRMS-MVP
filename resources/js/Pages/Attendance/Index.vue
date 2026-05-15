<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    summaries:    Object,
    stats:        Object,
    month:        String,
    filters:      Object,
    activeModule: String,
});

const monthValue = ref(props.month);
const changeMonth = () => router.get(route('attendance.index'), { month: monthValue.value }, {
    preserveState: true, replace: true,
});

const showManual = ref(false);
const manual = useForm({
    employee_id: '',
    event_at:    new Date().toISOString().slice(0, 16),
    direction:   'in',
    reason:      '',
});
const submitManual = () => manual.post(route('attendance.manual'), {
    preserveScroll: true,
    onSuccess: () => { showManual.value = false; manual.reset('reason'); },
});

const statusTone = (s) => ({
    present:  'success',
    late:     'warn',
    half_day: 'warn',
    absent:   'danger',
    on_leave: 'neutral',
    holiday:  'neutral',
    weekend:  'neutral',
}[s] ?? 'neutral');
</script>

<template>
    <Head title="Attendance" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold tracking-tight">Attendance — Org-wide</h1>
                <PrimaryButton @click="showManual = true">Manual entry</PrimaryButton>
            </div>
        </template>

        <div class="flex gap-2 mb-4 pt-4 px-4 sm:px-6 lg:px-8">
            <Link :href="route('attendance.index')" class="rounded-xl px-3 py-1.5 text-xs font-bold border border-primary text-primary bg-primary/5">Daily</Link>
            <Link v-if="$page.props.auth.permissions?.includes('attendance.approve')" :href="route('attendance.corrections.index')" class="rounded-xl px-3 py-1.5 text-xs font-bold border border-outline-variant text-on-surface-variant hover:border-primary/40 hover:text-primary">Corrections</Link>
            <Link v-if="$page.props.auth.permissions?.includes('attendance.shift_manage')" :href="route('attendance.shifts.index')" class="rounded-xl px-3 py-1.5 text-xs font-bold border border-outline-variant text-on-surface-variant hover:border-primary/40 hover:text-primary">Shifts</Link>
        </div>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="Present today" :value="stats.present_today" tone="success" />
                <StatCard label="Late today" :value="stats.late_today" tone="warn" />
                <StatCard label="Absent today" :value="stats.absent_today" tone="danger" />
                <StatCard label="Avg hours / day (month)" :value="stats.month_avg_hours" />
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100">
                <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap gap-3 items-center">
                    <label class="text-xs text-slate-600">Period</label>
                    <input v-model="monthValue" type="month" @change="changeMonth"
                           class="rounded-lg border-slate-200 text-sm">
                </div>

                <div v-if="summaries?.data?.length === 0">
                    <EmptyState title="No attendance records for this period"
                                description="Records arrive from biometric webhooks, self-service clock-in, or manual entry." />
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Employee</th>
                            <th class="px-5 py-3 text-left">Date</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-right">In</th>
                            <th class="px-5 py-3 text-right">Out</th>
                            <th class="px-5 py-3 text-right">Hours</th>
                            <th class="px-5 py-3 text-right">OT (premium-hrs)</th>
                            <th class="px-5 py-3 text-left">Source</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="s in summaries.data" :key="s.id" class="hover:bg-slate-50">
                            <td class="px-5 py-2.5">
                                <div class="font-medium">{{ s.employee?.user?.name ?? '—' }}</div>
                            </td>
                            <td class="px-5 py-2.5">{{ s.date }}</td>
                            <td class="px-5 py-2.5">
                                <StatusBadge :status="s.status" :label="s.status_label" :tone="statusTone(s.status)" />
                            </td>
                            <td class="px-5 py-2.5 text-right font-mono text-xs">{{ s.first_in ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-right font-mono text-xs">{{ s.last_out ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-right">{{ Number(s.hours_worked).toFixed(2) }}</td>
                            <td class="px-5 py-2.5 text-right">{{ Number(s.overtime_hours).toFixed(2) }}</td>
                            <td class="px-5 py-2.5 text-slate-500 text-xs">{{ s.source ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-slate-100">
                    <Pagination :links="summaries?.meta?.links ?? []" />
                </div>
            </div>
        </div>

        <SlidePanel v-model="showManual" title="Manual attendance entry">
            <form @submit.prevent="submitManual" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Employee ID</label>
                    <input v-model="manual.employee_id" type="number" class="w-full rounded-lg border-slate-200" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Event timestamp</label>
                    <input v-model="manual.event_at" type="datetime-local" class="w-full rounded-lg border-slate-200" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Direction</label>
                    <select v-model="manual.direction" class="w-full rounded-lg border-slate-200">
                        <option value="in">Clock-in</option>
                        <option value="out">Clock-out</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Reason (required, audit-logged)</label>
                    <textarea v-model="manual.reason" rows="3" class="w-full rounded-lg border-slate-200" required></textarea>
                </div>
                <p v-if="manual.errors.reason" class="text-rose-600 text-xs">{{ manual.errors.reason }}</p>
                <PrimaryButton type="submit" :disabled="manual.processing">Record</PrimaryButton>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>

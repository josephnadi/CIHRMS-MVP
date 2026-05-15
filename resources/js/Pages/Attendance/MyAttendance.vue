<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    period:  Object,
    summary: Object,
    days:    Object,
});

const monthValue = ref(props.period.label);
const changeMonth = () => router.get(route('attendance.me'), { month: monthValue.value }, {
    preserveState: true, replace: true,
});

const clockForm = useForm({ direction: 'in', geo_lat: null, geo_lng: null });

const tryGeo = () => new Promise((resolve) => {
    if (! navigator.geolocation) return resolve(null);
    navigator.geolocation.getCurrentPosition(
        p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
        () => resolve(null),
        { timeout: 4000 },
    );
});

const clockSelf = async (direction) => {
    clockForm.direction = direction;
    const geo = await tryGeo();
    if (geo) { clockForm.geo_lat = geo.lat; clockForm.geo_lng = geo.lng; }
    clockForm.post(route('attendance.clock'), { preserveScroll: true });
};

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
    <Head title="My Attendance" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-semibold tracking-tight">My Attendance — {{ period.label }}</h1>
        </template>

        <div class="py-6 space-y-6">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex flex-wrap gap-3 items-center">
                <PrimaryButton @click="clockSelf('in')" :disabled="clockForm.processing">Clock In</PrimaryButton>
                <PrimaryButton @click="clockSelf('out')" :disabled="clockForm.processing"
                               class="bg-slate-700 hover:bg-slate-800">Clock Out</PrimaryButton>
                <span class="text-xs text-slate-500 ml-auto">GPS location captured if you allow it.</span>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <StatCard label="Working days" :value="summary.working_days" />
                <StatCard label="Days worked" :value="summary.days_worked" tone="success" />
                <StatCard label="Days absent" :value="summary.days_absent" tone="danger" />
                <StatCard label="Days late" :value="summary.days_late" tone="warn" />
                <StatCard label="Attendance" :value="(summary.attendance_ratio * 100).toFixed(0) + '%'" />
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
                    <input v-model="monthValue" type="month" @change="changeMonth"
                           class="rounded-lg border-slate-200 text-sm">
                </div>

                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Date</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-right">In</th>
                            <th class="px-5 py-3 text-right">Out</th>
                            <th class="px-5 py-3 text-right">Hours</th>
                            <th class="px-5 py-3 text-right">OT</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="d in days.data" :key="d.id">
                            <td class="px-5 py-2.5">{{ d.date }}</td>
                            <td class="px-5 py-2.5">
                                <StatusBadge :status="d.status" :label="d.status_label" :tone="statusTone(d.status)" />
                            </td>
                            <td class="px-5 py-2.5 text-right font-mono text-xs">{{ d.first_in ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-right font-mono text-xs">{{ d.last_out ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-right">{{ d.hours_worked.toFixed(2) }}</td>
                            <td class="px-5 py-2.5 text-right">{{ d.overtime_hours.toFixed(2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

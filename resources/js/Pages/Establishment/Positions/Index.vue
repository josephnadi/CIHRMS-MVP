<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    positions:    Object,
    stats:        Object,
    filters:      Object,
    activeModule: String,
});

const localFilters = reactive({
    status:        props.filters?.status        ?? '',
    department_id: props.filters?.department_id ?? '',
    grade_id:      props.filters?.grade_id      ?? '',
});

const applyFilters = () => router.get(route('positions.index'), {
    status:        localFilters.status        || undefined,
    department_id: localFilters.department_id || undefined,
    grade_id:      localFilters.grade_id      || undefined,
}, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Positions / Establishment" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <h1 class="text-2xl font-semibold tracking-tight">Establishment — Positions</h1>
        </template>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="Total positions" :value="stats.total" />
                <StatCard label="Vacant" :value="stats.vacant" tone="warn" />
                <StatCard label="Filled" :value="stats.filled" tone="success" />
                <StatCard label="Frozen" :value="stats.frozen" tone="neutral" />
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100">
                <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap gap-3 items-center">
                    <select v-model="localFilters.status" @change="applyFilters"
                            class="rounded-lg border-slate-200 text-sm">
                        <option value="">All statuses</option>
                        <option value="vacant">Vacant</option>
                        <option value="filled">Filled</option>
                        <option value="frozen">Frozen</option>
                        <option value="acting">Acting</option>
                    </select>
                </div>

                <div v-if="positions?.data?.length === 0">
                    <EmptyState title="No positions defined yet"
                                description="Approved establishment posts will appear here." />
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Code</th>
                            <th class="px-5 py-3 text-left">Title</th>
                            <th class="px-5 py-3 text-left">Grade</th>
                            <th class="px-5 py-3 text-left">Department</th>
                            <th class="px-5 py-3 text-left">Cost center</th>
                            <th class="px-5 py-3 text-left">Funding</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="p in positions.data" :key="p.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3 font-mono text-xs">{{ p.code }}</td>
                            <td class="px-5 py-3 font-medium">{{ p.title }}</td>
                            <td class="px-5 py-3">{{ p.grade?.code }}</td>
                            <td class="px-5 py-3">{{ p.department?.name ?? '—' }}</td>
                            <td class="px-5 py-3">{{ p.cost_center ?? '—' }}</td>
                            <td class="px-5 py-3">{{ p.funding_source_label }}</td>
                            <td class="px-5 py-3"><StatusBadge :status="p.status" :label="p.status_label" /></td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('positions.show', p.id)" class="text-indigo-600 hover:underline">Open</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-slate-100">
                    <Pagination :links="positions?.meta?.links ?? []" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    sessions:     Object,
    cycles:       Array,
    activeModule: String,
});

const showPanel = ref(false);
const form = useForm({ cycle_id: '', department_id: '', target_distribution: null });

const submit = () => form.post(route('performance.calibration.store'), {
    preserveScroll: true,
    onSuccess: () => { showPanel.value = false; form.reset(); },
});
</script>

<template>
    <Head title="Calibration Sessions" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-on-surface-variant/60">Force-distribution · Bell-curve enforcement</p>
                    <h1 class="text-2xl font-semibold tracking-tight">Calibration</h1>
                </div>
                <PrimaryButton @click="showPanel = true">+ New session</PrimaryButton>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40">
                <table class="w-full text-sm">
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Cycle</th>
                            <th class="px-5 py-3 text-left">Department</th>
                            <th class="px-5 py-3 text-left">Facilitator</th>
                            <th class="px-5 py-3 text-right">Adjustments</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="s in sessions.data" :key="s.id" class="hover:bg-surface-container-low/60">
                            <td class="px-5 py-3">{{ s.cycle?.name }}</td>
                            <td class="px-5 py-3 text-xs">{{ s.department?.name ?? 'Org-wide' }}</td>
                            <td class="px-5 py-3 text-xs">{{ s.facilitator?.name ?? '—' }}</td>
                            <td class="px-5 py-3 text-right">{{ s.adjustments_count }}</td>
                            <td class="px-5 py-3"><StatusBadge :status="s.status" :label="s.status_label" /></td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('performance.calibration.show', s.id)" class="text-secondary hover:underline">Open</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-outline-variant/40">
                    <Pagination :links="sessions?.meta?.links ?? []" />
                </div>
            </div>
        </div>

        <SlidePanel v-model="showPanel" title="Open calibration session">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Cycle</label>
                    <select v-model="form.cycle_id" class="w-full rounded-lg border-outline-variant text-sm" required>
                        <option value="">Select…</option>
                        <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Department (leave blank for org-wide)</label>
                    <input v-model="form.department_id" type="number" class="w-full rounded-lg border-outline-variant text-sm">
                </div>
                <PrimaryButton type="submit" :disabled="form.processing">Open session</PrimaryButton>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>

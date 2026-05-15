<script setup>
import { reactive } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    verifications: Object,
    stats:         Object,
    filters:       Object,
    activeModule:  String,
});

const form = useForm({
    employee_id: '',
    ghana_card_number: '',
});

const submit = () => form.post(route('identity.store'), { preserveScroll: true });
</script>

<template>
    <Head title="Ghana Card Verification" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <h1 class="text-2xl font-semibold tracking-tight">Ghana Card / NIA Identity Verification</h1>
        </template>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="Verified" :value="stats.verified" tone="success" />
                <StatCard label="Pending" :value="stats.pending" tone="warn" />
                <StatCard label="Failed" :value="stats.failed" tone="danger" />
                <StatCard label="Unverified active employees" :value="stats.unverified_employees" tone="danger" />
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                <h2 class="text-sm font-semibold mb-3">Submit a new verification</h2>
                <form @submit.prevent="submit" class="grid md:grid-cols-3 gap-3">
                    <input v-model="form.employee_id" type="number" placeholder="Employee ID"
                           class="rounded-lg border-slate-200 text-sm" required>
                    <input v-model="form.ghana_card_number" placeholder="GHA-123456789-1"
                           class="rounded-lg border-slate-200 text-sm" required>
                    <PrimaryButton type="submit" :disabled="form.processing">Verify</PrimaryButton>
                </form>
                <p v-if="form.errors.ghana_card_number" class="text-rose-600 text-xs mt-2">{{ form.errors.ghana_card_number }}</p>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Employee</th>
                            <th class="px-5 py-3 text-left">Card</th>
                            <th class="px-5 py-3 text-left">Provider</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-left">Verified at</th>
                            <th class="px-5 py-3 text-left">Expires</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="v in verifications.data" :key="v.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <div class="font-medium">{{ v.employee?.name ?? '—' }}</div>
                                <div class="text-xs text-slate-500">{{ v.employee?.employee_no }}</div>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs">{{ v.masked_card }}</td>
                            <td class="px-5 py-3">{{ v.provider_label }}</td>
                            <td class="px-5 py-3"><StatusBadge :status="v.status" :label="v.status_label" /></td>
                            <td class="px-5 py-3">{{ v.verified_at ? new Date(v.verified_at).toLocaleDateString('en-GH') : '—' }}</td>
                            <td class="px-5 py-3">{{ v.expires_at ? new Date(v.expires_at).toLocaleDateString('en-GH') : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">
                    <Pagination :links="verifications?.meta?.links ?? []" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

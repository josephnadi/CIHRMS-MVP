<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    users: { type: Object, required: true },
    roles: { type: Array,  default: () => [] },
});

const rows = computed(() => props.users.data ?? []);

const PRIVILEGED = ['super_admin', 'ceo', 'hr_admin', 'finance_officer'];

const showCreate = ref(false);
const form = useForm({
    name: '',
    email: '',
    staff_id: '',
    role: 'employee',
    password: '',
    password_confirmation: '',
    two_factor_required: false,
});

const privilegedSelected = computed(() => PRIVILEGED.includes(form.role));

const openCreate = () => {
    form.reset();
    form.role = 'employee';
    showCreate.value = true;
};

const submit = () => {
    form.post(route('admin.users.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; form.reset(); },
    });
};

const roleLabel = (slug) => props.roles.find(r => r.value === slug)?.label ?? slug;

const rolePillClass = (slug) => {
    if (slug === 'super_admin') return 'bg-rose-50 text-rose-700 border-rose-200';
    if (slug === 'ceo')         return 'bg-amber-50 text-amber-700 border-amber-200';
    if (slug === 'hr_admin' || slug === 'finance_officer') return 'bg-secondary/10 text-secondary border-secondary/30';
    return 'bg-surface-container text-on-surface-variant border-outline-variant';
};
</script>

<template>
    <Head title="Admin · Users" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">ADMIN — USERS</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">User Accounts</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                    {{ users.total ?? rows.length }} accounts · create super-admin / CEO / role-based users.
                </p>
            </div>
            <button @click="openCreate"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary text-on-primary px-4 py-2 text-[13px] font-bold hover:opacity-90 transition-opacity">
                <span class="material-symbols-outlined text-[18px]">person_add</span>
                New User
            </button>
        </div>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[13px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Name</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Staff ID</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Email</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Role</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">2FA</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in rows" :key="u.id" class="border-t border-outline-variant/30">
                        <td class="px-4 py-2.5 font-semibold text-on-surface">{{ u.name }}</td>
                        <td class="px-4 py-2.5 font-mono text-on-surface-variant">{{ u.staff_id }}</td>
                        <td class="px-4 py-2.5 text-on-surface-variant">{{ u.email }}</td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                  :class="rolePillClass(u.role)">{{ roleLabel(u.role) }}</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <span v-if="u.two_factor_confirmed_at" class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700">
                                <span class="material-symbols-outlined text-[14px]">verified</span> Active
                            </span>
                            <span v-else-if="u.two_factor_required" class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-700">
                                <span class="material-symbols-outlined text-[14px]">schedule</span> Required
                            </span>
                            <span v-else class="text-[11px] text-on-surface-variant/60">Optional</span>
                        </td>
                        <td class="px-4 py-2.5 text-on-surface-variant text-[11px]">{{ u.created_at?.substring(0, 10) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <SlidePanel :open="showCreate" @close="showCreate = false" title="Create User Account" size="md">
            <form @submit.prevent="submit" class="space-y-4 p-6">

                <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-[18px] text-amber-600 flex-shrink-0 mt-0.5" style="font-variation-settings:'FILL' 1">info</span>
                    <p class="text-[12px] text-amber-700">
                        New users must change their password on first login. Privileged roles (super-admin, CEO, HR admin, finance officer) automatically require 2FA.
                    </p>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Full name</label>
                    <input v-model="form.name" type="text" required autocomplete="off"
                           class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    <p v-if="form.errors.name" class="mt-1 text-[11px] text-rose-700">{{ form.errors.name }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Staff ID</label>
                        <input v-model="form.staff_id" type="text" required placeholder="GH-HR-042" autocomplete="off"
                               class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px] font-mono" />
                        <p v-if="form.errors.staff_id" class="mt-1 text-[11px] text-rose-700">{{ form.errors.staff_id }}</p>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Email</label>
                        <input v-model="form.email" type="email" required autocomplete="off"
                               class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                        <p v-if="form.errors.email" class="mt-1 text-[11px] text-rose-700">{{ form.errors.email }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Role</label>
                    <select v-model="form.role" required
                            class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </select>
                    <p v-if="form.errors.role" class="mt-1 text-[11px] text-rose-700">{{ form.errors.role }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Password</label>
                        <input v-model="form.password" type="password" required autocomplete="new-password"
                               class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                        <p v-if="form.errors.password" class="mt-1 text-[11px] text-rose-700">{{ form.errors.password }}</p>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Confirm password</label>
                        <input v-model="form.password_confirmation" type="password" required autocomplete="new-password"
                               class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    </div>
                </div>

                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="checkbox" v-model="form.two_factor_required" :disabled="privilegedSelected"
                           class="mt-0.5 accent-secondary" />
                    <span class="text-[12px] text-on-surface-variant">
                        Require 2FA enrolment on first login
                        <span v-if="privilegedSelected" class="block text-[10px] font-bold text-amber-700">Automatically required for {{ roleLabel(form.role) }}.</span>
                    </span>
                </label>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="showCreate = false"
                            class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <button type="submit" :disabled="form.processing"
                            class="rounded-xl bg-primary text-on-primary px-3 py-2 text-[12px] font-bold disabled:opacity-60">Create user</button>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>

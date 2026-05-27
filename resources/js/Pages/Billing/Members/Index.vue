<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    members: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const page = usePage();
const canManage = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('members.manage') || list.includes('*');
});

const rows         = computed(() => props.members.data ?? props.members ?? []);
const q            = ref(props.filters.q ?? '');
const classFilter  = ref(props.filters.class ?? '');
const statusFilter = ref(props.filters.status ?? '');

const apply = () => router.get(route('billing.members.index'), {
    q: q.value || undefined,
    class: classFilter.value || undefined,
    status: statusFilter.value || undefined,
}, { preserveState: true, replace: true });

let timer = null;
watch(q, () => { clearTimeout(timer); timer = setTimeout(apply, 320); });
watch([classFilter, statusFilter], apply);

const showForm = ref(false);
const form = useForm({
    class: 'professional',
    name: '',
    email: '',
    phone: '',
    address: '',
    date_of_birth: '',
    ghana_card_number: '',
    chartered_at: '',
});
function submit() {
    form.post(route('billing.members.store'), {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); },
    });
}
</script>

<template>
<Head title="Members — CIHRM" />
<div class="p-6 max-w-7xl mx-auto">
    <header class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-primary">Members</h1>
            <p class="text-sm text-on-surface-variant">CIHRM members and students — the billable parties for fee assignments.</p>
        </div>
        <PrimaryButton v-if="canManage" @click="showForm = true">Register member</PrimaryButton>
    </header>

    <div class="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <input v-model="q" aria-label="Search members" placeholder="Search name, email, member no…" class="rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest" />
        <select v-model="classFilter" aria-label="Filter by member class" class="rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest">
            <option value="">All classes</option>
            <option value="associate">Associate</option>
            <option value="professional">Professional</option>
            <option value="fellow">Fellow</option>
            <option value="student">Student</option>
            <option value="alumni">Alumni</option>
        </select>
        <select v-model="statusFilter" aria-label="Filter by member status" class="rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="lapsed">Lapsed</option>
            <option value="resigned">Resigned</option>
            <option value="deceased">Deceased</option>
        </select>
    </div>

    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-surface-container">
                <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    <th class="px-4 py-3">Member No</th>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Class</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Phone</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="m in rows" :key="m.id" class="border-t border-outline-variant/40 hover:bg-surface-container/50">
                    <td class="px-4 py-2 font-mono text-xs">
                        <Link :href="route('billing.members.show', m.id)" class="text-primary hover:underline">{{ m.member_no }}</Link>
                    </td>
                    <td class="px-4 py-2 font-semibold">{{ m.name }}</td>
                    <td class="px-4 py-2 capitalize">{{ m.class }}</td>
                    <td class="px-4 py-2 capitalize">{{ m.status }}</td>
                    <td class="px-4 py-2">{{ m.email ?? '—' }}</td>
                    <td class="px-4 py-2">{{ m.phone ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="rows.length === 0" title="No members yet" subtitle="Click 'Register member' to add the first CIHRM member or student." />
    </div>

    <SlidePanel v-if="showForm" @close="showForm = false" title="Register CIHRM member">
        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="class" value="Class" />
                <select id="class" v-model="form.class" aria-label="Member class" class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                    <option value="associate">Associate</option>
                    <option value="professional">Professional</option>
                    <option value="fellow">Fellow</option>
                    <option value="student">Student</option>
                    <option value="alumni">Alumni</option>
                </select>
                <InputError :message="form.errors.class" class="mt-1" />
            </div>
            <div>
                <InputLabel for="name" value="Full name" />
                <TextInput id="name" v-model="form.name" type="text" required class="mt-1 w-full" />
                <InputError :message="form.errors.name" class="mt-1" />
            </div>
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" v-model="form.email" type="email" class="mt-1 w-full" />
                <InputError :message="form.errors.email" class="mt-1" />
            </div>
            <div>
                <InputLabel for="phone" value="Phone (e.g. +233...)" />
                <TextInput id="phone" v-model="form.phone" type="text" class="mt-1 w-full" />
                <InputError :message="form.errors.phone" class="mt-1" />
            </div>
            <div>
                <InputLabel for="ghana_card_number" value="Ghana Card number (hashed before storage)" />
                <TextInput id="ghana_card_number" v-model="form.ghana_card_number" type="text" class="mt-1 w-full" />
                <InputError :message="form.errors.ghana_card_number" class="mt-1" />
            </div>
            <div class="pt-2 flex justify-end gap-2">
                <button type="button" @click="showForm = false" class="rounded-xl px-4 py-2 text-sm">Cancel</button>
                <PrimaryButton type="submit" :disabled="form.processing">Register</PrimaryButton>
            </div>
        </form>
    </SlidePanel>
</div>
</template>

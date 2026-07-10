<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    member: { type: Object, required: true },
});

const form = useForm({
    name:    props.member.name,
    email:   props.member.email ?? '',
    phone:   props.member.phone ?? '',
    address: props.member.address ?? '',
});

function submit() {
    form.patch(route('portal.profile.update'), { preserveScroll: true });
}
</script>

<template>
<Head title="Profile — CIHRM Portal" />
<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-black text-primary">Your profile</h1>
        <p class="text-sm text-on-surface-variant">
            Contact details are member-editable. Class, status, and member number are managed by the institute office.
        </p>
    </header>

    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Member no</p>
            <p class="font-mono">{{ member.member_no }}</p>
        </div>
        <div>
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Class</p>
            <p class="capitalize">{{ member.class }}</p>
        </div>
        <div>
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Status</p>
            <p class="capitalize">{{ member.status }}</p>
        </div>
    </section>

    <form @submit.prevent="submit" class="space-y-4 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
        <div>
            <label for="name" class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-1">Full name</label>
            <input id="name" v-model="form.name" required
                   class="w-full rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-error">{{ form.errors.name }}</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="email" class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-1">Email</label>
                <input id="email" v-model="form.email" type="email"
                       class="w-full rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest" />
                <p v-if="form.errors.email" class="mt-1 text-xs text-error">{{ form.errors.email }}</p>
            </div>
            <div>
                <label for="phone" class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-1">Phone</label>
                <input id="phone" v-model="form.phone"
                       class="w-full rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest" />
                <p v-if="form.errors.phone" class="mt-1 text-xs text-error">{{ form.errors.phone }}</p>
            </div>
        </div>
        <div>
            <label for="address" class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-1">Address</label>
            <textarea id="address" v-model="form.address" rows="3"
                      class="w-full rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest"></textarea>
            <p v-if="form.errors.address" class="mt-1 text-xs text-error">{{ form.errors.address }}</p>
        </div>
        <div class="flex justify-end">
            <button type="submit" :disabled="form.processing"
                    class="rounded-xl bg-gradient-to-br from-primary to-secondary px-5 py-2 text-sm font-black text-white shadow-glow-sm disabled:opacity-50">
                Save changes
            </button>
        </div>
    </form>
</div>
</template>

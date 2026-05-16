<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    announcements: Object,
});

const list = computed(() => props.announcements?.data ?? props.announcements ?? []);

const showForm = ref(false);

const form = useForm({
    type:          'notice',
    severity:      'info',
    title:         '',
    body:          '',
    link_url:      '',
    audience_role: '',
    pinned:        false,
    is_active:     true,
    starts_at:     '',
    ends_at:       '',
});

const submit = () => {
    form.post(route('announcements.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
};

const remove = (id) => {
    if (!confirm('Remove this notice?')) return;
    router.delete(route('announcements.destroy', id), { preserveScroll: true });
};

const typeMeta = {
    notice:   { icon: 'campaign',  label: 'Notice',   bg: 'bg-secondary/10',     text: 'text-secondary' },
    event:    { icon: 'event',     label: 'Event',    bg: 'bg-brand-cyan/15',    text: 'text-brand-blue-bright' },
    birthday: { icon: 'cake',      label: 'Birthday', bg: 'bg-brand-magenta/12', text: 'text-brand-magenta' },
    task:     { icon: 'task_alt',  label: 'Task',     bg: 'bg-brand-gold/15',    text: 'text-brand-gold-deep' },
    system:   { icon: 'info',      label: 'System',   bg: 'bg-on-surface-variant/10', text: 'text-on-surface-variant' },
};

const severityMeta = {
    info:      { label: 'Info',      bg: 'bg-secondary/10',     text: 'text-secondary' },
    important: { label: 'Important', bg: 'bg-brand-gold/20',    text: 'text-brand-gold-deep' },
    urgent:    { label: 'Urgent',    bg: 'bg-brand-magenta/12', text: 'text-brand-magenta' },
};

const audienceRoles = [
    { value: '',                label: 'Everyone' },
    { value: 'hr_admin',        label: 'HR' },
    { value: 'manager',         label: 'Managers' },
    { value: 'employee',        label: 'Employees' },
    { value: 'finance_officer', label: 'Finance' },
    { value: 'it_support',      label: 'IT Support' },
    { value: 'marketing',       label: 'Marketing' },
];
</script>

<template>
    <Head title="Notice Board" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70">Communications</p>
                    <h1 class="mt-1 text-[1.6rem] font-black tracking-tight text-primary">Notice Board</h1>
                    <p class="mt-1 text-[13px] text-on-surface-variant">Publish notices that scroll across the top of every page.</p>
                </div>
                <button @click="showForm = !showForm"
                        class="btn-shimmer flex items-center gap-2 rounded-xl bg-gradient-cobalt px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:shadow-glow hover:-translate-y-px">
                    <span class="material-symbols-outlined text-[18px]">{{ showForm ? 'close' : 'add' }}</span>
                    {{ showForm ? 'Cancel' : 'New notice' }}
                </button>
            </div>
        </template>

        <!-- Create form -->
        <Transition
            enter-active-class="transition-all duration-200 ease-spring"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2">
            <section v-if="showForm" class="mb-6 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest shadow-card">
                <header class="border-b border-outline-variant/60 px-5 py-3">
                    <h2 class="text-[13px] font-black uppercase tracking-[0.12em] text-on-surface">Compose a notice</h2>
                </header>

                <form @submit.prevent="submit" class="grid grid-cols-1 gap-4 p-5 lg:grid-cols-12">
                    <!-- Title -->
                    <div class="lg:col-span-12">
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant">Title</label>
                        <input v-model="form.title" type="text" required maxlength="180"
                               placeholder="Office closes 1pm Friday for staff retreat"
                               class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-4 py-2.5 text-[13.5px] text-on-surface placeholder:text-on-surface-variant/40 focus:border-secondary focus:outline-none focus:ring-4 focus:ring-secondary/10" />
                        <p v-if="form.errors.title" class="mt-1 text-[11px] font-bold text-brand-magenta">{{ form.errors.title }}</p>
                    </div>

                    <!-- Body -->
                    <div class="lg:col-span-12">
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant">Details <span class="font-normal opacity-60">(optional)</span></label>
                        <textarea v-model="form.body" rows="2" maxlength="2000"
                                  class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-4 py-2.5 text-[13.5px] text-on-surface placeholder:text-on-surface-variant/40 focus:border-secondary focus:outline-none focus:ring-4 focus:ring-secondary/10"></textarea>
                    </div>

                    <!-- Type -->
                    <div class="lg:col-span-3">
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant">Type</label>
                        <select v-model="form.type"
                                class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2.5 text-[13px] text-on-surface focus:border-secondary focus:outline-none focus:ring-4 focus:ring-secondary/10">
                            <option v-for="(meta, k) in typeMeta" :key="k" :value="k">{{ meta.label }}</option>
                        </select>
                    </div>

                    <!-- Severity -->
                    <div class="lg:col-span-3">
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant">Severity</label>
                        <select v-model="form.severity"
                                class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2.5 text-[13px] text-on-surface focus:border-secondary focus:outline-none focus:ring-4 focus:ring-secondary/10">
                            <option v-for="(meta, k) in severityMeta" :key="k" :value="k">{{ meta.label }}</option>
                        </select>
                    </div>

                    <!-- Audience -->
                    <div class="lg:col-span-3">
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant">Audience</label>
                        <select v-model="form.audience_role"
                                class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2.5 text-[13px] text-on-surface focus:border-secondary focus:outline-none focus:ring-4 focus:ring-secondary/10">
                            <option v-for="r in audienceRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>

                    <!-- Link -->
                    <div class="lg:col-span-3">
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant">Link <span class="font-normal opacity-60">(optional)</span></label>
                        <input v-model="form.link_url" type="url" placeholder="https://…"
                               class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:border-secondary focus:outline-none focus:ring-4 focus:ring-secondary/10" />
                    </div>

                    <!-- Starts -->
                    <div class="lg:col-span-4">
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant">Starts</label>
                        <input v-model="form.starts_at" type="datetime-local"
                               class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2.5 text-[13px] text-on-surface focus:border-secondary focus:outline-none focus:ring-4 focus:ring-secondary/10" />
                    </div>

                    <!-- Ends -->
                    <div class="lg:col-span-4">
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-on-surface-variant">Ends</label>
                        <input v-model="form.ends_at" type="datetime-local"
                               class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2.5 text-[13px] text-on-surface focus:border-secondary focus:outline-none focus:ring-4 focus:ring-secondary/10" />
                    </div>

                    <!-- Toggles -->
                    <div class="flex items-center gap-4 lg:col-span-4">
                        <label class="inline-flex items-center gap-2 text-[12.5px] font-semibold text-on-surface cursor-pointer">
                            <input v-model="form.pinned" type="checkbox" class="h-4 w-4 rounded border-outline-variant text-secondary focus:ring-secondary/30" />
                            Pin to front
                        </label>
                        <label class="inline-flex items-center gap-2 text-[12.5px] font-semibold text-on-surface cursor-pointer">
                            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-outline-variant text-secondary focus:ring-secondary/30" />
                            Active
                        </label>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-2 lg:col-span-12 border-t border-outline-variant/60 pt-4 -mx-5 px-5">
                        <button type="button" @click="showForm = false"
                                class="rounded-xl px-4 py-2 text-[13px] font-bold text-on-surface-variant hover:bg-surface-container-low">
                            Cancel
                        </button>
                        <button type="submit" :disabled="form.processing"
                                class="btn-shimmer flex items-center gap-2 rounded-xl bg-gradient-cobalt px-5 py-2 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:shadow-glow hover:-translate-y-px disabled:opacity-60">
                            <span class="material-symbols-outlined text-[16px]">{{ form.processing ? 'progress_activity' : 'send' }}</span>
                            {{ form.processing ? 'Publishing…' : 'Publish notice' }}
                        </button>
                    </div>
                </form>
            </section>
        </Transition>

        <!-- List -->
        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest shadow-card">
            <header class="flex items-center justify-between border-b border-outline-variant/60 px-5 py-3">
                <h2 class="text-[13px] font-black uppercase tracking-[0.12em] text-on-surface">Published notices</h2>
                <span class="text-[11px] font-semibold text-on-surface-variant">{{ list.length }} total</span>
            </header>

            <div v-if="list.length === 0" class="p-10">
                <EmptyState icon="campaign" title="No notices yet" hint="Click 'New notice' to publish your first one." />
            </div>

            <ul v-else class="divide-y divide-outline-variant/50">
                <li v-for="a in list" :key="a.id" class="flex items-start gap-3 px-5 py-4 hover:bg-surface-container-low/40">
                    <span class="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg"
                          :class="[typeMeta[a.type]?.bg, typeMeta[a.type]?.text]">
                        <span class="material-symbols-outlined text-[18px]" style="font-variation-settings:'FILL' 1">{{ a.icon || typeMeta[a.type]?.icon }}</span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-[13.5px] font-bold text-on-surface">{{ a.title }}</p>
                            <span v-if="a.pinned" class="badge bg-brand-gold/15 text-brand-gold-deep border border-brand-gold/25">Pinned</span>
                            <span v-if="!a.is_active" class="badge bg-on-surface-variant/10 text-on-surface-variant border border-outline-variant">Inactive</span>
                            <span class="badge"
                                  :class="[severityMeta[a.severity]?.bg, severityMeta[a.severity]?.text]">
                                {{ severityMeta[a.severity]?.label }}
                            </span>
                        </div>
                        <p v-if="a.body" class="mt-1 text-[12.5px] text-on-surface-variant line-clamp-2">{{ a.body }}</p>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10.5px] font-semibold uppercase tracking-wider text-on-surface-variant/70">
                            <span>{{ typeMeta[a.type]?.label }}</span>
                            <span v-if="a.audience_role">· {{ a.audience_role }}</span>
                            <span v-else>· Everyone</span>
                            <span v-if="a.starts_at">· From {{ new Date(a.starts_at).toLocaleString() }}</span>
                            <span v-if="a.ends_at">· Until {{ new Date(a.ends_at).toLocaleString() }}</span>
                            <span v-if="a.author">· by {{ a.author.name }}</span>
                        </div>
                    </div>
                    <button @click="remove(a.id)"
                            class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg text-on-surface-variant/60 transition-colors hover:bg-brand-magenta/10 hover:text-brand-magenta"
                            aria-label="Remove notice">
                        <span class="material-symbols-outlined text-[17px]">delete</span>
                    </button>
                </li>
            </ul>
        </section>
    </AuthenticatedLayout>
</template>

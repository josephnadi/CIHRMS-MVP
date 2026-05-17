<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import StatusBadge from '@/Components/StatusBadge.vue';

const props = defineProps({
    job: Object,
});

const j = computed(() => props.job?.data ?? props.job);

const showForm = ref(false);
const submitted = ref(false);

const form = useForm({
    name:  '',
    email: '',
    cv:    null,
});

const submit = () => {
    form.post(route('careers.apply', j.value.id), {
        forceFormData: true,
        onSuccess: () => {
            submitted.value = true;
            form.reset();
        },
    });
};

const onFileChange = (e) => {
    form.cv = e.target.files[0] ?? null;
};

const formatDate = (d) => {
    if (!d) return 'Open until filled';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};
</script>

<template>
    <Head :title="`${j.title} Â· Careers Â· CIHRM Ghana`" />

    <div class="min-h-screen bg-background">

        <!-- Public header -->
        <header class="sticky top-0 z-30 border-b border-outline-variant/40 bg-surface-container-lowest/80 backdrop-blur">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <Link href="/" class="group flex items-center gap-3">
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-xl shadow-glow-sm transition-transform group-hover:rotate-6"
                        style="background:linear-gradient(135deg,#0a2647,#205295)"
                    >
                        <span class="material-symbols-outlined text-[20px] text-white" style="font-variation-settings:'FILL' 1">account_balance</span>
                    </div>
                    <div>
                        <p class="text-[15px] font-black leading-none text-on-surface">CIHRM <span class="text-secondary">Ghana</span></p>
                        <p class="mt-0.5 text-[9px] font-bold uppercase tracking-[0.2em] text-on-surface-variant/50">Careers Portal</p>
                    </div>
                </Link>
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('login')"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        Staff Login
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-12">

            <!-- Job header -->
            <div class="mb-8">
                <Link href="/" class="inline-flex items-center gap-1 text-[12px] font-semibold text-on-surface-variant/70 hover:text-secondary mb-4">
                    <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                    All openings
                </Link>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-[2rem] font-black tracking-tight text-on-surface leading-tight">{{ j.title }}</h1>
                        <div class="mt-3 flex flex-wrap items-center gap-3 text-[13px] text-on-surface-variant">
                            <StatusBadge :status="j.status" type="recruitment" />
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[16px]">event</span>
                                {{ formatDate(j.closes_at) }}
                            </span>
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[16px]">place</span>
                                Accra, Ghana
                            </span>
                        </div>
                    </div>
                    <button
                        v-if="j.status === 'open' && !submitted"
                        @click="showForm = !showForm"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-3 text-[13px] font-bold text-white shadow-glow-sm hover:-translate-y-px hover:shadow-glow transition-all"
                        style="background:linear-gradient(135deg,#0a2647,#205295)"
                    >
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        Apply Now
                    </button>
                </div>
            </div>

            <div class="grid gap-8 lg:grid-cols-3">

                <!-- Description -->
                <div class="lg:col-span-2 rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-8">
                    <h2 class="text-[14px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-4">About the Role</h2>
                    <p class="text-[14px] text-on-surface whitespace-pre-line leading-relaxed">{{ j.description }}</p>
                </div>

                <!-- Apply form sidebar -->
                <div>
                    <div v-if="submitted" class="rounded-2xl bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-900/40 p-6 text-center">
                        <span class="material-symbols-outlined text-[40px] text-green-600 mb-2">check_circle</span>
                        <h3 class="text-[15px] font-bold text-green-800 dark:text-green-200">Application Received</h3>
                        <p class="mt-2 text-[12px] text-green-700 dark:text-green-300">
                            Thank you for applying. Our recruitment team will review your application and reach out if there's a match.
                        </p>
                    </div>

                    <div
                        v-else-if="showForm && j.status === 'open'"
                        class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 sticky top-24"
                    >
                        <h3 class="text-[14px] font-bold text-on-surface mb-1">Submit Application</h3>
                        <p class="text-[11px] text-on-surface-variant/70 mb-5">Provide your details and upload your CV.</p>

                        <form @submit.prevent="submit" class="space-y-4">
                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Full Name <span class="text-red-500">*</span></label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    placeholder="Your full name"
                                    required
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                    :class="{ 'border-red-400': form.errors.name }"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-[11px] text-red-500">{{ form.errors.name }}</p>
                            </div>

                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Email Address <span class="text-red-500">*</span></label>
                                <input
                                    v-model="form.email"
                                    type="email"
                                    placeholder="you@example.com"
                                    required
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                                    :class="{ 'border-red-400': form.errors.email }"
                                />
                                <p v-if="form.errors.email" class="mt-1 text-[11px] text-red-500">{{ form.errors.email }}</p>
                            </div>

                            <div>
                                <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">CV / Resume <span class="text-on-surface-variant/60 font-normal">(PDF, DOC, DOCX Â· max 5MB)</span></label>
                                <input
                                    type="file"
                                    accept=".pdf,.doc,.docx"
                                    @change="onFileChange"
                                    class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[12px] text-on-surface file:mr-3 file:rounded-lg file:border-0 file:bg-secondary/10 file:px-3 file:py-1.5 file:text-[11px] file:font-semibold file:text-secondary hover:file:bg-secondary/20"
                                    :class="{ 'border-red-400': form.errors.cv }"
                                />
                                <p v-if="form.errors.cv" class="mt-1 text-[11px] text-red-500">{{ form.errors.cv }}</p>
                            </div>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="btn-shimmer mt-2 flex w-full items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-bold text-white disabled:opacity-60"
                                style="background:linear-gradient(135deg,#0a2647,#205295)"
                            >
                                <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                                <span>Submit Application</span>
                            </button>
                        </form>
                    </div>

                    <div v-else-if="j.status !== 'open'" class="rounded-2xl bg-surface-container-low border border-outline-variant/50 p-6 text-center">
                        <span class="material-symbols-outlined text-[32px] text-on-surface-variant/40 mb-2">lock</span>
                        <h3 class="text-[14px] font-bold text-on-surface">Applications Closed</h3>
                        <p class="mt-2 text-[12px] text-on-surface-variant">
                            This posting is no longer accepting applications.
                        </p>
                    </div>

                    <div v-else class="rounded-2xl bg-surface-container-low border border-outline-variant/50 p-6 text-center">
                        <span class="material-symbols-outlined text-[32px] text-secondary mb-2">how_to_reg</span>
                        <h3 class="text-[14px] font-bold text-on-surface">Ready to apply?</h3>
                        <p class="mt-2 text-[12px] text-on-surface-variant">
                            Click "Apply Now" to share your details and CV with our recruitment team.
                        </p>
                    </div>
                </div>
            </div>
        </main>

        <footer class="border-t border-outline-variant/40 mt-12 py-8">
            <p class="text-center text-[11px] text-on-surface-variant/60">Â© 2026 CIHRM Ghana Â· Enterprise HR Management</p>
        </footer>
    </div>
</template>

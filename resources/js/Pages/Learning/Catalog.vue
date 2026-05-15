<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    courses:      Object,
    filters:      Object,
    canManage:    Boolean,
    activeModule: String,
});

const list = computed(() => props.courses?.data ?? []);

// ── Filters ──────────────────────────────────────────────────────────────────
const filters = ref({ ...(props.filters ?? {}) });
let debouncer;
watch(filters, (v) => {
    clearTimeout(debouncer);
    debouncer = setTimeout(() => {
        router.get(route('learning.catalog'), v, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
}, { deep: true });

const categories = [
    { v: '',           l: 'All categories' },
    { v: 'technical',  l: 'Technical' },
    { v: 'leadership', l: 'Leadership' },
    { v: 'compliance', l: 'Compliance' },
    { v: 'wellness',   l: 'Wellness' },
    { v: 'onboarding', l: 'Onboarding' },
    { v: 'soft_skills',l: 'Soft skills' },
    { v: 'other',      l: 'Other' },
];
const formats = [
    { v: '',                l: 'Any format' },
    { v: 'self_paced',      l: 'Self-paced' },
    { v: 'instructor_led',  l: 'Instructor-led' },
    { v: 'blended',         l: 'Blended' },
    { v: 'external',        l: 'External' },
];

// ── Enrol ────────────────────────────────────────────────────────────────────
const enrol = (course) => {
    router.post(route('learning.courses.enrol', course.id), {}, { preserveScroll: true });
};

// ── Create modal (HR/LD) ─────────────────────────────────────────────────────
const showCreate = ref(false);
const createForm = useForm({
    title: '',
    description: '',
    category: 'technical',
    format: 'self_paced',
    provider: '',
    cover_image: '',
    duration_minutes: 60,
    price: 0,
    currency: 'GHS',
    skill_tags: [],
    is_published: true,
});
const tagInput = ref('');
const addTag = () => {
    const t = tagInput.value.trim();
    if (t && !createForm.skill_tags.includes(t) && createForm.skill_tags.length < 20) {
        createForm.skill_tags.push(t);
    }
    tagInput.value = '';
};
const removeTag = (t) => {
    createForm.skill_tags = createForm.skill_tags.filter(x => x !== t);
};
const submitCreate = () => {
    createForm.post(route('learning.courses.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; createForm.reset(); createForm.skill_tags = []; },
    });
};

// ── Quick actions ────────────────────────────────────────────────────────────
const togglePublish = (c) => {
    if (!c.is_published) {
        router.patch(route('learning.courses.publish', c.id), {}, { preserveScroll: true });
    }
};
const removeCourse = (c) => {
    if (!confirm(`Delete "${c.title}"?`)) return;
    router.delete(route('learning.courses.destroy', c.id), { preserveScroll: true });
};

const stats = computed(() => ({
    total:       list.value.length,
    published:   list.value.filter(c => c.is_published).length,
    drafts:      list.value.filter(c => !c.is_published).length,
    enrolments:  list.value.reduce((s, c) => s + (c.enrolled_count ?? 0), 0),
}));
</script>

<template>
    <Head title="Course Catalogue" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70 mb-1">
                        <span>Learning</span>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>Catalogue</span>
                    </div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Course Catalogue</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Browse internal and partner courses. Enrol yourself, then track progress in My Learning.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('learning.my')"
                        class="rounded-xl border border-outline-variant/60 px-4 py-2.5 text-[13px] font-bold text-on-surface-variant hover:bg-surface-container-low flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[16px]">play_lesson</span>
                        My Learning
                    </Link>
                    <button
                        v-if="canManage"
                        @click="showCreate = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm hover:-translate-y-px hover:shadow-glow transition-all"
                        style="background:linear-gradient(135deg,#0051d5,#316bf3)"
                    >
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        New course
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Stat strip (admins only) -->
            <div v-if="canManage" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="s in [
                    { label: 'Total courses', value: stats.total,      icon: 'menu_book',   color: '#0051d5' },
                    { label: 'Published',     value: stats.published,  icon: 'visibility',  color: '#059669' },
                    { label: 'Drafts',        value: stats.drafts,     icon: 'edit_note',   color: '#d97706' },
                    { label: 'Enrolments',    value: stats.enrolments, icon: 'group',       color: '#7c3aed' },
                ]" :key="s.label" class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl" :style="`background:${s.color}1a`">
                            <span class="material-symbols-outlined text-[20px]" :style="`color:${s.color}`">{{ s.icon }}</span>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">{{ s.label }}</p>
                            <p class="text-[22px] font-black text-on-surface leading-none">{{ s.value }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-2">
                <input
                    v-model="filters.search"
                    type="text"
                    placeholder="Search courses, providers, descriptions…"
                    class="rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12.5px] flex-1 min-w-[200px] max-w-md"
                />
                <select v-model="filters.category" class="rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12.5px]">
                    <option v-for="c in categories" :key="c.v" :value="c.v">{{ c.l }}</option>
                </select>
                <select v-model="filters.format" class="rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12.5px]">
                    <option v-for="f in formats" :key="f.v" :value="f.v">{{ f.l }}</option>
                </select>
            </div>

            <!-- Course grid -->
            <div v-if="list.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="c in list"
                    :key="c.id"
                    class="group relative overflow-hidden rounded-2xl border border-outline-variant/40 bg-surface-container-lowest transition-all hover:-translate-y-0.5 hover:shadow-md flex flex-col"
                >
                    <!-- Cover / category band -->
                    <div class="relative h-24 flex items-center justify-center"
                         :style="`background:linear-gradient(135deg, ${c.category_color}, ${c.category_color}cc)`">
                        <img v-if="c.cover_image" :src="c.cover_image" class="absolute inset-0 h-full w-full object-cover opacity-60" />
                        <span class="material-symbols-outlined text-[42px] text-white/85 relative z-10">school</span>
                        <span class="absolute top-2 right-2 rounded-full bg-white/95 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.10em]"
                              :style="`color:${c.category_color}`">{{ c.category_label }}</span>
                        <span v-if="!c.is_published"
                              class="absolute top-2 left-2 rounded-full bg-amber-500/95 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.10em] text-white">
                            Draft
                        </span>
                    </div>

                    <div class="p-5 flex flex-col flex-1">
                        <h3 class="text-[14.5px] font-black text-on-surface leading-tight mb-1 line-clamp-2">{{ c.title }}</h3>
                        <p v-if="c.provider" class="text-[11px] font-semibold text-on-surface-variant/70 mb-2">{{ c.provider }}</p>
                        <p v-if="c.description" class="text-[12px] text-on-surface-variant leading-relaxed line-clamp-2 mb-3">
                            {{ c.description }}
                        </p>

                        <div class="mt-auto flex items-center gap-3 text-[11px] text-on-surface-variant/70 mb-3">
                            <span v-if="c.duration_label" class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">schedule</span>
                                {{ c.duration_label }}
                            </span>
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">{{
                                    c.format === 'instructor_led' ? 'co_present'
                                    : c.format === 'blended' ? 'casino'
                                    : c.format === 'external' ? 'open_in_new'
                                    : 'play_arrow'
                                }}</span>
                                {{ c.format_label }}
                            </span>
                            <span v-if="c.price > 0" class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">payments</span>
                                {{ c.currency }} {{ c.price }}
                            </span>
                            <span v-if="c.enrolled_count != null" class="flex items-center gap-1 ml-auto">
                                <span class="material-symbols-outlined text-[13px]">group</span>
                                {{ c.enrolled_count }}
                            </span>
                        </div>

                        <!-- Skill tags -->
                        <div v-if="c.skill_tags?.length" class="flex flex-wrap gap-1 mb-3">
                            <span v-for="t in c.skill_tags.slice(0, 4)" :key="t"
                                  class="rounded-md bg-secondary/8 px-1.5 py-0.5 text-[10px] font-bold text-secondary">
                                {{ t }}
                            </span>
                            <span v-if="c.skill_tags.length > 4" class="text-[10px] text-on-surface-variant/60">+{{ c.skill_tags.length - 4 }}</span>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <button
                                @click="enrol(c)"
                                :disabled="!c.is_published && !canManage"
                                class="flex-1 rounded-xl px-3 py-2 text-[12px] font-bold text-white disabled:opacity-50"
                                :style="`background:linear-gradient(135deg, ${c.category_color}, ${c.category_color}dd)`"
                            >
                                <span class="material-symbols-outlined text-[14px] mr-1 align-middle">add_task</span>
                                Enrol
                            </button>
                            <button
                                v-if="canManage && !c.is_published"
                                @click="togglePublish(c)"
                                class="rounded-xl border border-emerald-500/30 px-2.5 py-2 text-[12px] font-bold text-emerald-600 hover:bg-emerald-500/8"
                                title="Publish"
                            ><span class="material-symbols-outlined text-[16px]">publish</span></button>
                            <button
                                v-if="canManage"
                                @click="removeCourse(c)"
                                class="rounded-xl border border-outline-variant/60 px-2.5 py-2 text-[12px] font-bold text-red-600 hover:bg-red-500/8 hover:border-red-500/30"
                                title="Delete"
                            ><span class="material-symbols-outlined text-[16px]">delete</span></button>
                        </div>
                    </div>
                </article>
            </div>

            <div v-else class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-10 text-center">
                <span class="material-symbols-outlined text-[42px] text-on-surface-variant/30">school</span>
                <p class="mt-2 text-[14px] font-semibold text-on-surface">No courses match your filters.</p>
                <p v-if="canManage" class="text-[12px] text-on-surface-variant/70">Use "New course" above to seed the catalogue.</p>
            </div>

        </div>

        <!-- Create-course modal -->
        <Teleport to="body">
            <div v-if="showCreate" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 backdrop-blur-sm p-4 pt-10" @click.self="showCreate = false">
                <div class="w-full max-w-xl rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-2xl overflow-hidden">
                    <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                        <h3 class="text-[15px] font-black text-on-surface">New course</h3>
                        <button @click="showCreate = false" class="rounded-lg p-1 hover:bg-surface-container-low"><span class="material-symbols-outlined text-[18px]">close</span></button>
                    </div>
                    <form @submit.prevent="submitCreate" class="space-y-4 p-5">
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Title</label>
                            <input v-model="createForm.title" required maxlength="200" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Description</label>
                            <textarea v-model="createForm.description" rows="3" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Category</label>
                                <select v-model="createForm.category" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]">
                                    <option v-for="c in categories.slice(1)" :key="c.v" :value="c.v">{{ c.l }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Format</label>
                                <select v-model="createForm.format" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]">
                                    <option v-for="f in formats.slice(1)" :key="f.v" :value="f.v">{{ f.l }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Provider</label>
                                <input v-model="createForm.provider" maxlength="120" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Duration (min)</label>
                                <input v-model.number="createForm.duration_minutes" type="number" min="0" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Price</label>
                                <input v-model.number="createForm.price" type="number" step="0.01" min="0" class="w-full rounded-xl border border-outline-variant/60 bg-surface-container-low/40 px-3 py-2 text-[13px]" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70 mb-1.5">Skills granted on completion</label>
                            <div class="flex flex-wrap items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-low/40 p-2">
                                <span v-for="t in createForm.skill_tags" :key="t"
                                      class="inline-flex items-center gap-1 rounded-md bg-secondary/10 px-2 py-0.5 text-[11px] font-bold text-secondary">
                                    {{ t }}
                                    <button type="button" @click="removeTag(t)" class="hover:text-red-500"><span class="material-symbols-outlined text-[12px]">close</span></button>
                                </span>
                                <input
                                    v-model="tagInput"
                                    @keydown.enter.prevent="addTag"
                                    @keydown.,.prevent="addTag"
                                    placeholder="add skill + Enter"
                                    class="flex-1 min-w-[120px] bg-transparent px-1 py-0.5 text-[12px] focus:outline-none"
                                />
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-[12px] text-on-surface-variant cursor-pointer">
                            <input type="checkbox" v-model="createForm.is_published" class="h-4 w-4 rounded border-outline-variant" />
                            Publish immediately
                        </label>
                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button type="button" @click="showCreate = false" class="rounded-xl border border-outline-variant/60 px-4 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container-low">Cancel</button>
                            <button type="submit" :disabled="createForm.processing" class="rounded-xl px-5 py-2 text-[12px] font-bold text-white disabled:opacity-60" style="background:linear-gradient(135deg,#0051d5,#316bf3)">{{ createForm.processing ? 'Saving…' : 'Create course' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref, watch, reactive } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    courses:      Object, // paginated: { data: [], links: [], meta: {} }
    filters:      Object, // { search, category, format }
    canManage:    Boolean,
    activeModule: String,
});

// â”€â”€ Auth permissions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const page = usePage();

// â”€â”€ Filter state â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const localFilters = reactive({
    search:   props.filters?.search   ?? '',
    category: props.filters?.category ?? '',
    format:   props.filters?.format   ?? '',
});

// Active tag chips (client-side only â€” refine against server-provided tags if needed)
const activeTagFilters = ref([]);

const applyFilters = () => {
    router.get(
        route('learning.catalog'),
        {
            search:   localFilters.search   || undefined,
            category: localFilters.category || undefined,
            format:   localFilters.format   || undefined,
        },
        { preserveState: true, replace: true },
    );
};

let searchTimer = null;
watch(() => localFilters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

const clearFilters = () => {
    localFilters.search = '';
    localFilters.category = '';
    localFilters.format = '';
    activeTagFilters.value = [];
    applyFilters();
};

const hasFilters = computed(() =>
    localFilters.search || localFilters.category || localFilters.format || activeTagFilters.value.length,
);

// â”€â”€ Data â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const list = computed(() => props.courses?.data ?? []);

// Collect all unique tags from the visible list for chip cloud
const allTags = computed(() => {
    const tags = new Set();
    list.value.forEach(c => (c.skill_tags ?? []).forEach(t => tags.add(t)));
    return [...tags].slice(0, 12);
});

const toggleTag = (tag) => {
    const idx = activeTagFilters.value.indexOf(tag);
    if (idx === -1) activeTagFilters.value.push(tag);
    else activeTagFilters.value.splice(idx, 1);
};

// Client-side tag filtering on top of server results
const filteredList = computed(() => {
    if (!activeTagFilters.value.length) return list.value;
    return list.value.filter(c =>
        activeTagFilters.value.every(tag => (c.skill_tags ?? []).includes(tag)),
    );
});

// â”€â”€ Stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const stats = computed(() => ({
    total:       props.courses?.meta?.total ?? list.value.length,
    published:   list.value.filter(c => c.is_published).length,
    enrolments:  list.value.reduce((s, c) => s + (c.enrolled_count ?? 0), 0),
    hours:       Math.round(list.value.reduce((s, c) => s + (c.duration_minutes ?? 0), 0) / 60),
}));

// â”€â”€ Course detail slide-panel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const selectedCourse   = ref(null);
const showDetailPanel  = ref(false);

const openCourseDetail = (course) => {
    selectedCourse.value = course;
    showDetailPanel.value = true;
};

// â”€â”€ Enrol â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const enrol = (course, event) => {
    event?.stopPropagation();
    router.post(route('learning.courses.enrol', course.id), {}, { preserveScroll: true });
};

// â”€â”€ Publish / Delete (HR/LD) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const togglePublish = (c, event) => {
    event?.stopPropagation();
    if (!c.is_published) {
        router.patch(route('learning.courses.publish', c.id), {}, { preserveScroll: true });
    }
};

const removeCourse = (c, event) => {
    event?.stopPropagation();
    if (!confirm(`Delete "${c.title}"?`)) return;
    router.delete(route('learning.courses.destroy', c.id), { preserveScroll: true });
};

// â”€â”€ Create Course SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const showCreate = ref(false);

const createForm = useForm({
    title:            '',
    description:      '',
    category:         'technical',
    format:           'self_paced',
    provider:         '',
    cover_image:      '',
    duration_minutes: 60,
    price:            0,
    currency:         'GHS',
    skill_tags:       [],
    is_published:     true,
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
        onSuccess: () => {
            showCreate.value = false;
            createForm.reset();
            createForm.skill_tags = [];
        },
    });
};

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const categories = [
    { v: '',            l: 'All categories' },
    { v: 'technical',   l: 'Technical' },
    { v: 'leadership',  l: 'Leadership' },
    { v: 'compliance',  l: 'Compliance' },
    { v: 'wellness',    l: 'Wellness' },
    { v: 'onboarding',  l: 'Onboarding' },
    { v: 'soft_skills', l: 'Soft Skills' },
    { v: 'other',       l: 'Other' },
];

const formats = [
    { v: '',               l: 'Any format' },
    { v: 'self_paced',     l: 'Self-paced' },
    { v: 'instructor_led', l: 'Instructor-led' },
    { v: 'blended',        l: 'Blended' },
    { v: 'external',       l: 'External' },
];

const categoryIcons = {
    technical:   'terminal',
    leadership:  'supervisor_account',
    compliance:  'gavel',
    wellness:    'self_improvement',
    onboarding:  'waving_hand',
    soft_skills: 'psychology',
    other:       'category',
};

const formatIcon = (fmt) => ({
    instructor_led: 'co_present',
    blended:        'device_hub',
    external:       'open_in_new',
    self_paced:     'play_circle',
}[fmt] ?? 'play_circle');

const difficultyColors = {
    beginner:     { bg: 'rgba(217,119,6,0.12)',   fg: '#92400e' },
    intermediate: { bg: 'rgba(32,82,149,0.12)',     fg: '#205295' },
    advanced:     { bg: 'rgba(124,92,255,0.12)',   fg: '#6d28d9' },
    expert:       { bg: 'rgba(5,150,105,0.12)',    fg: '#065f46' },
};

const difficultyStyle = (d) => difficultyColors[d] ?? { bg: 'rgba(100,116,139,0.10)', fg: '#475569' };
</script>

<template>
    <Head title="Course Catalogue" />
    <AuthenticatedLayout :activeModule="activeModule">

        <!-- â”€â”€ Header â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
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
                        <span class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            {{ stats.total }} courses
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2.5">
                    <Link
                        :href="route('learning.my')"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[18px]">play_lesson</span>
                        My Learning
                    </Link>
                    <button
                        v-if="canManage"
                        @click="showCreate = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                        style="background:linear-gradient(135deg,#205295,#2c74b3)"
                    >
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        Publish Course
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6 animate-reveal-up">

            <!-- â”€â”€ Stat cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div
                    v-for="(s, i) in [
                        { label: 'Total Courses',    value: stats.total,      icon: 'menu_book',  color: '32,82,149'  },
                        { label: 'Published',        value: stats.published,  icon: 'visibility', color: '5,150,105' },
                        { label: 'My Enrolments',    value: stats.enrolments, icon: 'school',     color: '217,119,6' },
                        { label: 'Catalogue Hours',  value: stats.hours,      icon: 'schedule',   color: '15,118,110'},
                    ]"
                    :key="s.label"
                    class="rounded-2xl border bg-surface-container-lowest p-5 shadow-card card-lift"
                    :style="`border-color:rgba(${s.color},0.25);animation-delay:${i * 0.06}s`"
                >
                    <div class="flex items-start justify-between">
                        <div
                            class="h-10 w-10 rounded-xl flex items-center justify-center flex-shrink-0"
                            :style="`background:rgba(${s.color},0.12)`"
                        >
                            <span class="material-symbols-outlined text-[20px]" :style="`color:rgb(${s.color})`" style="font-variation-settings:'FILL' 1">{{ s.icon }}</span>
                        </div>
                    </div>
                    <p class="mt-4 text-[28px] font-black text-on-surface tracking-tight leading-none">{{ s.value }}</p>
                    <p class="mt-1.5 text-[12px] font-semibold text-on-surface-variant">{{ s.label }}</p>
                </div>
            </div>

            <!-- â”€â”€ Filter strip â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-[200px] max-w-sm">
                        <SearchInput v-model="localFilters.search" placeholder="Search courses, providersâ€¦" />
                    </div>

                    <select
                        v-model="localFilters.category"
                        @change="applyFilters"
                        class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option v-for="c in categories" :key="c.v" :value="c.v">{{ c.l }}</option>
                    </select>

                    <select
                        v-model="localFilters.format"
                        @change="applyFilters"
                        class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                    >
                        <option v-for="f in formats" :key="f.v" :value="f.v">{{ f.l }}</option>
                    </select>

                    <button
                        v-if="hasFilters"
                        @click="clearFilters"
                        class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                    >
                        <span class="material-symbols-outlined text-[16px]">close</span>
                        Clear
                    </button>
                </div>

                <!-- Tag chip cloud -->
                <div v-if="allTags.length" class="flex flex-wrap items-center gap-2">
                    <span class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/60">Skills:</span>
                    <button
                        v-for="tag in allTags"
                        :key="tag"
                        @click="toggleTag(tag)"
                        class="rounded-lg px-2.5 py-1 text-[11px] font-bold transition-all"
                        :class="activeTagFilters.includes(tag)
                            ? 'bg-secondary text-white shadow-sm'
                            : 'bg-secondary/8 text-secondary hover:bg-secondary/15'"
                    >{{ tag }}</button>
                </div>
            </div>

            <!-- â”€â”€ Course grid â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div v-if="filteredList.length" class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="(c, i) in filteredList"
                    :key="c.id"
                    class="group relative overflow-hidden rounded-2xl border border-outline-variant/50 bg-surface-container-lowest flex flex-col card-lift cursor-pointer"
                    :style="`animation-delay:${i * 0.04}s`"
                    @click="openCourseDetail(c)"
                >
                    <!-- Hero gradient strip -->
                    <div
                        class="relative h-28 flex items-end p-3 overflow-hidden"
                        style="background:linear-gradient(135deg,rgba(32,82,149,0.80),rgba(44,116,179,0.60))"
                        :style="c.category_color ? `background:linear-gradient(135deg,${c.category_color}cc,${c.category_color}80)` : ''"
                    >
                        <!-- Background cover image if available -->
                        <img
                            v-if="c.cover_image"
                            :src="c.cover_image"
                            class="absolute inset-0 h-full w-full object-cover opacity-30"
                        />

                        <!-- Category icon (large, centered) -->
                        <span
                            class="material-symbols-outlined absolute inset-0 m-auto flex h-fit w-fit text-[44px] text-white/20 select-none"
                            style="font-variation-settings:'FILL' 1"
                        >{{ categoryIcons[c.category] ?? 'school' }}</span>

                        <!-- Top badges -->
                        <div class="absolute top-2.5 left-2.5 right-2.5 flex items-start justify-between z-10">
                            <span
                                class="rounded-lg bg-white/90 px-2 py-0.5 text-[9.5px] font-black uppercase tracking-[0.10em]"
                                :style="`color:${c.category_color ?? '#205295'}`"
                            >{{ c.category_label }}</span>

                            <span
                                v-if="!c.is_published"
                                class="rounded-lg bg-amber-500 px-2 py-0.5 text-[9.5px] font-black uppercase tracking-[0.10em] text-white"
                            >Draft</span>
                        </div>

                        <!-- Duration badge bottom-right -->
                        <span
                            v-if="c.duration_label"
                            class="absolute bottom-2.5 right-2.5 z-10 flex items-center gap-1 rounded-lg bg-black/40 backdrop-blur-sm px-2 py-0.5 text-[10px] font-bold text-white"
                        >
                            <span class="material-symbols-outlined text-[12px]">schedule</span>
                            {{ c.duration_label }}
                        </span>
                    </div>

                    <!-- Card body -->
                    <div class="p-4 flex flex-col flex-1">
                        <!-- Title + provider -->
                        <h3 class="text-[14.5px] font-black text-on-surface leading-tight line-clamp-2">{{ c.title }}</h3>
                        <p v-if="c.provider" class="mt-0.5 font-mono text-[10.5px] text-on-surface-variant/60">{{ c.provider }}</p>

                        <!-- Description -->
                        <p v-if="c.description" class="mt-2 text-[12px] text-on-surface-variant leading-relaxed line-clamp-2">
                            {{ c.description }}
                        </p>

                        <!-- Tag pills -->
                        <div v-if="c.skill_tags?.length" class="mt-3 flex flex-wrap gap-1.5">
                            <span
                                v-for="t in c.skill_tags.slice(0, 4)"
                                :key="t"
                                class="rounded-md bg-secondary/8 px-2 py-0.5 text-[10px] font-bold text-secondary"
                            >{{ t }}</span>
                            <span v-if="c.skill_tags.length > 4" class="text-[10px] text-on-surface-variant/50 py-0.5">+{{ c.skill_tags.length - 4 }}</span>
                        </div>

                        <!-- Footer meta row -->
                        <div class="mt-auto pt-3 flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <!-- Difficulty badge -->
                                <span
                                    v-if="c.difficulty"
                                    class="rounded-md px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.08em]"
                                    :style="`background:${difficultyStyle(c.difficulty).bg};color:${difficultyStyle(c.difficulty).fg}`"
                                >{{ c.difficulty }}</span>

                                <!-- Format icon -->
                                <span class="flex items-center gap-1 text-[11px] text-on-surface-variant/60">
                                    <span class="material-symbols-outlined text-[14px]">{{ formatIcon(c.format) }}</span>
                                    <span class="text-[10.5px]">{{ c.format_label }}</span>
                                </span>
                            </div>

                            <!-- Enrolment count -->
                            <span v-if="c.enrolled_count != null" class="flex items-center gap-1 text-[10.5px] text-on-surface-variant/50">
                                <span class="material-symbols-outlined text-[13px]">group</span>
                                {{ c.enrolled_count }}
                            </span>
                        </div>

                        <!-- CTA footer -->
                        <div class="mt-3 flex items-center gap-2 border-t border-outline-variant/30 pt-3" @click.stop>
                            <button
                                @click="enrol(c, $event)"
                                :disabled="!c.is_published && !canManage"
                                class="btn-shimmer flex-1 rounded-xl px-3 py-2 text-[12px] font-bold text-white disabled:opacity-50 transition-all"
                                style="background:linear-gradient(135deg,#205295,#2c74b3)"
                            >
                                <span class="material-symbols-outlined text-[14px] mr-1 align-middle">add_task</span>
                                {{ c.my_enrolment ? 'Continue' : 'Enrol' }}
                            </button>

                            <button
                                v-if="canManage && !c.is_published"
                                @click="togglePublish(c, $event)"
                                class="rounded-xl border border-emerald-500/30 px-2.5 py-2 text-[12px] font-bold text-emerald-600 hover:bg-emerald-500/8 transition-colors"
                                title="Publish"
                            >
                                <span class="material-symbols-outlined text-[16px]">publish</span>
                            </button>

                            <button
                                v-if="canManage"
                                @click="removeCourse(c, $event)"
                                class="rounded-xl border border-outline-variant/60 px-2.5 py-2 text-[12px] font-bold text-red-600 hover:bg-red-500/8 hover:border-red-500/30 transition-colors"
                                title="Delete"
                            >
                                <span class="material-symbols-outlined text-[16px]">delete</span>
                            </button>
                        </div>
                    </div>
                </article>
            </div>

            <!-- Empty state -->
            <div v-else class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-12">
                <EmptyState
                    title="No courses match your filters"
                    description="Try adjusting your search or filters, or ask your L&D team to publish new courses."
                    icon="school"
                >
                    <template #action>
                        <button
                            v-if="canManage"
                            @click="showCreate = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#205295,#2c74b3)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            Publish Course
                        </button>
                    </template>
                </EmptyState>
            </div>

            <!-- Pagination -->
            <div v-if="courses?.links?.length > 3" class="flex items-center justify-between rounded-2xl bg-surface-container-lowest border border-outline-variant/50 px-4 py-3 shadow-card">
                <p class="text-[12px] text-on-surface-variant">
                    Showing
                    <span class="font-semibold text-on-surface">{{ courses.meta?.from }}</span>
                    â€“
                    <span class="font-semibold text-on-surface">{{ courses.meta?.to }}</span>
                    of
                    <span class="font-semibold text-on-surface">{{ courses.meta?.total }}</span>
                </p>
                <Pagination :links="courses.links" />
            </div>
        </div>

        <!-- â”€â”€ Course Detail SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel
            :open="showDetailPanel"
            :title="selectedCourse?.title ?? 'Course Detail'"
            size="lg"
            @close="showDetailPanel = false; selectedCourse = null"
        >
            <div v-if="selectedCourse" class="p-6 space-y-6">

                <!-- Hero -->
                <div
                    class="relative h-32 rounded-2xl overflow-hidden flex items-end p-4"
                    :style="`background:linear-gradient(135deg,${selectedCourse.category_color ?? '#205295'}cc,${selectedCourse.category_color ?? '#2c74b3'}80)`"
                >
                    <img v-if="selectedCourse.cover_image" :src="selectedCourse.cover_image" class="absolute inset-0 h-full w-full object-cover opacity-30" />
                    <div class="relative z-10">
                        <span class="rounded-lg bg-white/90 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.10em]"
                              :style="`color:${selectedCourse.category_color ?? '#205295'}`">
                            {{ selectedCourse.category_label }}
                        </span>
                    </div>
                    <span v-if="selectedCourse.duration_label"
                          class="absolute bottom-3 right-3 flex items-center gap-1 rounded-lg bg-black/40 backdrop-blur-sm px-2.5 py-1 text-[11px] font-bold text-white">
                        <span class="material-symbols-outlined text-[13px]">schedule</span>
                        {{ selectedCourse.duration_label }}
                    </span>
                </div>

                <!-- Meta chips -->
                <div class="flex flex-wrap items-center gap-2">
                    <span v-if="selectedCourse.format_label" class="flex items-center gap-1.5 rounded-lg bg-surface-container-low px-2.5 py-1.5 text-[11px] font-semibold text-on-surface-variant">
                        <span class="material-symbols-outlined text-[14px]">{{ formatIcon(selectedCourse.format) }}</span>
                        {{ selectedCourse.format_label }}
                    </span>
                    <span v-if="selectedCourse.difficulty"
                          class="rounded-lg px-2.5 py-1.5 text-[11px] font-bold uppercase tracking-[0.08em]"
                          :style="`background:${difficultyStyle(selectedCourse.difficulty).bg};color:${difficultyStyle(selectedCourse.difficulty).fg}`">
                        {{ selectedCourse.difficulty }}
                    </span>
                    <span v-if="selectedCourse.price > 0" class="flex items-center gap-1.5 rounded-lg bg-amber-500/10 px-2.5 py-1.5 text-[11px] font-bold text-amber-700">
                        <span class="material-symbols-outlined text-[14px]">payments</span>
                        {{ selectedCourse.currency }} {{ selectedCourse.price }}
                    </span>
                    <span v-else class="rounded-lg bg-emerald-500/10 px-2.5 py-1.5 text-[11px] font-bold text-emerald-700">Free</span>
                </div>

                <!-- Description -->
                <div v-if="selectedCourse.description">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-2">About this course</p>
                    <p class="text-[13px] text-on-surface-variant leading-relaxed">{{ selectedCourse.description }}</p>
                </div>

                <!-- Provider -->
                <div v-if="selectedCourse.provider" class="flex items-center gap-3 rounded-xl border border-outline-variant/60 bg-surface-container/40 p-3.5">
                    <div class="h-9 w-9 rounded-lg bg-secondary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[18px] text-secondary">business</span>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/60">Provider</p>
                        <p class="text-[13px] font-bold text-on-surface">{{ selectedCourse.provider }}</p>
                    </div>
                </div>

                <!-- Skill tags -->
                <div v-if="selectedCourse.skill_tags?.length">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-2.5">Skills you'll gain</p>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="t in selectedCourse.skill_tags"
                            :key="t"
                            class="rounded-lg bg-secondary/8 px-3 py-1 text-[11px] font-bold text-secondary"
                        >{{ t }}</span>
                    </div>
                </div>

                <!-- Prerequisites -->
                <div v-if="selectedCourse.prerequisites?.length">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-2.5">Prerequisites</p>
                    <ul class="space-y-1.5">
                        <li v-for="p in selectedCourse.prerequisites" :key="p"
                            class="flex items-start gap-2 text-[12px] text-on-surface-variant">
                            <span class="material-symbols-outlined text-[14px] text-on-surface-variant/40 mt-0.5">check_circle</span>
                            {{ p }}
                        </li>
                    </ul>
                </div>
            </div>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showDetailPanel = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >Close</button>
                    <button
                        v-if="selectedCourse"
                        @click="enrol(selectedCourse, $event); showDetailPanel = false"
                        :disabled="!selectedCourse.is_published && !canManage"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#205295,#2c74b3)"
                    >
                        <span class="material-symbols-outlined text-[16px]">add_task</span>
                        Enrol Now
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- â”€â”€ Publish Course SlidePanel â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <SlidePanel
            :open="showCreate"
            title="Publish New Course"
            size="lg"
            @close="showCreate = false"
        >
            <form @submit.prevent="submitCreate" class="space-y-5 p-6">

                <!-- Course identity -->
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Course Title <span class="text-red-500">*</span></label>
                    <input
                        v-model="createForm.title"
                        type="text"
                        required
                        maxlength="200"
                        placeholder="e.g. Introduction to Procurement Management"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': createForm.errors.title }"
                    />
                    <p v-if="createForm.errors.title" class="mt-1 text-[11px] text-red-500">{{ createForm.errors.title }}</p>
                </div>

                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Description</label>
                    <textarea
                        v-model="createForm.description"
                        rows="3"
                        placeholder="What will learners gain from this course?"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                    />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Category <span class="text-red-500">*</span></label>
                        <select
                            v-model="createForm.category"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option v-for="c in categories.slice(1)" :key="c.v" :value="c.v">{{ c.l }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Format <span class="text-red-500">*</span></label>
                        <select
                            v-model="createForm.format"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        >
                            <option v-for="f in formats.slice(1)" :key="f.v" :value="f.v">{{ f.l }}</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Provider</label>
                        <input
                            v-model="createForm.provider"
                            type="text"
                            maxlength="120"
                            placeholder="GIFMIS Academy"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Duration (min)</label>
                        <input
                            v-model.number="createForm.duration_minutes"
                            type="number"
                            min="0"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Price (GHS)</label>
                        <input
                            v-model.number="createForm.price"
                            type="number"
                            step="0.01"
                            min="0"
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        />
                    </div>
                </div>

                <!-- Skill tags -->
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Skills Granted on Completion</label>
                    <div class="flex flex-wrap items-center gap-1.5 rounded-xl border border-outline-variant bg-surface-container-low p-2.5 min-h-[42px]">
                        <span
                            v-for="t in createForm.skill_tags"
                            :key="t"
                            class="inline-flex items-center gap-1 rounded-lg bg-secondary/10 px-2.5 py-1 text-[11px] font-bold text-secondary"
                        >
                            {{ t }}
                            <button type="button" @click="removeTag(t)" class="hover:text-red-500 transition-colors">
                                <span class="material-symbols-outlined text-[12px]">close</span>
                            </button>
                        </span>
                        <input
                            v-model="tagInput"
                            @keydown.enter.prevent="addTag"
                            @keydown.,.prevent="addTag"
                            placeholder="Add skill + Enter"
                            class="flex-1 min-w-[120px] bg-transparent px-1 py-0.5 text-[12px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none"
                        />
                    </div>
                    <p class="mt-1 text-[11px] text-on-surface-variant/60">Press Enter or comma to add a skill tag</p>
                </div>

                <!-- Publish toggle -->
                <label class="flex items-center gap-3 rounded-xl border border-outline-variant/60 bg-surface-container/40 px-4 py-3 cursor-pointer">
                    <input type="checkbox" v-model="createForm.is_published" class="h-4 w-4 rounded border-outline-variant accent-secondary" />
                    <div>
                        <p class="text-[13px] font-semibold text-on-surface">Publish immediately</p>
                        <p class="text-[11px] text-on-surface-variant/70">Employees will be able to see and enrol in this course right away.</p>
                    </div>
                </label>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showCreate = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >Cancel</button>
                    <button
                        @click="submitCreate"
                        :disabled="createForm.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#205295,#2c74b3)"
                    >
                        <span v-if="createForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        {{ createForm.processing ? 'Publishingâ€¦' : 'Publish Course' }}
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>

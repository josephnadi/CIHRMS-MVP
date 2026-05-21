<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import CategoryBadge from '@/Components/Incidents/CategoryBadge.vue';
import StatusPill   from '@/Components/Incidents/StatusPill.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    reports:      Object,
    reviewers:    { type: Array, default: () => [] },
    filters:      { type: Object, default: () => ({}) },
    activeModule: String,
});

const localFilters = reactive({
    category: props.filters?.category ?? '',
    status:   props.filters?.status   ?? '',
    q:        props.filters?.q        ?? '',
});

const applyFilters = () => router.get(route('incidents.index'), {
    category: localFilters.category || undefined,
    status:   localFilters.status   || undefined,
    q:        localFilters.q        || undefined,
}, { preserveState: true, replace: true });

let qTimer = null;
watch(() => localFilters.q, () => {
    clearTimeout(qTimer);
    qTimer = setTimeout(applyFilters, 380);
});

const showNew = ref(false);

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
        showNew.value = true;
        params.delete('new');
        const qs = params.toString();
        window.history.replaceState({}, '', window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash);
    }
});

const form = useForm({
    category: 'grievance',
    title:    '',
    body:     '',
    attachments: [],
});

const submit = () => {
    form.post(route('incidents.store'), {
        preserveState:  true,
        preserveScroll: true,
        forceFormData:  true,
        onSuccess: () => { form.reset(); showNew.value = false; },
    });
};

const formatRel = (iso) => {
    if (!iso) return '—';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60)        return 'just now';
    if (diff < 3600)      return `${Math.floor(diff / 60)} min ago`;
    if (diff < 86400)     return `${Math.floor(diff / 3600)} h ago`;
    return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const reports = computed(() => props.reports?.data ?? []);
</script>

<template>
    <Head title="Incident Reports" />
    <Teleport to="#page-header-mount" defer>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">report</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">INSTITUTIONAL VOICE</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Incident Reports</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">Private channel for grievances, suggestions, and safety concerns.</p>
            </div>
            <button @click="showNew = true"
                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow"
                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">edit_note</span>
                New Report
            </button>
        </div>
    </Teleport>

    <div data-page-root="true">
        <div class="grid lg:grid-cols-[240px_1fr] gap-6">
            <aside class="space-y-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70 mb-2">Category</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="c in [['','All'],['grievance','Grievance'],['improvement','Improvement'],['safety','Safety'],['other','Other']]"
                                :key="c[0]"
                                @click="localFilters.category = c[0]; applyFilters()"
                                :class="['rounded-full border px-3 py-1 text-[11px] font-semibold',
                                         localFilters.category === c[0]
                                            ? 'bg-secondary/10 border-secondary/30 text-secondary'
                                            : 'bg-surface-container-lowest border-outline-variant text-on-surface-variant hover:bg-surface-container']">
                            {{ c[1] }}
                        </button>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70 mb-2">Status</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="s in [['','All'],['open','Open'],['in_review','In Review'],['closed','Closed']]"
                                :key="s[0]"
                                @click="localFilters.status = s[0]; applyFilters()"
                                :class="['rounded-full border px-3 py-1 text-[11px] font-semibold',
                                         localFilters.status === s[0]
                                            ? 'bg-secondary/10 border-secondary/30 text-secondary'
                                            : 'bg-surface-container-lowest border-outline-variant text-on-surface-variant hover:bg-surface-container']">
                            {{ s[1] }}
                        </button>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70 mb-2">Search</p>
                    <input v-model="localFilters.q" placeholder="Title…"
                           class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                </div>
            </aside>

            <main class="space-y-3">
                <Link v-for="r in reports" :key="r.id" :href="route('incidents.show', r.id)"
                      class="block rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card hover:-translate-y-px hover:shadow-lifted transition-all">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <CategoryBadge :category="r.category" />
                                <StatusPill :status="r.status" />
                            </div>
                            <h3 class="mt-2 text-[14px] font-bold text-on-surface truncate">{{ r.title }}</h3>
                            <p class="mt-1 text-[12px] text-on-surface-variant line-clamp-2">{{ r.body }}</p>
                        </div>
                        <span class="text-[11px] text-on-surface-variant/60 tabular-nums whitespace-nowrap">{{ formatRel(r.created_at) }}</span>
                    </div>
                </Link>
                <div v-if="reports.length === 0" class="rounded-2xl border border-dashed border-outline-variant/50 bg-surface-container-lowest p-12 text-center text-[13px] text-on-surface-variant">
                    No reports in this view.
                </div>
            </main>
        </div>

        <SlidePanel :open="showNew" title="New Incident Report" size="lg" @close="showNew = false">
            <form @submit.prevent="submit" class="space-y-5 p-6" enctype="multipart/form-data">
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Category</label>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="c in [['grievance','Grievance'],['improvement','Improvement Suggestion'],['safety','Workplace Safety'],['other','Other']]"
                               :key="c[0]" class="cursor-pointer">
                            <input type="radio" v-model="form.category" :value="c[0]" :aria-label="c[1]" class="sr-only" />
                            <span :class="['inline-flex rounded-full border px-3 py-1.5 text-[12px] font-semibold',
                                           form.category === c[0]
                                              ? 'bg-secondary/10 border-secondary/40 text-secondary'
                                              : 'bg-surface-container-lowest border-outline-variant text-on-surface-variant']">
                                {{ c[1] }}
                            </span>
                        </label>
                    </div>
                </div>
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Title</label>
                    <input v-model="form.title" required minlength="6" maxlength="180"
                           class="w-full rounded-xl border border-outline-variant px-4 py-2.5 text-[13px]" />
                    <p v-if="form.errors.title" class="mt-1 text-[11px] text-red-500">{{ form.errors.title }}</p>
                </div>
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Details</label>
                    <textarea v-model="form.body" rows="8" required minlength="20" maxlength="10000"
                              class="w-full rounded-xl border border-outline-variant px-4 py-2.5 text-[13px]" />
                    <p v-if="form.errors.body" class="mt-1 text-[11px] text-red-500">{{ form.errors.body }}</p>
                </div>
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Attachments (optional, up to 3, 10 MB each)</label>
                    <input type="file" multiple
                           @change="(e) => { form.attachments = Array.from(e.target.files).slice(0, 3); }"
                           accept=".pdf,.png,.jpg,.jpeg,.doc,.docx"
                           class="text-[12px]" />
                </div>
            </form>
            <template #footer>
                <button type="button" @click="showNew = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant">Cancel</button>
                <button @click="submit" :disabled="form.processing"
                        class="btn-shimmer rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                    {{ form.processing ? 'Submitting…' : 'Submit Privately' }}
                </button>
            </template>
        </SlidePanel>
    </div>
</template>

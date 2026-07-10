<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import CategoryBadge from '@/Components/Incidents/CategoryBadge.vue';
import StatusPill   from '@/Components/Incidents/StatusPill.vue';
import MessageBubble from '@/Components/Incidents/MessageBubble.vue';
import AttachmentChip from '@/Components/Incidents/AttachmentChip.vue';
import InputError from '@/Components/InputError.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    report:    { type: Object, required: true },
    reviewers: { type: Array, default: () => [] },
    activeModule: String,
});

const page = usePage();
const me = computed(() => page.props.auth?.user);

const r = computed(() => props.report?.data ?? props.report);
const isSubmitter = computed(() => r.value?.submitter?.id !== undefined && me.value?.id && r.value.submitter.id === me.value.id);

const reply = useForm({ body: '', attachments: [] });
const sendReply = () => {
    reply.post(route('incidents.messages.store', r.value.id), {
        preserveState:  true,
        preserveScroll: true,
        forceFormData:  true,
        onSuccess: () => reply.reset(),
    });
};

const showCloseModal = ref(false);
const closeForm = useForm({ resolution_note: '' });
const doClose = () => closeForm.post(route('incidents.close', r.value.id), {
    preserveScroll: true,
    onSuccess: () => { showCloseModal.value = false; closeForm.reset(); },
});
const doReopen = () => {
    if (! window.confirm('Reopen this report?')) return;
    useForm({}).post(route('incidents.reopen', r.value.id), { preserveScroll: true });
};

const showAssign = ref(false);
const assignForm = useForm({ user_id: '' });
const doAssign = (userId) => {
    assignForm.user_id = userId;
    assignForm.post(route('incidents.assign', r.value.id), {
        preserveScroll: true,
        onSuccess: () => assignForm.reset(),
    });
};
const doUnassign = (userId) => {
    if (! window.confirm('Remove this reviewer? They will lose access immediately.')) return;
    useForm({}).delete(route('incidents.unassign', { report: r.value.id, user: userId }), { preserveScroll: true });
};

const assigneeIds = computed(() => new Set((r.value.assignees ?? []).map(a => a.id)));
</script>

<template>
    <Head :title="r.title" />
    <Teleport to="#page-header-mount" defer>
        <div class="space-y-2">
            <Link :href="route('incidents.index')" class="inline-flex items-center gap-1 text-[12px] font-semibold text-on-surface-variant/70 hover:text-secondary">
                <span class="material-symbols-outlined text-[14px]">arrow_back</span> Back to reports
            </Link>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <CategoryBadge :category="r.category" />
                        <StatusPill :status="r.status" />
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">{{ r.title }}</h1>
                    <p class="mt-1 text-[12px] text-on-surface-variant">
                        Submitted {{ new Date(r.created_at).toLocaleString('en-GB') }}
                        <span v-if="!isSubmitter && r.submitter"> · by {{ r.submitter.name }}</span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="showAssign = true"
                            class="rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2 text-[13px] font-semibold text-on-surface hover:bg-surface-container">
                        Assign
                    </button>
                    <button v-if="r.status !== 'closed'" @click="showCloseModal = true"
                            class="rounded-xl bg-green-600 text-white px-4 py-2 text-[13px] font-bold hover:bg-green-700">
                        Close
                    </button>
                    <button v-if="r.status === 'closed'" @click="doReopen"
                            class="rounded-xl bg-amber-500 text-white px-4 py-2 text-[13px] font-bold hover:bg-amber-600">
                        Reopen
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <div data-page-root="true">
        <div class="grid lg:grid-cols-[1fr_320px] gap-6">
            <section class="space-y-4">
                <article class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card">
                    <p class="text-[13px] text-on-surface whitespace-pre-wrap">{{ r.body }}</p>
                    <div v-if="r.attachments?.length" class="mt-4 flex flex-wrap gap-2">
                        <AttachmentChip v-for="a in r.attachments" :key="a.id" :attachment="a" />
                    </div>
                </article>

                <div class="space-y-3">
                    <MessageBubble v-for="m in r.messages" :key="m.id" :message="m" :is-own="m.author?.id === me?.id" />
                </div>

                <form v-if="r.status !== 'closed'" @submit.prevent="sendReply"
                      class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card space-y-3"
                      enctype="multipart/form-data">
                    <textarea aria-label="Body" v-model="reply.body" rows="3" required maxlength="10000"
                              placeholder="Write a reply…"
                              class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] resize-none" />
                    <InputError :message="reply.errors.body" />
                    <div class="flex items-center justify-between gap-3">
                        <input type="file" multiple
                               @change="(e) => { reply.attachments = Array.from(e.target.files).slice(0, 3); }"
                               accept=".pdf,.png,.jpg,.jpeg,.doc,.docx"
                               class="text-[11px]" />
                        <button :disabled="reply.processing"
                                class="rounded-xl px-4 py-2 text-[12px] font-bold text-white shadow-glow-sm disabled:opacity-60"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                            {{ reply.processing ? 'Posting…' : 'Post Reply' }}
                        </button>
                    </div>
                </form>
                <p v-else class="text-center text-[12px] text-on-surface-variant/60">This report is closed. The thread is locked.</p>
            </section>

            <aside class="space-y-4">
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70">Status</p>
                    <div class="mt-2"><StatusPill :status="r.status" /></div>
                    <p v-if="r.resolution_note" class="mt-3 text-[12px] text-on-surface-variant whitespace-pre-wrap">{{ r.resolution_note }}</p>
                </div>
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70 mb-2">Assignees</p>
                    <ul class="space-y-2">
                        <li v-for="a in r.assignees" :key="a.id" class="flex items-center justify-between gap-2">
                            <div>
                                <p class="text-[13px] font-bold text-on-surface">{{ a.name }}</p>
                                <p class="text-[11px] text-on-surface-variant/60">{{ a.role }}</p>
                            </div>
                            <button @click="doUnassign(a.id)" class="text-[11px] text-red-600 font-semibold hover:underline">Remove</button>
                        </li>
                        <li v-if="r.assignees?.length === 0" class="text-[12px] text-on-surface-variant/60 italic">No reviewers yet.</li>
                    </ul>
                </div>
            </aside>
        </div>

        <SlidePanel :open="showAssign" title="Assign Reviewer" size="md" @close="showAssign = false">
            <div class="p-6 space-y-2">
                <p class="text-[12px] text-on-surface-variant">Only users with the <code class="text-secondary">incidents.review</code> permission appear here.</p>
                <ul class="divide-y divide-outline-variant/30">
                    <li v-for="u in reviewers" :key="u.id" class="flex items-center justify-between py-3">
                        <div>
                            <p class="text-[13px] font-bold text-on-surface">{{ u.name }}</p>
                            <p class="text-[11px] text-on-surface-variant/60">{{ u.role }}</p>
                        </div>
                        <button v-if="!assigneeIds.has(u.id)"
                                @click="doAssign(u.id)"
                                class="rounded-lg border border-secondary/30 bg-secondary/10 px-3 py-1.5 text-[12px] font-bold text-secondary">Assign</button>
                        <span v-else class="text-[12px] font-semibold text-green-600">✓ Assigned</span>
                    </li>
                    <li v-if="reviewers.length === 0" class="py-6 text-center text-[12px] text-on-surface-variant/60 italic">
                        No users hold the incidents.review permission yet.
                    </li>
                </ul>
            </div>
        </SlidePanel>

        <SlidePanel :open="showCloseModal" title="Close Report" size="md" @close="showCloseModal = false">
            <div class="p-6 space-y-3">
                <label class="text-[12px] font-semibold text-on-surface-variant block">Resolution note</label>
                <textarea aria-label="Resolution note" v-model="closeForm.resolution_note" rows="4" maxlength="5000"
                          placeholder="Summarise how this report was resolved…"
                          class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] resize-none" />
                <InputError :message="closeForm.errors.resolution_note" />
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" @click="showCloseModal = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="doClose" :disabled="closeForm.processing"
                            class="rounded-xl bg-green-600 text-white px-4 py-2 text-[13px] font-bold hover:bg-green-700 disabled:opacity-60">
                        {{ closeForm.processing ? 'Closing…' : 'Close Report' }}
                    </button>
                </div>
            </div>
        </SlidePanel>
    </div>
</template>

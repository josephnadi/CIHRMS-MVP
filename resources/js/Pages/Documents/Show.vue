<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Viewer from '@/Components/Documents/Viewer.vue';
import AnnotationLayer from '@/Components/Documents/AnnotationLayer.vue';
import SignaturePad from '@/Components/Documents/SignaturePad.vue';
import StampPicker from '@/Components/Documents/StampPicker.vue';
import RoutingSlipPanel from '@/Components/Documents/RoutingSlipPanel.vue';
import TimelineRail from '@/Components/Documents/TimelineRail.vue';
import RecipientPicker from '@/Components/Documents/RecipientPicker.vue';

defineOptions({ layout: AuthenticatedLayout });

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);

const props = defineProps({
    document:      Object,
    downloadUrls:  Object,
    activeModule:  String,
});

const D = computed(() => props.document.data ?? props.document);

// Signed-URL downloads minted server-side (5-min TTL). The `documents.download`
// route is protected by `signed` middleware, so we must use the URLs the
// controller hands us — calling route() directly here would produce 403s.
const docUrl = computed(() => props.downloadUrls?.original ?? '#');
const downloadBurnedUrl = computed(() => props.downloadUrls?.burned ?? '#');

const pageSize  = ref({ width: 0, height: 0 });
const currentPage = ref(1);
const pendingAnnotation = ref(null);
const showSigPad   = ref(false);
const showStamp    = ref(false);

const showRouteModal = ref(false);
const routeForm = useForm({ recipients: [{ user_id: null, action_required: 'sign' }] });

function addRecipient() { routeForm.recipients.push({ user_id: null, action_required: 'sign' }); }
function removeRecipient(i) { routeForm.recipients.splice(i, 1); }

function submitRoute() {
    routeForm.post(route('documents.route', D.value.uuid), {
        onSuccess: () => { showRouteModal.value = false; routeForm.reset(); },
    });
}

function placeAnnotation({ x_pct, y_pct, page: pageNo }) {
    if (! pendingAnnotation.value) return;
    const dimensions = pendingAnnotation.value.type === 'stamp'
        ? { w_pct: 18, h_pct: 6 }
        : { w_pct: 22, h_pct: 8 };

    router.post(route('documents.annotations.store', D.value.uuid), {
        type:  pendingAnnotation.value.type,
        page:  pageNo,
        x_pct, y_pct,
        ...dimensions,
        data:  pendingAnnotation.value.data,
    }, { preserveScroll: true, onSuccess: () => { pendingAnnotation.value = null; } });
}

function onSigned({ png_base64 }) {
    pendingAnnotation.value = { type: 'signature', data: { png_base64 } };
    showSigPad.value = false;
}

function onStamp({ text, color }) {
    pendingAnnotation.value = { type: 'stamp', data: { text, color } };
    showStamp.value = false;
}

const actForm = useForm({ decision: '', comment: '' });
function act(routeId, decision) {
    actForm.decision = decision;
    if (decision === 'reject' && ! actForm.comment) {
        actForm.comment = prompt('Reason for rejection?') ?? '';
        if (! actForm.comment) return;
    }
    actForm.post(route('documents.routes.act', { document: D.value.uuid, route: routeId }), {
        preserveScroll: true,
        onSuccess: () => { actForm.reset(); },
    });
}

function withdraw() {
    if (! confirm('Withdraw this document from review?')) return;
    router.post(route('documents.withdraw', D.value.uuid));
}

function downloadBurned() {
    window.open(downloadBurnedUrl.value, '_blank');
}

// Open the signed burned PDF in a new tab. Browsers' built-in PDF viewer
// includes a Print button; users can hit Ctrl+P or use that toolbar. This
// avoids the fragile iframe.print() route which behaves differently across
// Chrome / Firefox / Safari for embedded PDFs.
function printDocument() {
    const target = window.open(downloadBurnedUrl.value, '_blank');
    if (! target) return;
    // Best-effort auto-trigger of the print dialog once the PDF loads.
    // If the browser blocks (e.g., for cross-origin PDFs), the user can
    // still click Print in the built-in viewer.
    target.addEventListener?.('load', () => {
        try { target.focus(); target.print(); } catch (e) { /* user prints manually */ }
    });
}

const myActiveRoute = computed(() =>
    D.value.routes?.find(r => r.status === 'in_progress' && r.to_user?.id === currentUserId.value)
);

const canAnnotate = computed(() => D.value.status === 'draft' || !! myActiveRoute.value);
const canRoute    = computed(() => D.value.status === 'draft' && D.value.owner?.id === currentUserId.value);
const canWithdraw = computed(() => D.value.status === 'in_review' && D.value.owner?.id === currentUserId.value);

// ── Documents v2 — Phase 1: Edit / Delete / Share ────────────────────────────
const userPerms = computed(() => page.props.auth?.permissions ?? []);
const can = (slug) => userPerms.value.includes('*') || userPerms.value.includes(slug);

const isOwner = computed(() => D.value.owner?.id === currentUserId.value);
const canEdit = computed(() => isOwner.value && D.value.status === 'draft');
const canDelete = computed(() => (isOwner.value && D.value.status === 'draft') || can('documents.manage'));
const canShare  = computed(() => isOwner.value || can('documents.manage'));
const canShareOrg = computed(() => can('documents.share_organization') || can('documents.manage'));
const isSensitive = computed(() => ['confidential', 'restricted'].includes(D.value.confidentiality));

const showEditPanel = ref(false);
const editForm = useForm({
    title: '', description: '', confidentiality: 'internal', tags: [],
});

function openEdit() {
    editForm.title           = D.value.title ?? '';
    editForm.description     = D.value.description ?? '';
    editForm.confidentiality = D.value.confidentiality ?? 'internal';
    editForm.tags            = D.value.tags ?? [];
    showEditPanel.value = true;
}
function submitEdit() {
    editForm.patch(route('documents.update', D.value.uuid), {
        preserveScroll: true,
        onSuccess: () => { showEditPanel.value = false; },
    });
}

function destroyDocument() {
    if (! confirm(`Delete "${D.value.title}" (${D.value.ref_no})? This is reversible by an admin.`)) return;
    router.delete(route('documents.destroy', D.value.uuid));
}

const showShareModal = ref(false);
const shareForm = useForm({
    audience_type: 'user', audience_id: null, expires_at: '',
});
const shares = computed(() => D.value.shares ?? []);

function openShare() {
    shareForm.audience_type = 'user';
    shareForm.audience_id   = null;
    shareForm.expires_at    = '';
    showShareModal.value = true;
}
function submitShare() {
    shareForm.post(route('documents.shares.store', D.value.uuid), {
        preserveScroll: true,
        onSuccess: () => { shareForm.reset(); shareForm.audience_type = 'user'; },
    });
}
function revokeShare(shareId) {
    if (! confirm('Revoke this share?')) return;
    router.delete(route('documents.shares.destroy', { document: D.value.uuid, share: shareId }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="D.title" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
                <div class="space-y-2">
                    <nav class="flex items-center gap-1.5 text-[12px] font-semibold text-on-surface-variant/60">
                        <Link :href="route('documents.index')" class="hover:text-secondary">Documents</Link>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span class="text-on-surface">{{ D.ref_no }}</span>
                    </nav>
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">description</span>
                                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">{{ D.confidentiality?.toUpperCase() }} · {{ D.status_label?.toUpperCase() }}</p>
                            </div>
                            <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">{{ D.title }}</h1>
                            <p class="mt-1 text-[12px] text-on-surface-variant">{{ D.ref_no }} · owner {{ D.owner?.name }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <a :href="docUrl" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-black flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">download</span> Original
                            </a>
                            <button @click="downloadBurned" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-black flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">picture_as_pdf</span> Burned PDF
                            </button>
                            <button @click="printDocument" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-black flex items-center gap-2"
                                    title="Open the burned PDF and trigger the browser print dialog">
                                <span class="material-symbols-outlined text-[16px]">print</span> Print
                            </button>
                            <button v-if="canShare" @click="openShare"
                                    class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-black flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">share</span> Share
                            </button>
                            <button v-if="canEdit" @click="openEdit"
                                    class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-black flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">edit</span> Edit
                            </button>
                            <button v-if="canDelete" @click="destroyDocument"
                                    class="rounded-xl border border-rose-300 text-rose-700 px-3 py-2 text-[12px] font-black flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">delete</span> Delete
                            </button>
                            <button v-if="canRoute" @click="showRouteModal = true"
                                    class="btn-shimmer flex items-center gap-2 rounded-xl px-3 py-2 text-[12px] font-black text-white shadow-glow-sm"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                <span class="material-symbols-outlined text-[17px]">send</span> Route
                            </button>
                            <button v-if="canWithdraw" @click="withdraw"
                                    class="rounded-xl border border-rose-300 text-rose-700 px-3 py-2 text-[12px] font-black">
                                Withdraw
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>

            <div class="grid lg:grid-cols-[1fr_320px] gap-6">
                <section class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
                    <div v-if="canAnnotate" class="mb-3 flex flex-wrap items-center gap-2">
                        <button @click="showSigPad = true" class="rounded-lg border border-outline-variant px-3 py-1.5 text-[12px] font-black flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[15px]">gesture</span> Add signature
                        </button>
                        <button @click="showStamp = true" class="rounded-lg border border-outline-variant px-3 py-1.5 text-[12px] font-black flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[15px]">verified</span> Add stamp
                        </button>
                        <span v-if="pendingAnnotation" class="text-[11px] font-bold text-amber-700">
                            Click on the page where you want to place the {{ pendingAnnotation.type }}.
                        </span>
                    </div>
                    <Viewer :src="docUrl" :mime="D.current_version?.mime"
                            @page-size="(s) => pageSize = s"
                            @page-changed="(p) => currentPage = p">
                        <template #overlay="{ pageSize: ps, page }">
                            <AnnotationLayer
                                :annotations="D.annotations"
                                :page="page"
                                :pageSize="ps"
                                :can-place="!!pendingAnnotation && canAnnotate"
                                :pending="pendingAnnotation"
                                @place="placeAnnotation" />
                        </template>
                    </Viewer>
                </section>

                <aside class="space-y-4">
                    <div v-if="myActiveRoute" class="rounded-2xl border border-amber-300 bg-amber-50/50 p-4 shadow-card">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-800 mb-1">Awaiting your action</p>
                        <p class="text-[12px] font-bold text-amber-900">{{ myActiveRoute.action_label }}</p>
                        <textarea v-model="actForm.comment" rows="2" placeholder="Comment (optional)"
                                  class="mt-2 w-full rounded-lg border border-outline-variant px-2 py-1.5 text-[12px]"></textarea>
                        <div class="mt-2 flex items-center gap-2">
                            <button @click="act(myActiveRoute.id, 'complete')" class="flex-1 rounded-lg px-3 py-2 text-[12px] font-black text-white"
                                    style="background:linear-gradient(135deg,#059669,#10b981)">Sign &amp; forward</button>
                            <button @click="act(myActiveRoute.id, 'reject')" class="rounded-lg border border-rose-300 text-rose-700 px-3 py-2 text-[12px] font-black">Reject</button>
                        </div>
                    </div>

                    <RoutingSlipPanel :routes="D.routes ?? []" />
                    <TimelineRail :events="D.events ?? []" />
                </aside>
            </div>

            <SignaturePad v-if="showSigPad" @signed="onSigned" @cancel="showSigPad = false" />
            <StampPicker v-if="showStamp" @stamp="onStamp" @cancel="showStamp = false" />

            <!-- Documents v2 — Phase 1: Edit drawer -->
            <div v-if="showEditPanel" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
                <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-lg">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Metadata only · draft documents</p>
                    <h2 class="text-lg font-black text-primary mb-3">Edit document</h2>
                    <form @submit.prevent="submitEdit" class="space-y-3">
                        <div>
                            <label for="edit-title" class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Title</label>
                            <input id="edit-title" v-model="editForm.title" type="text" required maxlength="255"
                                   class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
                            <p v-if="editForm.errors.title" class="text-rose-600 text-xs mt-1">{{ editForm.errors.title }}</p>
                        </div>
                        <div>
                            <label for="edit-desc" class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Description</label>
                            <textarea id="edit-desc" v-model="editForm.description" rows="3" maxlength="2000"
                                      class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]"></textarea>
                        </div>
                        <div>
                            <label for="edit-conf" class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Confidentiality</label>
                            <select id="edit-conf" v-model="editForm.confidentiality"
                                    class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                                <option value="internal">Internal</option>
                                <option value="confidential">Confidential</option>
                                <option value="restricted">Restricted</option>
                            </select>
                        </div>
                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-outline-variant/40">
                            <button type="button" @click="showEditPanel = false" class="rounded-lg border border-outline-variant px-4 py-2 text-[12px] font-bold">Cancel</button>
                            <button type="submit" :disabled="editForm.processing"
                                    class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                {{ editForm.processing ? 'Saving…' : 'Save changes' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Documents v2 — Phase 1: Share modal -->
            <div v-if="showShareModal" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
                <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-lg">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Read-only audience</p>
                    <h2 class="text-lg font-black text-primary mb-3">Share document</h2>

                    <!-- Confidentiality guard banner -->
                    <p v-if="isSensitive" class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-[11px] font-bold text-amber-900">
                        This document is marked {{ D.confidentiality }}. It can only be shared with individual users — not departments or the whole organization.
                    </p>

                    <form @submit.prevent="submitShare" class="space-y-3">
                        <div>
                            <label for="share-audience" class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Audience</label>
                            <select id="share-audience" v-model="shareForm.audience_type"
                                    class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                                <option value="user">Individual user</option>
                                <option value="department" :disabled="isSensitive">Department</option>
                                <option value="organization" :disabled="isSensitive || ! canShareOrg">
                                    Entire organization{{ ! canShareOrg ? ' (requires permission)' : '' }}
                                </option>
                            </select>
                            <p v-if="shareForm.errors.audience_type" class="text-rose-600 text-xs mt-1">{{ shareForm.errors.audience_type }}</p>
                        </div>
                        <div v-if="shareForm.audience_type === 'user'">
                            <label class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">User</label>
                            <RecipientPicker v-model="shareForm.audience_id" />
                            <p v-if="shareForm.errors.audience_id" class="text-rose-600 text-xs mt-1">{{ shareForm.errors.audience_id }}</p>
                        </div>
                        <div v-if="shareForm.audience_type === 'department'">
                            <label for="share-dept" class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Department ID</label>
                            <input id="share-dept" v-model.number="shareForm.audience_id" type="number" min="1"
                                   class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
                            <p v-if="shareForm.errors.audience_id" class="text-rose-600 text-xs mt-1">{{ shareForm.errors.audience_id }}</p>
                        </div>
                        <div>
                            <label for="share-expires" class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Expires at (optional)</label>
                            <input id="share-expires" v-model="shareForm.expires_at" type="datetime-local"
                                   class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
                            <p v-if="shareForm.errors.expires_at" class="text-rose-600 text-xs mt-1">{{ shareForm.errors.expires_at }}</p>
                        </div>
                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-outline-variant/40">
                            <button type="button" @click="showShareModal = false" class="rounded-lg border border-outline-variant px-4 py-2 text-[12px] font-bold">Done</button>
                            <button type="submit" :disabled="shareForm.processing"
                                    class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                {{ shareForm.processing ? 'Sharing…' : 'Share' }}
                            </button>
                        </div>
                    </form>

                    <!-- Existing shares list -->
                    <div v-if="shares.length" class="mt-5 pt-4 border-t border-outline-variant/40">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant mb-2">Active shares</p>
                        <ul class="space-y-1.5">
                            <li v-for="s in shares" :key="s.id" class="flex items-center justify-between gap-3 text-[12px]">
                                <span class="font-bold text-primary">
                                    <span class="rounded-full bg-secondary/10 text-secondary px-2 py-0.5 text-[10px] font-black uppercase tracking-widest mr-2">{{ s.audience_type }}</span>
                                    {{ s.label ?? s.audience_id ?? 'organization' }}
                                    <span v-if="s.expires_at" class="text-[10px] text-on-surface-variant">· expires {{ s.expires_at }}</span>
                                </span>
                                <button @click="revokeShare(s.id)" class="text-[11px] font-bold text-rose-600 hover:underline">Revoke</button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div v-if="showRouteModal" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
                <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-lg">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Send to recipients in order</p>
                    <h2 class="text-lg font-black text-primary mb-3">Route document</h2>
                    <div class="space-y-2">
                        <div v-for="(r, i) in routeForm.recipients" :key="i" class="flex items-start gap-2">
                            <span class="w-7 text-center font-mono text-[12px] font-black pt-2">{{ i + 1 }}</span>
                            <RecipientPicker v-model="r.user_id" />
                            <select v-model="r.action_required" :aria-label="`Action required for recipient ${i + 1}`"
                                    class="rounded-lg border border-outline-variant px-2 py-2 text-[12px]">
                                <option value="sign">Sign</option>
                                <option value="review">Review</option>
                                <option value="approve">Approve</option>
                                <option value="acknowledge">Acknowledge</option>
                            </select>
                            <button v-if="routeForm.recipients.length > 1" @click="removeRecipient(i)" class="text-rose-600 text-[14px] pt-2">✕</button>
                        </div>
                    </div>
                    <button @click="addRecipient" class="mt-2 text-[12px] font-black text-secondary">+ Add recipient</button>
                    <div class="mt-4 flex justify-end gap-2">
                        <button @click="showRouteModal = false" class="rounded-lg border border-outline-variant px-4 py-2 text-[12px] font-bold">Cancel</button>
                        <button @click="submitRoute" :disabled="routeForm.processing"
                                class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                            {{ routeForm.processing ? 'Routing…' : 'Send' }}
                        </button>
                    </div>
                </div>
            </div>
    </div>
</template>

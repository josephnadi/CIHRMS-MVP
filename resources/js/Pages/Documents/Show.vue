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

defineOptions({ layout: AuthenticatedLayout });

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);

const props = defineProps({
    document:     Object,
    activeModule: String,
});

const D = computed(() => props.document.data ?? props.document);

const docUrl = computed(() => route('documents.download', { document: D.value.uuid, version: D.value.current_version?.version_no }));
const downloadBurnedUrl = computed(() => route('documents.download', { document: D.value.uuid, burned: 1 }));

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

const myActiveRoute = computed(() =>
    D.value.routes?.find(r => r.status === 'in_progress' && r.to_user?.id === currentUserId.value)
);

const canAnnotate = computed(() => D.value.status === 'draft' || !! myActiveRoute.value);
const canRoute    = computed(() => D.value.status === 'draft' && D.value.owner?.id === currentUserId.value);
const canWithdraw = computed(() => D.value.status === 'in_review' && D.value.owner?.id === currentUserId.value);
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

            <div v-if="showRouteModal" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
                <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-lg">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Send to recipients in order</p>
                    <h2 class="text-lg font-black text-primary mb-3">Route document</h2>
                    <div class="space-y-2">
                        <div v-for="(r, i) in routeForm.recipients" :key="i" class="flex items-center gap-2">
                            <span class="w-7 text-center font-mono text-[12px] font-black">{{ i + 1 }}</span>
                            <input v-model.number="r.user_id" type="number" placeholder="Staff user ID"
                                   class="flex-1 rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
                            <select v-model="r.action_required" class="rounded-lg border border-outline-variant px-2 py-2 text-[12px]">
                                <option value="sign">Sign</option>
                                <option value="review">Review</option>
                                <option value="approve">Approve</option>
                                <option value="acknowledge">Acknowledge</option>
                            </select>
                            <button v-if="routeForm.recipients.length > 1" @click="removeRecipient(i)" class="text-rose-600 text-[14px]">✕</button>
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

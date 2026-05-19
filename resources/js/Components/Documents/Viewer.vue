<script setup>
import { computed, markRaw, nextTick, ref, shallowRef, watch, onMounted } from 'vue';
import * as pdfjs from 'pdfjs-dist';
import workerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjs.GlobalWorkerOptions.workerSrc = workerSrc;

const props = defineProps({
    /** Signed URL to the source file (empty/`#` means "no file yet") */
    src:      { type: String, default: '' },
    /** mime type of the source */
    mime:     { type: String, default: '' },
    /** Optional page selection from parent */
    page:     { type: Number, default: 1 },
});

const emit = defineEmits(['rendered', 'page-changed', 'page-size']);

const containerRef = ref(null);
// pdfjs returns class instances with private fields (#pagePromises etc.) that
// cannot be accessed through a Vue reactive Proxy — wrapping them in a plain
// ref() throws "Cannot read private member …". shallowRef + markRaw stores
// the raw instance so the private fields stay reachable.
const pdfDoc       = shallowRef(null);
const totalPages   = ref(1);
const currentPage  = ref(props.page);
const pageSize     = ref({ width: 0, height: 0 });
const loading      = ref(false);
const errorMsg     = ref('');

const hasSrc  = computed(() => !! props.src && props.src !== '#');
const isPdf   = () => props.mime === 'application/pdf';
const isImage = () => props.mime?.startsWith('image/');

async function renderPdf() {
    if (! hasSrc.value) {
        errorMsg.value = 'No document file is attached yet.';
        return;
    }
    loading.value = true;
    errorMsg.value = '';
    try {
        const loadingTask = pdfjs.getDocument({
            url: props.src,
            withCredentials: true,           // carry Laravel session cookie through the signed-route fetch
        });
        const doc = await loadingTask.promise;
        // markRaw belt-and-suspenders: even though shallowRef avoids deep
        // proxying, this guarantees the instance is never wrapped, anywhere.
        pdfDoc.value = markRaw(doc);
        totalPages.value = doc.numPages;
        // Wait one tick so the canvas element is in the DOM before we try to draw on it.
        await nextTick();
        await renderPage(currentPage.value);
    } catch (e) {
        // Surface the underlying reason instead of silently leaving an empty canvas.
        const reason = e?.name === 'MissingPDFException'
            ? 'The file could not be downloaded (link may be expired or unauthorized).'
            : e?.name === 'InvalidPDFException'
                ? 'The file is not a valid PDF.'
                : (e?.message || 'Failed to load the document preview.');
        errorMsg.value = reason;
        // eslint-disable-next-line no-console
        console.error('[Viewer] PDF load failed:', e);
    } finally {
        loading.value = false;
    }
}

async function renderPage(num) {
    if (! pdfDoc.value) return;
    const canvas = containerRef.value?.querySelector('canvas');
    if (! canvas) return;

    const page = await pdfDoc.value.getPage(num);
    const viewport = page.getViewport({ scale: 1.4 });

    canvas.width  = viewport.width;
    canvas.height = viewport.height;
    pageSize.value = { width: viewport.width, height: viewport.height };
    emit('page-size', pageSize.value);

    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    emit('rendered', { page: num });
}

function gotoPage(num) {
    if (num < 1 || num > totalPages.value) return;
    currentPage.value = num;
    emit('page-changed', num);
    renderPage(num);
}

onMounted(() => {
    if (isPdf()) renderPdf();
});

watch(() => props.src, () => {
    if (isPdf()) renderPdf();
});
</script>

<template>
    <div class="relative w-full">
        <!-- Image: render directly -->
        <div v-if="isImage()" class="flex items-center justify-center bg-surface-container-low rounded-xl border border-outline-variant/40 p-4">
            <img :src="src" alt="Document"
                 class="max-w-full max-h-[80vh] rounded-md shadow"
                 @load="(e) => emit('page-size', { width: e.target.naturalWidth, height: e.target.naturalHeight })" />
        </div>

        <!-- PDF: canvas + loading / error overlays so the user knows why a blank box is showing -->
        <div v-else-if="isPdf()" ref="containerRef" class="relative inline-block w-full">
            <!-- The canvas is always present so it's available to pdfjs once render starts. -->
            <canvas class="rounded-xl border border-outline-variant/40 shadow block max-w-full"></canvas>

            <!-- Loading -->
            <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-white/60 backdrop-blur-sm rounded-xl">
                <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px] animate-spin">progress_activity</span>
                    Loading document…
                </div>
            </div>

            <!-- Error -->
            <div v-else-if="errorMsg" class="absolute inset-0 flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50/95 p-6 text-center">
                <div>
                    <span class="material-symbols-outlined text-[34px] text-rose-500">error</span>
                    <p class="mt-2 text-[13px] font-bold text-rose-900">Preview unavailable</p>
                    <p class="mt-1 text-[12px] text-rose-700">{{ errorMsg }}</p>
                    <a :href="src" target="_blank" rel="noopener"
                       class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-[12px] font-bold text-rose-700 hover:bg-rose-50">
                        <span class="material-symbols-outlined text-[14px]">download</span>
                        Try downloading the file
                    </a>
                </div>
            </div>

            <slot name="overlay" :pageSize="pageSize" :page="currentPage" />

            <div class="mt-3 flex items-center gap-3 text-[12px] font-semibold">
                <button @click="gotoPage(currentPage - 1)" :disabled="currentPage <= 1 || loading || !!errorMsg"
                        class="rounded-lg border border-outline-variant px-3 py-1.5 disabled:opacity-40">Prev</button>
                <span>Page {{ currentPage }} / {{ totalPages }}</span>
                <button @click="gotoPage(currentPage + 1)" :disabled="currentPage >= totalPages || loading || !!errorMsg"
                        class="rounded-lg border border-outline-variant px-3 py-1.5 disabled:opacity-40">Next</button>
            </div>
        </div>

        <!-- Unsupported / no mime -->
        <div v-else class="rounded-xl border border-outline-variant/40 bg-surface-container-low p-8 text-center text-on-surface-variant">
            <span class="material-symbols-outlined text-[40px]">description</span>
            <p class="mt-2 text-[13px] font-semibold">
                <template v-if="!mime">No file is attached to this document yet.</template>
                <template v-else>Preview not available for this format. Use Download to view.</template>
            </p>
        </div>
    </div>
</template>

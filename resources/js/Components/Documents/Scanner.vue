<script setup>
/**
 * Document scanner — uses the device camera (rear-facing where available) as
 * a scanner. Captures a still frame to a canvas, converts it to a JPEG blob,
 * and exposes it via the `captured` event so the parent can include it in a
 * normal document-upload POST.
 *
 * Why no TWAIN / WIA bridge today?
 *   Browsers cannot drive flatbed/document-feeder scanners directly — that
 *   needs an OS-level helper (Dynamsoft DWT, eXpressScan, scanner.js, etc.).
 *   When we ship a desktop helper, swap the implementation in here and the
 *   call sites stay identical. Until then, the camera covers ~90% of "I have
 *   a paper in my hand" scenarios and a fallback file-picker handles the
 *   "scanner saved a PDF" case via the existing upload flow.
 */
import { ref, onBeforeUnmount, watch, nextTick } from 'vue';

const props = defineProps({
    /** When true the camera initialises and the preview shows. */
    open: { type: Boolean, default: false },
});

const emit = defineEmits([
    /** Fires with a File (JPEG, ~quality 0.92) ready for FormData. */
    'captured',
    /** Fires when the user cancels. */
    'close',
]);

const videoRef  = ref(null);
const canvasRef = ref(null);
const stream    = ref(null);
const status    = ref('idle'); // idle | starting | live | captured | error
const error     = ref('');
const previewUrl = ref(null);
const capturedFile = ref(null);

const start = async () => {
    error.value = '';
    status.value = 'starting';
    if (!navigator.mediaDevices?.getUserMedia) {
        error.value = 'Camera capture isn\'t supported in this browser.';
        status.value = 'error';
        return;
    }
    try {
        stream.value = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: 'environment' },
                width:  { ideal: 1920 },
                height: { ideal: 1080 },
            },
            audio: false,
        });
        await nextTick();
        if (videoRef.value) {
            videoRef.value.srcObject = stream.value;
            await videoRef.value.play();
        }
        status.value = 'live';
    } catch (e) {
        error.value = e?.message?.includes('Permission')
            ? 'Camera access denied. Allow camera permission in your browser and retry.'
            : 'Could not start the camera. ' + (e?.message ?? '');
        status.value = 'error';
    }
};

const stop = () => {
    if (stream.value) {
        stream.value.getTracks().forEach(t => t.stop());
        stream.value = null;
    }
    if (videoRef.value) videoRef.value.srcObject = null;
    if (previewUrl.value) { URL.revokeObjectURL(previewUrl.value); previewUrl.value = null; }
    capturedFile.value = null;
    status.value = 'idle';
};

const capture = async () => {
    if (!videoRef.value || !canvasRef.value) return;
    const v = videoRef.value;
    const c = canvasRef.value;
    c.width  = v.videoWidth  || 1280;
    c.height = v.videoHeight || 720;
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    const blob = await new Promise(res => c.toBlob(res, 'image/jpeg', 0.92));
    if (!blob) { error.value = 'Capture failed. Try again.'; return; }
    capturedFile.value = new File([blob], `scan-${Date.now()}.jpg`, { type: 'image/jpeg' });
    previewUrl.value = URL.createObjectURL(blob);
    status.value = 'captured';
};

const retake = () => {
    if (previewUrl.value) { URL.revokeObjectURL(previewUrl.value); previewUrl.value = null; }
    capturedFile.value = null;
    status.value = 'live';
};

const accept = () => {
    if (!capturedFile.value) return;
    emit('captured', capturedFile.value);
    stop();
};

const close = () => {
    stop();
    emit('close');
};

watch(() => props.open, (v) => { v ? start() : stop(); }, { immediate: true });
onBeforeUnmount(stop);
</script>

<template>
    <div v-if="open" class="scanner-shell" role="dialog" aria-label="Scan document">
        <div class="scanner-card">
            <header class="flex items-center justify-between mb-3">
                <div>
                    <div class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Document scanner</div>
                    <h3 class="text-[14px] font-bold text-on-surface leading-tight">
                        {{ status === 'captured' ? 'Confirm scan' : 'Position the document in the frame' }}
                    </h3>
                </div>
                <button type="button" class="rounded-lg p-1.5 hover:bg-surface-container transition-colors"
                        :aria-label="'Close scanner'" @click="close">
                    <span class="material-symbols-outlined text-[20px] text-on-surface-variant">close</span>
                </button>
            </header>

            <!-- Live camera preview -->
            <div v-show="status === 'live' || status === 'starting'" class="scanner-stage">
                <video ref="videoRef" muted playsinline autoplay class="scanner-video"></video>
                <div class="scanner-frame" aria-hidden="true"></div>
                <p v-if="status === 'starting'" class="scanner-overlay-text">Starting camera…</p>
            </div>

            <!-- Captured preview -->
            <div v-show="status === 'captured'" class="scanner-stage">
                <img v-if="previewUrl" :src="previewUrl" alt="Scanned document preview" class="scanner-preview" />
            </div>

            <!-- Error state -->
            <div v-if="status === 'error'" class="scanner-error">
                <span class="material-symbols-outlined text-[28px] text-rose-500">videocam_off</span>
                <p class="text-[13px] font-semibold text-on-surface text-center max-w-sm">{{ error }}</p>
                <button type="button" @click="start" class="mt-2 text-[12px] font-black text-secondary hover:underline">Try again</button>
            </div>

            <canvas ref="canvasRef" class="hidden"></canvas>

            <!-- Actions -->
            <div class="mt-4 flex items-center justify-end gap-2">
                <button v-if="status === 'live'" type="button" @click="close"
                        class="rounded-lg border border-outline-variant px-4 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                    Cancel
                </button>
                <button v-if="status === 'live'" type="button" @click="capture"
                        class="flex items-center gap-2 rounded-lg px-4 py-2 text-[12px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                    <span class="material-symbols-outlined text-[16px]">photo_camera</span>
                    Capture
                </button>

                <button v-if="status === 'captured'" type="button" @click="retake"
                        class="rounded-lg border border-outline-variant px-4 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                    Retake
                </button>
                <button v-if="status === 'captured'" type="button" @click="accept"
                        class="flex items-center gap-2 rounded-lg px-4 py-2 text-[12px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                        style="background:linear-gradient(135deg,#205295,#2c74b3)">
                    <span class="material-symbols-outlined text-[16px]">check</span>
                    Use this scan
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.scanner-shell {
    position: fixed; inset: 0; z-index: 70;
    display: flex; align-items: center; justify-content: center;
    background: rgba(8, 12, 28, 0.6);
    backdrop-filter: blur(6px);
    padding: 1rem;
    animation: scanner-fade 0.18s ease-out;
}
@keyframes scanner-fade { from { opacity: 0; } to { opacity: 1; } }

.scanner-card {
    width: 100%;
    max-width: 720px;
    background: rgb(var(--ct-surface-lowest, 255 255 255));
    border: 1px solid rgb(var(--ct-outline-variant, 198 198 205) / 0.4);
    border-radius: 1rem;
    padding: 1.1rem 1.2rem;
    box-shadow: 0 24px 60px rgba(0,0,0,0.35);
}

.scanner-stage {
    position: relative;
    width: 100%;
    aspect-ratio: 4 / 3;
    background: #06192f;
    border-radius: 0.65rem;
    overflow: hidden;
}
.scanner-video, .scanner-preview {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}

/* Visual hint frame so users know roughly where to centre the page */
.scanner-frame {
    pointer-events: none;
    position: absolute; inset: 6%;
    border: 2px dashed rgba(255,215,0,0.55);
    border-radius: 0.4rem;
    box-shadow:
        inset 0 0 0 1px rgba(255,255,255,0.04),
        0 0 0 9999px rgba(6,25,47,0.35);
}
.scanner-overlay-text {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,0.75);
    font-weight: 700; letter-spacing: 0.04em;
}

.scanner-error {
    display: flex; flex-direction: column; align-items: center;
    gap: 0.6rem; padding: 2.5rem 1rem;
}
</style>

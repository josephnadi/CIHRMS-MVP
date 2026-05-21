<script setup>
/**
 * Biometric photo capture for Ghana Card verification.
 *
 * Uses the device camera (rear-facing preferred) to capture the holder's
 * face + Ghana Card together in a single frame, which becomes the audit
 * evidence attached to the `IdentityVerification` row. The captured JPEG
 * is emitted via `captured` so the parent form can include it in the
 * upload alongside the typed card number.
 *
 * The framing guide is sized for a face + card composition — wider than
 * the Documents/Scanner guide, which is portrait-oriented for paper.
 */
import { ref, onBeforeUnmount, watch, nextTick } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['captured', 'close']);

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
                facingMode: { ideal: 'user' },     // front camera — subject holding card to face
                width:  { ideal: 1280 },
                height: { ideal: 960 },
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
    c.height = v.videoHeight || 960;
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    const blob = await new Promise(res => c.toBlob(res, 'image/jpeg', 0.9));
    if (!blob) { error.value = 'Capture failed. Try again.'; return; }
    capturedFile.value = new File([blob], `biometric-${Date.now()}.jpg`, { type: 'image/jpeg' });
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

const close = () => { stop(); emit('close'); };

watch(() => props.open, (v) => { v ? start() : stop(); }, { immediate: true });
onBeforeUnmount(stop);
</script>

<template>
    <div v-if="open" class="bio-shell" role="dialog" aria-label="Biometric capture for Ghana Card verification">
        <div class="bio-card">
            <header class="flex items-center justify-between mb-3">
                <div>
                    <div class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Biometric capture</div>
                    <h3 class="text-[14px] font-bold text-on-surface leading-tight">
                        {{ status === 'captured' ? 'Confirm photo' : 'Hold Ghana Card next to face' }}
                    </h3>
                </div>
                <button type="button" class="rounded-lg p-1.5 hover:bg-surface-container transition-colors"
                        aria-label="Close" @click="close">
                    <span class="material-symbols-outlined text-[20px] text-on-surface-variant">close</span>
                </button>
            </header>

            <div v-show="status === 'live' || status === 'starting'" class="bio-stage">
                <video ref="videoRef" muted playsinline autoplay class="bio-video"></video>
                <div class="bio-frame" aria-hidden="true"></div>
                <p v-if="status === 'starting'" class="bio-overlay-text">Starting camera…</p>
            </div>

            <div v-show="status === 'captured'" class="bio-stage">
                <img v-if="previewUrl" :src="previewUrl" alt="Captured biometric preview" class="bio-preview" />
            </div>

            <div v-if="status === 'error'" class="bio-error">
                <span class="material-symbols-outlined text-[28px] text-rose-500">videocam_off</span>
                <p class="text-[13px] font-semibold text-on-surface text-center max-w-sm">{{ error }}</p>
                <button type="button" @click="start" class="mt-2 text-[12px] font-black text-secondary hover:underline">Try again</button>
            </div>

            <canvas ref="canvasRef" class="hidden"></canvas>

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
                    Use this photo
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.bio-shell {
    position: fixed; inset: 0; z-index: 70;
    display: flex; align-items: center; justify-content: center;
    background: rgba(8, 12, 28, 0.6);
    backdrop-filter: blur(6px);
    padding: 1rem;
    animation: bio-fade 0.18s ease-out;
}
@keyframes bio-fade { from { opacity: 0; } to { opacity: 1; } }

.bio-card {
    width: 100%;
    max-width: 640px;
    background: rgb(var(--ct-surface-lowest, 255 255 255));
    border: 1px solid rgb(var(--ct-outline-variant, 198 198 205) / 0.4);
    border-radius: 1rem;
    padding: 1.1rem 1.2rem;
    box-shadow: 0 24px 60px rgba(0,0,0,0.35);
}

.bio-stage {
    position: relative;
    width: 100%;
    aspect-ratio: 4 / 3;
    background: #06192f;
    border-radius: 0.65rem;
    overflow: hidden;
}
.bio-video, .bio-preview { width: 100%; height: 100%; object-fit: cover; display: block; }

/* Face + card composition guide — square crop hint */
.bio-frame {
    pointer-events: none;
    position: absolute; inset: 8%;
    border: 2px dashed rgba(255,215,0,0.55);
    border-radius: 0.4rem;
    box-shadow:
        inset 0 0 0 1px rgba(255,255,255,0.04),
        0 0 0 9999px rgba(6,25,47,0.35);
}
.bio-overlay-text {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,0.75);
    font-weight: 700; letter-spacing: 0.04em;
}

.bio-error {
    display: flex; flex-direction: column; align-items: center;
    gap: 0.6rem; padding: 2.5rem 1rem;
}
</style>

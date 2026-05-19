<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import SignaturePad from 'signature_pad';

const emit = defineEmits(['signed', 'cancel']);

const canvasRef = ref(null);
let pad = null;

onMounted(() => {
    pad = new SignaturePad(canvasRef.value, {
        penColor:       '#0d1452',
        backgroundColor:'#fff',
        minWidth: 0.6,
        maxWidth: 2.5,
    });
});

onBeforeUnmount(() => { pad?.off(); });

function clear() { pad?.clear(); }

function save() {
    if (pad.isEmpty()) {
        alert('Please draw a signature first.');
        return;
    }
    const png = pad.toDataURL('image/png');
    emit('signed', { png_base64: png });
}
</script>

<template>
    <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-lg">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Add your signature</p>
            <h2 class="text-lg font-black text-primary leading-tight mb-3">Draw signature</h2>
            <canvas ref="canvasRef" width="500" height="200" class="block w-full border border-outline-variant rounded-lg bg-white"></canvas>
            <div class="flex items-center justify-between mt-4">
                <button @click="clear" class="text-[12px] font-bold text-on-surface-variant hover:text-primary">Clear</button>
                <div class="flex items-center gap-2">
                    <button @click="emit('cancel')" class="rounded-lg border border-outline-variant px-4 py-2 text-[13px] font-bold">Cancel</button>
                    <button @click="save" class="rounded-lg px-4 py-2 text-[13px] font-black text-white" style="background:linear-gradient(135deg,#0d1452,#1a237e)">Save</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
    presets: {
        type: Array,
        default: () => [
            { text: 'APPROVED',     color: '#059669' },
            { text: 'RECEIVED',     color: '#1a237e' },
            { text: 'FOR ACTION',   color: '#d97706' },
            { text: 'CONFIDENTIAL', color: '#dc2626' },
            { text: 'COPY',         color: '#64748b' },
        ],
    },
});

const emit = defineEmits(['stamp', 'cancel']);

const customText  = ref('');
const customColor = ref('#cc0000');
</script>

<template>
    <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-md">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Place a stamp</p>
            <h2 class="text-lg font-black text-primary leading-tight mb-3">Choose a stamp</h2>

            <div class="grid grid-cols-2 gap-2">
                <button v-for="p in presets" :key="p.text"
                        @click="emit('stamp', { text: p.text, color: p.color })"
                        class="flex items-center justify-center border-2 rounded-lg px-3 py-3 text-[13px] font-black"
                        :style="{ color: p.color, borderColor: p.color }">
                    {{ p.text }}
                </button>
            </div>

            <div class="mt-4 border-t border-outline-variant/40 pt-3">
                <p class="text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-2">Custom</p>
                <div class="flex items-center gap-2">
                    <input v-model="customText" placeholder="STAMP TEXT" maxlength="20"
                           aria-label="Custom stamp text"
                           class="flex-1 rounded-lg border border-outline-variant text-[13px] px-3 py-2 font-bold uppercase" />
                    <input v-model="customColor" type="color" aria-label="Custom stamp colour"
                           class="rounded-lg border border-outline-variant w-10 h-10" />
                    <button :disabled="! customText.trim()"
                            @click="emit('stamp', { text: customText.trim().toUpperCase(), color: customColor })"
                            class="rounded-lg px-3 py-2 text-[12px] font-black text-white disabled:opacity-40"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)">Use</button>
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button @click="emit('cancel')" class="rounded-lg border border-outline-variant px-4 py-2 text-[13px] font-bold">Cancel</button>
            </div>
        </div>
    </div>
</template>

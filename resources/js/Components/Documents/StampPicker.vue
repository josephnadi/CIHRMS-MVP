<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
    presets: {
        type: Array,
        default: () => [
            { text: 'APPROVED',     color: '#059669' },
            { text: 'RECEIVED',     color: '#1a237e' },
            { text: 'FOR ACTION',   color: '#d97706' },
            { text: 'CONFIDENTIAL', color: '#dc2626' },
        ],
    },
});

const emit = defineEmits(['stamp', 'cancel']);

const tab = ref('library');
const assets = ref([]);
const loading = ref(false);
const customText  = ref('');
const customColor = ref('#cc0000');

async function fetchAssets() {
    loading.value = true;
    try {
        const res = await axios.get(route('settings.stamps.index'), {
            headers: {
                'X-Inertia': 'true',
                'X-Inertia-Version': '0',
                Accept: 'application/json',
            },
        });
        assets.value = res.data?.props?.assets?.data ?? [];
    } catch (e) {
        assets.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(fetchAssets);

async function pickAsset(asset) {
    const res = await fetch(asset.preview_url);
    const blob = await res.blob();
    const reader = new FileReader();
    reader.onloadend = () => {
        emit('stamp', { png_base64: reader.result, asset_id: asset.id, w_pct: asset.default_w_pct, h_pct: asset.default_h_pct });
    };
    reader.readAsDataURL(blob);
}
</script>

<template>
    <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-xl">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Place a stamp</p>
            <h2 class="text-lg font-black text-primary leading-tight mb-3">Choose a stamp</h2>

            <div class="flex gap-1 mb-3 border-b border-outline-variant/40">
                <button @click="tab = 'library'"
                        :class="['px-3 py-1.5 text-[11px] font-black uppercase tracking-widest border-b-2',
                                 tab === 'library' ? 'border-secondary text-secondary' : 'border-transparent text-on-surface-variant']">
                    My library
                </button>
                <button @click="tab = 'text'"
                        :class="['px-3 py-1.5 text-[11px] font-black uppercase tracking-widest border-b-2',
                                 tab === 'text' ? 'border-secondary text-secondary' : 'border-transparent text-on-surface-variant']">
                    Text stamp
                </button>
            </div>

            <div v-if="tab === 'library'">
                <p v-if="loading" class="text-[12px] text-on-surface-variant">Loading…</p>
                <div v-else-if="assets.length" class="grid grid-cols-3 gap-2">
                    <button v-for="a in assets" :key="a.id" @click="pickAsset(a)"
                            class="rounded-lg border border-outline-variant p-2 hover:border-secondary transition-colors">
                        <img :src="a.preview_url" :alt="a.name" class="h-14 w-full object-contain bg-white rounded" />
                        <p class="mt-1 text-[11px] font-bold truncate">{{ a.name }}</p>
                    </button>
                </div>
                <div v-else class="text-center py-6 text-[12px] text-on-surface-variant">
                    No stamps yet — <a :href="route('settings.stamps.index')" class="text-secondary font-black underline">upload one</a>.
                </div>
            </div>

            <div v-if="tab === 'text'">
                <div class="grid grid-cols-2 gap-2">
                    <button v-for="p in presets" :key="p.text"
                            @click="emit('stamp', { text: p.text, color: p.color })"
                            class="flex items-center justify-center border-2 rounded-lg px-3 py-3 text-[13px] font-black"
                            :style="{ color: p.color, borderColor: p.color }">{{ p.text }}</button>
                </div>
                <div class="mt-4 border-t border-outline-variant/40 pt-3">
                    <div class="flex items-center gap-2">
                        <input v-model="customText" placeholder="STAMP TEXT" maxlength="20"
                               class="flex-1 rounded-lg border border-outline-variant text-[13px] px-3 py-2 font-bold uppercase" />
                        <input v-model="customColor" type="color" class="rounded-lg border border-outline-variant w-10 h-10" />
                        <button :disabled="! customText.trim()"
                                @click="emit('stamp', { text: customText.trim().toUpperCase(), color: customColor })"
                                class="rounded-lg px-3 py-2 text-[12px] font-black text-white disabled:opacity-40"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)">Use</button>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button @click="emit('cancel')" class="rounded-lg border border-outline-variant px-4 py-2 text-[13px] font-bold">Cancel</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    annotations: { type: Array, default: () => [] },
    page:        { type: Number, default: 1 },
    pageSize:    { type: Object, required: true },     // { width, height }
    canPlace:    { type: Boolean, default: false },
    pending:     { type: Object, default: null },      // { type, data, w_pct, h_pct }
});

const emit = defineEmits(['place', 'remove']);

const visible = computed(() => props.annotations.filter(a => a.page === props.page));

function handleClick(e) {
    if (! props.canPlace || ! props.pending) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const x_pct = ((e.clientX - rect.left) / rect.width)  * 100;
    const y_pct = ((e.clientY - rect.top)  / rect.height) * 100;
    emit('place', { x_pct, y_pct, page: props.page });
}
</script>

<template>
    <div class="absolute inset-0"
         :class="canPlace ? 'cursor-crosshair' : 'pointer-events-none'"
         @click="handleClick">
        <div v-for="a in visible" :key="a.id"
             :style="{
                 position: 'absolute',
                 left:   a.x_pct + '%',
                 top:    a.y_pct + '%',
                 width:  a.w_pct + '%',
                 height: a.h_pct + '%',
             }"
             class="group pointer-events-auto">
            <img v-if="a.type === 'signature' || a.type === 'initial' || (a.type === 'stamp' && a.data?.png_base64)"
                 :src="a.data.png_base64"
                 class="w-full h-full object-contain" />
            <div v-else-if="a.type === 'stamp'"
                 class="flex items-center justify-center w-full h-full border-2 font-black text-center"
                 :style="{ color: a.data?.color ?? '#cc0000', borderColor: a.data?.color ?? '#cc0000' }">
                {{ a.data?.text ?? 'STAMP' }}
            </div>
            <div v-else-if="a.type === 'text'"
                 class="text-[11px] font-semibold text-on-surface">
                {{ a.data?.text }}
            </div>
        </div>
    </div>
</template>

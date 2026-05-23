<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    annotation:    { type: Object, required: true },
    canManipulate: { type: Boolean, default: false },
});
const emit = defineEmits(['update', 'delete']);

const draft = ref({
    x_pct: Number(props.annotation.x_pct),
    y_pct: Number(props.annotation.y_pct),
    w_pct: Number(props.annotation.w_pct),
    h_pct: Number(props.annotation.h_pct),
    rotation: Number(props.annotation.rotation ?? 0),
});
const dragging = ref(null);

function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

function start(mode, handle, e) {
    if (! props.canManipulate) return;
    e.stopPropagation(); e.preventDefault();
    const parent = e.currentTarget.closest('[data-annotation-layer]');
    dragging.value = {
        mode, handle,
        startX: e.clientX, startY: e.clientY,
        start: { ...draft.value },
        parentRect: parent.getBoundingClientRect(),
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onEnd);
}

function onMove(e) {
    const d = dragging.value;
    if (! d) return;
    const dxPct = ((e.clientX - d.startX) / d.parentRect.width) * 100;
    const dyPct = ((e.clientY - d.startY) / d.parentRect.height) * 100;

    if (d.mode === 'move') {
        draft.value.x_pct = clamp(d.start.x_pct + dxPct, 0, 100 - draft.value.w_pct);
        draft.value.y_pct = clamp(d.start.y_pct + dyPct, 0, 100 - draft.value.h_pct);
    } else if (d.mode === 'resize') {
        const minW = 4, minH = 4, maxW = 80, maxH = 80;
        if (d.handle === 'se') {
            draft.value.w_pct = clamp(d.start.w_pct + dxPct, minW, maxW);
            draft.value.h_pct = clamp(d.start.h_pct + dyPct, minH, maxH);
        } else if (d.handle === 'sw') {
            const nw = clamp(d.start.w_pct - dxPct, minW, maxW);
            draft.value.x_pct = clamp(d.start.x_pct + (d.start.w_pct - nw), 0, 100);
            draft.value.w_pct = nw;
            draft.value.h_pct = clamp(d.start.h_pct + dyPct, minH, maxH);
        } else if (d.handle === 'ne') {
            draft.value.w_pct = clamp(d.start.w_pct + dxPct, minW, maxW);
            const nh = clamp(d.start.h_pct - dyPct, minH, maxH);
            draft.value.y_pct = clamp(d.start.y_pct + (d.start.h_pct - nh), 0, 100);
            draft.value.h_pct = nh;
        } else if (d.handle === 'nw') {
            const nw = clamp(d.start.w_pct - dxPct, minW, maxW);
            const nh = clamp(d.start.h_pct - dyPct, minH, maxH);
            draft.value.x_pct = clamp(d.start.x_pct + (d.start.w_pct - nw), 0, 100);
            draft.value.y_pct = clamp(d.start.y_pct + (d.start.h_pct - nh), 0, 100);
            draft.value.w_pct = nw;
            draft.value.h_pct = nh;
        }
    } else if (d.mode === 'rotate') {
        const boxLeft = d.parentRect.left + (draft.value.x_pct / 100) * d.parentRect.width;
        const boxTop  = d.parentRect.top  + (draft.value.y_pct / 100) * d.parentRect.height;
        const cx = boxLeft + (draft.value.w_pct / 100) * d.parentRect.width  / 2;
        const cy = boxTop  + (draft.value.h_pct / 100) * d.parentRect.height / 2;
        const deg = Math.atan2(e.clientY - cy, e.clientX - cx) * 180 / Math.PI + 90;
        draft.value.rotation = Math.round(((deg + 180) % 360) - 180);
    }
}

function onEnd() {
    if (! dragging.value) return;
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onEnd);
    dragging.value = null;
    emit('update', { ...draft.value });
}

const boxStyle = computed(() => ({
    position: 'absolute',
    left:   draft.value.x_pct + '%',
    top:    draft.value.y_pct + '%',
    width:  draft.value.w_pct + '%',
    height: draft.value.h_pct + '%',
    transform: `rotate(${draft.value.rotation}deg)`,
    transformOrigin: 'center center',
}));

const a = computed(() => props.annotation);
</script>

<template>
    <div :style="boxStyle"
         :class="['group select-none', canManipulate ? 'cursor-move' : 'pointer-events-none']"
         @pointerdown="start('move', null, $event)">
        <img v-if="a.type === 'signature' || a.type === 'initial' || (a.type === 'stamp' && a.data?.png_base64)"
             :src="a.data.png_base64" draggable="false"
             class="w-full h-full object-contain pointer-events-none" />
        <div v-else-if="a.type === 'stamp'"
             class="flex items-center justify-center w-full h-full border-2 font-black text-center pointer-events-none"
             :style="{ color: a.data?.color ?? '#cc0000', borderColor: a.data?.color ?? '#cc0000' }">
            {{ a.data?.text ?? 'STAMP' }}
        </div>
        <div v-else-if="a.type === 'text'"
             class="text-[11px] font-semibold text-on-surface pointer-events-none">
            {{ a.data?.text }}
        </div>
        <template v-if="canManipulate">
            <button type="button" @pointerdown.stop="emit('delete')"
                    class="absolute -top-3 -right-3 w-6 h-6 rounded-full bg-rose-600 text-white text-[12px] font-black shadow opacity-0 group-hover:opacity-100 transition-opacity">✕</button>
            <div @pointerdown.stop="start('rotate', null, $event)"
                 class="absolute left-1/2 -top-6 w-3 h-3 -ml-1.5 rounded-full bg-secondary border-2 border-white shadow cursor-grab opacity-0 group-hover:opacity-100" title="Rotate"></div>
            <div @pointerdown.stop="start('resize', 'nw', $event)" class="absolute -top-1 -left-1 w-3 h-3 rounded-sm bg-secondary border border-white cursor-nwse-resize opacity-0 group-hover:opacity-100"></div>
            <div @pointerdown.stop="start('resize', 'ne', $event)" class="absolute -top-1 -right-1 w-3 h-3 rounded-sm bg-secondary border border-white cursor-nesw-resize opacity-0 group-hover:opacity-100"></div>
            <div @pointerdown.stop="start('resize', 'sw', $event)" class="absolute -bottom-1 -left-1 w-3 h-3 rounded-sm bg-secondary border border-white cursor-nesw-resize opacity-0 group-hover:opacity-100"></div>
            <div @pointerdown.stop="start('resize', 'se', $event)" class="absolute -bottom-1 -right-1 w-3 h-3 rounded-sm bg-secondary border border-white cursor-nwse-resize opacity-0 group-hover:opacity-100"></div>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    /**
     * Segments to render, in display order.
     * Each: { label: string, value: number, color: string }
     */
    segments:    { type: Array,  required: true },
    size:        { type: Number, default: 168 },
    stroke:      { type: Number, default: 16 },
    /** Big number in the centre (defaults to the sum of segment values). */
    centerValue: { type: [Number, String], default: null },
    /** Tiny eyebrow under the big number. */
    centerLabel: { type: String, default: '' },
    /** Gap (in degrees) between adjacent segments — refines the institutional feel. */
    gapDegrees:  { type: Number, default: 1.2 },
});

const radius   = computed(() => (props.size - props.stroke) / 2);
const circumf  = computed(() => 2 * Math.PI * radius.value);
const total    = computed(() => props.segments.reduce((s, seg) => s + (Number(seg.value) || 0), 0));

const resolvedCenterValue = computed(() => props.centerValue ?? total.value);

// Animate from 0 → final on mount.
const animated = ref(false);
onMounted(() => {
    requestAnimationFrame(() => requestAnimationFrame(() => { animated.value = true; }));
});

// Pre-compute each arc as (dashLen, dashOffset, rotation).
// stroke-dashoffset of `circumf` => fully hidden; we shrink to `gap` to reveal.
const arcs = computed(() => {
    if (!total.value) return [];
    const out = [];
    let cumulative = 0;
    const gapPx = (props.gapDegrees / 360) * circumf.value;
    for (const seg of props.segments) {
        const v = Number(seg.value) || 0;
        if (v <= 0) continue;
        const fraction = v / total.value;
        const arcLen   = Math.max(0, fraction * circumf.value - gapPx);
        // Rotate so the segment starts where the previous one ended.
        const rotation = -90 + (cumulative / total.value) * 360 + props.gapDegrees / 2;
        out.push({
            ...seg,
            arcLen,
            rotation,
            dashArray: `${arcLen} ${circumf.value - arcLen}`,
            percent: fraction * 100,
        });
        cumulative += v;
    }
    return out;
});
</script>

<template>
    <div class="multi-donut" :style="{ width: size + 'px', height: size + 'px' }">
        <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" class="multi-donut__svg" role="img" :aria-label="centerLabel">
            <!-- Track -->
            <circle
                :cx="size / 2" :cy="size / 2" :r="radius"
                fill="none"
                stroke="rgba(15,23,42,0.06)"
                :stroke-width="stroke"
            />
            <!-- Segments -->
            <circle
                v-for="(arc, idx) in arcs"
                :key="arc.label + '-' + idx"
                :cx="size / 2" :cy="size / 2" :r="radius"
                fill="none"
                :stroke="arc.color"
                :stroke-width="stroke"
                stroke-linecap="butt"
                :stroke-dasharray="animated ? arc.dashArray : `0 ${circumf}`"
                :transform="`rotate(${arc.rotation} ${size / 2} ${size / 2})`"
                class="multi-donut__arc"
                :style="{ transitionDelay: `${idx * 80}ms` }"
            />
        </svg>

        <div class="multi-donut__center">
            <span class="multi-donut__num tabular-nums">{{ resolvedCenterValue }}</span>
            <span v-if="centerLabel" class="multi-donut__lbl">{{ centerLabel }}</span>
        </div>
    </div>
</template>

<style scoped>
.multi-donut {
    position: relative;
    display: inline-block;
}
.multi-donut__svg {
    display: block;
    overflow: visible;
}
.multi-donut__arc {
    transition: stroke-dasharray 1.1s cubic-bezier(0.22, 1, 0.36, 1);
}
.multi-donut__center {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    line-height: 1;
    pointer-events: none;
}
.multi-donut__num {
    font-size: 22px;
    font-weight: 900;
    letter-spacing: -0.03em;
    color: rgb(15, 23, 42);
    line-height: 1;
}
.dark .multi-donut__num { color: rgb(241, 245, 249); }
.multi-donut__lbl {
    margin-top: 4px;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    color: rgba(15, 23, 42, 0.45);
}
.dark .multi-donut__lbl { color: rgba(241, 245, 249, 0.40); }

@media (prefers-reduced-motion: reduce) {
    .multi-donut__arc { transition: none; }
}
</style>

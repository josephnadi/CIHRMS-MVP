<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    data:       { type: Array,   default: () => [] },
    color:      { type: String,  default: '#1a237e' },
    width:      { type: Number,  default: 120 },
    height:     { type: Number,  default: 36 },
    strokeWidth:{ type: Number,  default: 1.6 },
    area:       { type: Boolean, default: true },
    showDot:    { type: Boolean, default: true },
    hover:      { type: Boolean, default: true },
    valueLabel: { type: Function, default: (v) => Number(v).toLocaleString() },
    label:      { type: String,  default: '' },
    loading:    { type: Boolean, default: false },
});

const uid = Math.random().toString(36).slice(2, 9);
const gradId  = `spark-grad-${uid}`;
const glowId  = `spark-glow-${uid}`;
const clipId  = `spark-clip-${uid}`;

const W = computed(() => props.width);
const H = computed(() => props.height);

// Build points; tolerate empty / 1-pt arrays.
const points = computed(() => {
    const d = props.data?.length ? props.data : [0, 0];
    const min = Math.min(...d);
    const max = Math.max(...d);
    const rng = Math.max(max - min, 0.001);
    const n   = d.length;
    return d.map((y, i) => ({
        x: n === 1 ? W.value / 2 : (i / (n - 1)) * W.value,
        y: H.value - ((y - min) / rng) * H.value * 0.86 - H.value * 0.07,
        v: y,
        i,
    }));
});

const linePath = computed(() => points.value.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(2)} ${p.y.toFixed(2)}`).join(' '));
const areaPath = computed(() => {
    if (!points.value.length) return '';
    const head = `M ${points.value[0].x.toFixed(2)} ${H.value} L ${points.value[0].x.toFixed(2)} ${points.value[0].y.toFixed(2)}`;
    const mid  = points.value.slice(1).map(p => `L ${p.x.toFixed(2)} ${p.y.toFixed(2)}`).join(' ');
    const tail = `L ${points.value.at(-1).x.toFixed(2)} ${H.value} Z`;
    return `${head} ${mid} ${tail}`;
});

const lastPt = computed(() => points.value.at(-1));

// ── Draw-in animation: stretch path-length on mount ───────────────
const pathRef = ref(null);
const pathLen = ref(0);

const measure = async () => {
    await nextTick();
    if (pathRef.value && pathRef.value.getTotalLength) {
        pathLen.value = pathRef.value.getTotalLength();
    }
};
onMounted(measure);
watch(() => props.data, measure, { deep: true });

// ── Hover tooltip ─────────────────────────────────────────────────
const hoverIdx = ref(null);
const hovered  = computed(() => hoverIdx.value !== null ? points.value[hoverIdx.value] : null);

const onMove = (evt) => {
    if (!props.hover || !points.value.length) return;
    const rect = evt.currentTarget.getBoundingClientRect();
    const x = ((evt.clientX - rect.left) / rect.width) * W.value;
    let nearest = 0;
    let best = Infinity;
    for (let i = 0; i < points.value.length; i++) {
        const dx = Math.abs(points.value[i].x - x);
        if (dx < best) { best = dx; nearest = i; }
    }
    hoverIdx.value = nearest;
};
const onLeave = () => { hoverIdx.value = null; };
</script>

<template>
    <div class="spark" :style="{ width: width + 'px', height: height + 'px' }">

        <!-- Loading shimmer state -->
        <div v-if="loading" class="spark-skeleton" :style="{ '--c': color }">
            <span class="spark-shimmer"></span>
        </div>

        <svg v-else
             class="spark-svg"
             :viewBox="`0 0 ${width} ${height}`"
             preserveAspectRatio="none"
             @mousemove="onMove"
             @mouseleave="onLeave">
            <defs>
                <linearGradient :id="gradId" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%"  :stop-color="color" stop-opacity="0.32"/>
                    <stop offset="60%" :stop-color="color" stop-opacity="0.08"/>
                    <stop offset="100%" :stop-color="color" stop-opacity="0"/>
                </linearGradient>
                <filter :id="glowId" x="-50%" y="-50%" width="200%" height="200%">
                    <feGaussianBlur stdDeviation="2" result="blur"/>
                    <feMerge>
                        <feMergeNode in="blur"/>
                        <feMergeNode in="SourceGraphic"/>
                    </feMerge>
                </filter>
                <clipPath :id="clipId">
                    <rect x="0" y="0" :width="width" :height="height"/>
                </clipPath>
            </defs>

            <!-- Area fill -->
            <path
                v-if="area"
                :d="areaPath"
                :fill="`url(#${gradId})`"
                class="spark-area"
            />

            <!-- Line stroke (draw-in via dasharray) -->
            <path
                ref="pathRef"
                :d="linePath"
                fill="none"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="spark-line"
                :style="{
                    '--len': pathLen,
                    strokeDasharray: pathLen,
                    strokeDashoffset: pathLen,
                }"
            />

            <!-- Terminal pulse dot -->
            <g v-if="showDot && lastPt" :transform="`translate(${lastPt.x},${lastPt.y})`">
                <circle r="6" :fill="color" opacity="0.15" class="spark-dot-ring"/>
                <circle r="2.4" :fill="color" :filter="`url(#${glowId})`" class="spark-dot"/>
            </g>

            <!-- Hover crosshair -->
            <g v-if="hover && hovered" class="spark-hover">
                <line :x1="hovered.x" :x2="hovered.x" :y1="0" :y2="height"
                      :stroke="color" stroke-width="0.8" stroke-dasharray="2 3" opacity="0.55"/>
                <circle :cx="hovered.x" :cy="hovered.y" r="4" :fill="color"/>
                <circle :cx="hovered.x" :cy="hovered.y" r="2" fill="#ffffff"/>
            </g>
        </svg>

        <!-- Hover tooltip -->
        <div v-if="hovered && !loading"
             class="spark-tip"
             :style="{
                left:  `${(hovered.x / width) * 100}%`,
                borderColor: color,
                color: color,
             }">
            <span class="spark-tip-val">{{ valueLabel(hovered.v) }}</span>
            <span v-if="label" class="spark-tip-lbl">{{ label }}</span>
        </div>
    </div>
</template>

<style scoped>
.spark {
    position: relative;
    display: inline-block;
    overflow: visible;
}
.spark-svg {
    display: block;
    width: 100%;
    height: 100%;
    overflow: visible;
}

/* Draw-in animation */
.spark-line {
    animation: spark-draw 1.2s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    transition: d 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes spark-draw {
    to { stroke-dashoffset: 0; }
}

/* Area soft entrance */
.spark-area {
    opacity: 0;
    animation: spark-fade 1.4s 0.2s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    transition: d 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes spark-fade {
    to { opacity: 1; }
}

/* Terminal dot — gentle pulse */
.spark-dot {
    animation: spark-dot-pulse 2.4s ease-in-out infinite;
}
.spark-dot-ring {
    transform-origin: center;
    animation: spark-ring-pulse 2.4s ease-in-out infinite;
}
@keyframes spark-dot-pulse {
    0%, 100% { opacity: 1;   }
    50%      { opacity: 0.6; }
}
@keyframes spark-ring-pulse {
    0%   { transform: scale(0.6); opacity: 0.5; }
    80%  { transform: scale(1.8); opacity: 0;   }
    100% { transform: scale(1.8); opacity: 0;   }
}

/* Hover crosshair fade-in */
.spark-hover {
    animation: spark-cross 0.15s ease-out forwards;
}
@keyframes spark-cross {
    from { opacity: 0; }
    to   { opacity: 1; }
}

/* Tooltip */
.spark-tip {
    position: absolute;
    bottom: calc(100% + 6px);
    transform: translateX(-50%);
    display: inline-flex;
    align-items: baseline;
    gap: 4px;
    padding: 3px 8px;
    background: var(--ct-surface-lowest, #ffffff);
    border: 1px solid;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.02em;
    box-shadow: 0 4px 14px rgba(13, 20, 82,0.18);
    white-space: nowrap;
    pointer-events: none;
    animation: spark-tip-in 0.18s cubic-bezier(0.22, 1, 0.36, 1);
    z-index: 5;
}
.spark-tip-lbl {
    color: rgb(var(--ct-on-surface-variant, 100 116 139));
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
@keyframes spark-tip-in {
    from { opacity: 0; transform: translate(-50%, 3px); }
    to   { opacity: 1; transform: translate(-50%, 0);   }
}

/* Loading skeleton */
.spark-skeleton {
    position: relative;
    width: 100%;
    height: 100%;
    overflow: hidden;
    border-radius: 4px;
    background: linear-gradient(180deg,
        color-mix(in srgb, var(--c) 8%, transparent),
        color-mix(in srgb, var(--c) 2%, transparent));
}
.spark-shimmer {
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg,
        transparent 0%,
        rgba(255,255,255,0.4) 50%,
        transparent 100%);
    background-size: 220% 100%;
    animation: spark-skel 1.5s linear infinite;
}
.dark .spark-shimmer {
    background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.08) 50%, transparent 100%);
    background-size: 220% 100%;
}
@keyframes spark-skel {
    from { background-position: 200% 0; }
    to   { background-position: -200% 0; }
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .spark-line, .spark-area, .spark-dot, .spark-dot-ring, .spark-shimmer {
        animation: none !important;
    }
    .spark-line { stroke-dashoffset: 0 !important; }
    .spark-area { opacity: 1 !important; }
}
</style>

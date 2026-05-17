<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: {
        type: Number,
        default: 0,
    },
    max: {
        type: Number,
        default: 100,
    },
    size: {
        type: Number,
        default: 48,
    },
    strokeWidth: {
        type: Number,
        default: 4,
    },
    color: {
        type: String,
        default: 'blue',
        validator: (v) => ['blue', 'green', 'amber', 'red', 'violet'].includes(v),
    },
    label: {
        type: String,
        default: '',
    },
    showPercent: {
        type: Boolean,
        default: true,
    },
    // Legacy compat props (from old StatCard usage: used, total, color hex, stroke)
    used:   { type: Number, default: null },
    total:  { type: Number, default: null },
    stroke: { type: Number, default: null },
});

// Hex color map
const colorHex = {
    blue:   '#1a237e',
    green:  '#22c55e',
    amber:  '#f59e0b',
    red:    '#ef4444',
    violet: '#8b5cf6',
};

const strokeColor = computed(() => {
    // Support legacy hex string passed as color
    if (props.color && props.color.startsWith('#')) return props.color;
    return colorHex[props.color] ?? colorHex.blue;
});

const resolvedStrokeWidth = computed(() => props.stroke ?? props.strokeWidth);

// Legacy compat: if `used`/`total` provided, derive value/max
const resolvedValue = computed(() => props.used !== null ? props.used : props.value);
const resolvedMax   = computed(() => props.total !== null ? props.total : props.max);

// SVG geometry
const center = computed(() => props.size / 2);
const radius = computed(() => (props.size - resolvedStrokeWidth.value) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);

// Clamped percentage
const percent = computed(() => {
    if (resolvedMax.value <= 0) return 0;
    return Math.min(100, Math.max(0, (resolvedValue.value / resolvedMax.value) * 100));
});

const dashOffset = computed(() => {
    return circumference.value * (1 - percent.value / 100);
});

const displayText = computed(() => {
    if (props.label) return props.label;
    if (props.showPercent) return `${Math.round(percent.value)}%`;
    return '';
});

// Dynamic font size based on ring size
const fontSize = computed(() => {
    const s = props.size;
    if (s <= 32) return Math.max(6, s * 0.22);
    if (s <= 52) return Math.max(9, s * 0.20);
    return Math.max(11, s * 0.16);
});
</script>

<template>
    <div
        class="inline-flex items-center justify-center relative"
        :style="{ width: size + 'px', height: size + 'px' }"
        role="progressbar"
        :aria-valuenow="resolvedValue"
        :aria-valuemax="resolvedMax"
        :aria-valuemin="0"
        :aria-label="displayText || `${Math.round(percent)}%`"
    >
        <svg
            :width="size"
            :height="size"
            :viewBox="`0 0 ${size} ${size}`"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            class="block"
            aria-hidden="true"
        >
            <!-- SVG rotated -90deg so arc starts at top; text has counter-rotation applied -->
            <g :transform="`rotate(-90 ${center} ${center})`">
                <!-- Track circle -->
                <circle
                    :cx="center"
                    :cy="center"
                    :r="radius"
                    :stroke="strokeColor"
                    :stroke-width="resolvedStrokeWidth"
                    fill="none"
                    opacity="0.12"
                />

                <!-- Progress arc -->
                <circle
                    :cx="center"
                    :cy="center"
                    :r="radius"
                    :stroke="strokeColor"
                    :stroke-width="resolvedStrokeWidth"
                    fill="none"
                    stroke-linecap="round"
                    :stroke-dasharray="circumference"
                    :stroke-dashoffset="dashOffset"
                    style="transition: stroke-dashoffset 0.7s cubic-bezier(0.22, 1, 0.36, 1);"
                />
            </g>

            <!-- Center label â€” NOT rotated (in its own group at normal orientation) -->
            <text
                v-if="displayText"
                :x="center"
                :y="center"
                text-anchor="middle"
                dominant-baseline="central"
                :font-size="fontSize"
                font-weight="800"
                font-family="Open Sans, sans-serif"
                :fill="strokeColor"
            >
                {{ displayText }}
            </text>
        </svg>
    </div>
</template>

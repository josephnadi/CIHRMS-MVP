<script setup>
import { computed } from 'vue';

const props = defineProps({
    value:     { type: Number, default: 0 },     // 0-100
    color:     { type: String, default: '#1a237e' },
    trackColor:{ type: String, default: 'rgba(26, 35, 126,0.12)' },
    size:      { type: Number, default: 84 },
    stroke:    { type: Number, default: 8 },
    label:     { type: String, default: '' },
    loading:   { type: Boolean, default: false },
});

const radius      = computed(() => (props.size - props.stroke) / 2);
const circumf     = computed(() => 2 * Math.PI * radius.value);
const dashOffset  = computed(() => circumf.value * (1 - Math.max(0, Math.min(100, props.value)) / 100));

const uid    = Math.random().toString(36).slice(2, 8);
const gradId = `donut-grad-${uid}`;
const glowId = `donut-glow-${uid}`;
</script>

<template>
    <div class="donut" :style="{ width: size + 'px', height: size + 'px' }">
        <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" class="donut-svg">
            <defs>
                <linearGradient :id="gradId" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%"  :stop-color="color"/>
                    <stop offset="100%" :stop-color="color" stop-opacity="0.65"/>
                </linearGradient>
                <filter :id="glowId" x="-20%" y="-20%" width="140%" height="140%">
                    <feGaussianBlur stdDeviation="2" result="blur"/>
                    <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                </filter>
            </defs>

            <!-- Track -->
            <circle :cx="size/2" :cy="size/2" :r="radius"
                    fill="none" :stroke="trackColor" :stroke-width="stroke"
                    stroke-linecap="round"/>

            <!-- Value arc — rotates from 12 o'clock, animates dashoffset -->
            <circle
                :cx="size/2" :cy="size/2" :r="radius"
                fill="none"
                :stroke="`url(#${gradId})`"
                :stroke-width="stroke"
                stroke-linecap="round"
                :stroke-dasharray="circumf"
                :stroke-dashoffset="loading ? circumf : dashOffset"
                :filter="`url(#${glowId})`"
                class="donut-arc"
                :transform="`rotate(-90 ${size/2} ${size/2})`"
            />
        </svg>

        <div class="donut-center">
            <Transition name="donut-fade" mode="out-in">
                <div v-if="loading" key="loading" class="donut-skel"></div>
                <div v-else key="value" class="donut-vals">
                    <span class="donut-val" :style="{ color }">{{ Math.round(value) }}<small>%</small></span>
                    <span v-if="label" class="donut-lbl">{{ label }}</span>
                </div>
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.donut {
    position: relative;
    display: inline-block;
}
.donut-svg {
    display: block;
}
.donut-arc {
    transition: stroke-dashoffset 1.1s cubic-bezier(0.22, 1, 0.36, 1);
}

/* Center */
.donut-center {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.donut-vals {
    display: flex;
    flex-direction: column;
    align-items: center;
    line-height: 1;
}
.donut-val {
    font-size: 18px;
    font-weight: 900;
    letter-spacing: -0.02em;
    font-feature-settings: 'tnum' 1;
    line-height: 1;
}
.donut-val small {
    font-size: 0.55em;
    font-weight: 700;
    opacity: 0.7;
    margin-left: 1px;
}
.donut-lbl {
    margin-top: 3px;
    font-size: 8.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: rgb(var(--ct-on-surface-variant, 100 116 139));
}

/* Loading skeleton */
.donut-skel {
    width: 60%; height: 12px;
    border-radius: 3px;
    background: linear-gradient(90deg,
        rgba(0,0,0,0.06) 0%,
        rgba(0,0,0,0.10) 50%,
        rgba(0,0,0,0.06) 100%);
    background-size: 200% 100%;
    animation: donut-shimmer 1.4s linear infinite;
}
.dark .donut-skel {
    background: linear-gradient(90deg,
        rgba(255,255,255,0.06) 0%,
        rgba(255,255,255,0.12) 50%,
        rgba(255,255,255,0.06) 100%);
    background-size: 200% 100%;
}
@keyframes donut-shimmer {
    from { background-position: 200% 0; }
    to   { background-position: -200% 0; }
}

.donut-fade-enter-active, .donut-fade-leave-active {
    transition: opacity 0.25s ease;
}
.donut-fade-enter-from, .donut-fade-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .donut-arc { transition: none; }
    .donut-skel { animation: none; }
}
</style>

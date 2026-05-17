<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    data:        { type: Array,  default: () => [] },     // [{ label, value }] or [number, ...]
    color:       { type: String, default: '#1a237e' },     // brand action blue
    accentColor: { type: String, default: '#ffd700' },     // gold — applied to tallest bar (5% accent)
    secondColor: { type: String, default: '#12d9e3' },     // cyan — used for the live shimmer wave
    height:      { type: Number, default: 140 },
    barGap:      { type: Number, default: 6 },
    rounded:     { type: Number, default: 6 },
    showAxis:    { type: Boolean, default: true },
    showMedian:  { type: Boolean, default: true },         // analytical reference line
    showValues:  { type: Boolean, default: false },        // always-on labels above bars
    formatValue: { type: Function, default: (v) => Number(v).toLocaleString() },
    loading:     { type: Boolean, default: false },
});

const normalised = computed(() => {
    const raw = props.data.length ? props.data : [];
    return raw.map((item, i) => {
        if (typeof item === 'number') return { label: String(i + 1), value: item };
        return { label: item.label ?? String(i + 1), value: item.value ?? 0 };
    });
});

const maxValue   = computed(() => Math.max(...normalised.value.map(d => d.value), 1));
const meanValue  = computed(() => {
    if (!normalised.value.length) return 0;
    return normalised.value.reduce((s, d) => s + d.value, 0) / normalised.value.length;
});
const tallestIdx = computed(() =>
    normalised.value.reduce((best, d, i, arr) => d.value > arr[best].value ? i : best, 0));

const hoverIdx  = ref(null);
const pulseTick = ref(0);

// Detect value changes → trigger a live-stream pulse (cyan shimmer rolls across bars)
watch(() => normalised.value.map(d => d.value).join('|'), () => { pulseTick.value++; });

const barColor = (i) => i === tallestIdx.value ? props.accentColor : props.color;
const isTallest = (i) => i === tallestIdx.value;
</script>

<template>
    <div class="lb" :style="{ height: height + 'px' }">

        <!-- Loading skeleton -->
        <div v-if="loading" class="lb-skel">
            <div v-for="i in 12" :key="i"
                 class="lb-skel-bar"
                 :style="{ '--c': color, '--h': (30 + (i * 17) % 60) + '%', '--d': (i * 60) + 'ms' }"></div>
        </div>

        <template v-else>
            <!-- Median analytical reference -->
            <div v-if="showMedian && meanValue > 0"
                 class="lb-median"
                 :style="{ bottom: ((meanValue / maxValue) * 100) + '%' }">
                <span class="lb-median-tag">avg · {{ formatValue(Math.round(meanValue)) }}</span>
            </div>

            <!-- Bars -->
            <div class="lb-rail">
                <div v-for="(d, i) in normalised" :key="i"
                     class="lb-col"
                     :style="{ marginRight: (i < normalised.length - 1 ? barGap : 0) + 'px' }"
                     @mouseenter="hoverIdx = i"
                     @mouseleave="hoverIdx = null">

                    <!-- Hover tooltip -->
                    <Transition name="lb-tip">
                        <div v-if="hoverIdx === i" class="lb-tip"
                             :style="{
                                borderColor: barColor(i),
                                color: barColor(i),
                             }">
                            <span class="lb-tip-val">{{ formatValue(d.value) }}</span>
                            <span class="lb-tip-lbl">{{ d.label }}</span>
                            <span v-if="isTallest(i)" class="lb-tip-tag">peak</span>
                        </div>
                    </Transition>

                    <!-- Always-on value label -->
                    <span v-if="showValues" class="lb-val">{{ formatValue(d.value) }}</span>

                    <!-- The bar itself -->
                    <div class="lb-bar-wrap"
                         :style="{
                            height: ((d.value / maxValue) * 100) + '%',
                         }">
                        <div class="lb-bar"
                             :class="{ 'lb-bar--active': hoverIdx === i, 'lb-bar--peak': isTallest(i) }"
                             :style="{
                                background: `linear-gradient(180deg, ${barColor(i)} 0%, ${barColor(i)}a8 60%, ${barColor(i)}66 100%)`,
                                borderRadius: rounded + 'px ' + rounded + 'px 0 0',
                                animationDelay: (i * 60) + 'ms',
                             }">

                            <!-- Inner depth shading -->
                            <span class="lb-bar-inner"></span>

                            <!-- Top cap highlight -->
                            <span class="lb-bar-cap"
                                  :style="{ background: `linear-gradient(180deg, ${barColor(i)}ff 0%, transparent 100%)` }"></span>

                            <!-- Live stream shimmer — rolls across each bar in waves -->
                            <span class="lb-bar-stream"
                                  :key="pulseTick + '-' + i"
                                  :style="{
                                    '--shimmer': secondColor,
                                    animationDelay: (i * 40) + 'ms',
                                  }"></span>

                            <!-- Hover glow -->
                            <span class="lb-bar-glow"
                                  :style="{ background: barColor(i) }"></span>
                        </div>

                        <!-- Pulse dot at peak of tallest bar -->
                        <span v-if="isTallest(i)" class="lb-bar-peak-dot"
                              :style="{ background: accentColor, boxShadow: `0 0 10px ${accentColor}aa` }"></span>
                    </div>

                    <!-- Hover crosshair (vertical guide) -->
                    <Transition name="lb-cross">
                        <span v-if="hoverIdx === i" class="lb-cross"
                              :style="{ background: `linear-gradient(180deg, transparent, ${barColor(i)}55, transparent)` }"></span>
                    </Transition>

                    <!-- Axis label -->
                    <span v-if="showAxis" class="lb-lbl"
                          :class="{ 'lb-lbl--active': hoverIdx === i }"
                          :style="hoverIdx === i ? { color: barColor(i) } : null">{{ d.label }}</span>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.lb {
    position: relative;
    width: 100%;
    display: block;
}

/* ─── Bars ─────────────────────────────────────────────────────── */
.lb-rail {
    display: flex;
    align-items: flex-end;
    height: 100%;
    width: 100%;
}
.lb-col {
    position: relative;
    flex: 1;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
    min-width: 0;
    cursor: default;
}

/* Each bar's outer wrapper holds the height — keeps animation origin clean */
.lb-bar-wrap {
    position: relative;
    width: 100%;
    transition: height 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}

/* The actual bar — grow-from-bottom + scale on hover */
.lb-bar {
    position: relative;
    width: 100%;
    height: 100%;
    transform-origin: bottom;
    transform: scaleY(0);
    animation: lb-grow 0.95s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    transition:
        filter 0.18s ease,
        transform 0.18s cubic-bezier(0.22, 1, 0.36, 1);
    box-shadow:
        inset 0 -1px 0 rgba(0,0,0,0.10),
        inset 0 1px 0 rgba(255,255,255,0.08);
    overflow: hidden;
}
@keyframes lb-grow {
    to { transform: scaleY(1); }
}

.lb-bar:hover,
.lb-bar--active {
    filter: brightness(1.18) saturate(1.2);
    transform: scaleY(1) translateY(-3px);
}

/* Inner depth shading */
.lb-bar-inner {
    position: absolute; inset: 0;
    background: linear-gradient(105deg,
        rgba(255,255,255,0.08) 0%,
        rgba(255,255,255,0) 30%,
        rgba(0,0,0,0.06) 100%);
    pointer-events: none;
}

/* Top cap — bright highlight */
.lb-bar-cap {
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 16%;
    opacity: 0.45;
    pointer-events: none;
}

/* Live stream shimmer — cyan band rolls vertically through the bar */
.lb-bar-stream {
    position: absolute;
    top: -40%;
    left: 0; right: 0;
    height: 50%;
    background: linear-gradient(180deg,
        transparent 0%,
        var(--shimmer, #12d9e3) 50%,
        transparent 100%);
    opacity: 0;
    mix-blend-mode: screen;
    animation: lb-stream 2.6s cubic-bezier(0.45, 0, 0.55, 1) forwards;
    pointer-events: none;
}
@keyframes lb-stream {
    0%   { transform: translateY(-100%); opacity: 0; }
    25%  { opacity: 0.18; }
    60%  { opacity: 0.32; }
    100% { transform: translateY(220%); opacity: 0; }
}

/* Subtle glow halo behind active bar */
.lb-bar-glow {
    position: absolute;
    inset: -6px -2px -2px;
    border-radius: inherit;
    opacity: 0;
    filter: blur(12px);
    transition: opacity 0.2s ease;
    z-index: -1;
}
.lb-bar:hover .lb-bar-glow,
.lb-bar--active .lb-bar-glow,
.lb-bar--peak .lb-bar-glow {
    opacity: 0.42;
}

/* Pulsing gold dot above tallest bar */
.lb-bar-peak-dot {
    position: absolute;
    top: -7px; left: 50%;
    transform: translateX(-50%);
    width: 6px; height: 6px;
    border-radius: 99px;
    animation: lb-peak 2.2s cubic-bezier(0.22, 1, 0.36, 1) infinite;
    z-index: 2;
}
@keyframes lb-peak {
    0%, 100% { opacity: 0.6; transform: translateX(-50%) scale(1);   }
    50%      { opacity: 1.0; transform: translateX(-50%) scale(1.35); }
}

/* ─── Axis labels ──────────────────────────────────────────────── */
.lb-lbl {
    margin-top: 8px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: rgb(var(--ct-on-surface-variant, 100 116 139));
    text-align: center;
    line-height: 1;
    transition: color 0.15s ease;
}
.lb-lbl--active { font-weight: 900; }

.lb-val {
    position: absolute;
    bottom: calc(100% + 4px);
    left: 50%;
    transform: translateX(-50%);
    font-family: 'JetBrains Mono', monospace;
    font-size: 9.5px;
    font-weight: 800;
    color: rgb(var(--ct-on-surface-variant, 100 116 139));
}

/* ─── Hover guide ──────────────────────────────────────────────── */
.lb-cross {
    position: absolute;
    top: 0; bottom: 22px;
    left: 50%;
    width: 1px;
    transform: translateX(-50%);
    pointer-events: none;
    z-index: 1;
}
.lb-cross-enter-active, .lb-cross-leave-active { transition: opacity 0.18s ease; }
.lb-cross-enter-from, .lb-cross-leave-to     { opacity: 0; }

/* ─── Median reference line ────────────────────────────────────── */
.lb-median {
    position: absolute;
    left: 0; right: 0;
    height: 1px;
    border-top: 1px dashed rgba(13, 20, 82, 0.25);
    z-index: 1;
    pointer-events: none;
}
.dark .lb-median { border-top-color: rgba(255,255,255,0.18); }
.lb-median-tag {
    position: absolute;
    top: -7px;
    right: 0;
    padding: 1px 6px;
    background: rgb(var(--ct-surface-lowest, 255 255 255));
    border: 1px solid rgba(13, 20, 82, 0.18);
    border-radius: 99px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 8.5px;
    font-weight: 700;
    color: #0d1452;
    letter-spacing: 0.04em;
}
.dark .lb-median-tag {
    background: rgb(var(--ct-surface-low, 28 32 48));
    color: rgb(var(--ct-on-surface, 226 229 235));
    border-color: rgba(255,255,255,0.18);
}

/* ─── Tooltip ──────────────────────────────────────────────────── */
.lb-tip {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
    padding: 5px 10px;
    background: rgb(var(--ct-surface-lowest, 255 255 255));
    border: 1.5px solid;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    box-shadow: 0 8px 22px rgba(13, 20, 82,0.22), 0 2px 6px rgba(13, 20, 82,0.08);
    white-space: nowrap;
    pointer-events: none;
    z-index: 10;
}
.lb-tip::after {
    content: '';
    position: absolute;
    top: 100%; left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: currentColor;
    opacity: 0.85;
}
.lb-tip-val { line-height: 1.1; font-size: 12.5px; font-feature-settings: 'tnum' 1; }
.lb-tip-lbl {
    color: rgb(var(--ct-on-surface-variant, 100 116 139));
    font-size: 8.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-top: 1px;
}
.lb-tip-tag {
    margin-top: 2px;
    padding: 1px 6px;
    border-radius: 99px;
    background: rgba(255,215,0,0.16);
    color: #b88a08;
    font-size: 8px;
    font-weight: 900;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.lb-tip-enter-active, .lb-tip-leave-active {
    transition: opacity 0.16s ease, transform 0.18s cubic-bezier(0.22, 1, 0.36, 1);
}
.lb-tip-enter-from, .lb-tip-leave-to {
    opacity: 0; transform: translateX(-50%) translateY(4px);
}

/* ─── Loading skeleton ─────────────────────────────────────────── */
.lb-skel {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    width: 100%;
    height: 100%;
}
.lb-skel-bar {
    flex: 1;
    height: var(--h);
    background: linear-gradient(180deg,
        color-mix(in srgb, var(--c) 14%, transparent),
        color-mix(in srgb, var(--c) 4%, transparent));
    border-radius: 6px 6px 0 0;
    position: relative;
    overflow: hidden;
    transform-origin: bottom;
    transform: scaleY(0);
    animation: lb-grow 0.7s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    animation-delay: var(--d);
}
.lb-skel-bar::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.45), transparent);
    background-size: 220% 100%;
    animation: lb-shimmer 1.5s linear infinite;
    animation-delay: var(--d);
}
.dark .lb-skel-bar::after {
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.10), transparent);
    background-size: 220% 100%;
}
@keyframes lb-shimmer {
    from { background-position: 200% 0; }
    to   { background-position: -200% 0; }
}

/* ─── Reduced motion ───────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    .lb-bar         { animation: none; transform: scaleY(1); }
    .lb-skel-bar    { animation: none; transform: scaleY(1); }
    .lb-skel-bar::after,
    .lb-bar-stream,
    .lb-bar-peak-dot { animation: none; }
}
</style>

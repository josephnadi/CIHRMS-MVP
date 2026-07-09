<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import GlossaryText from '@/Components/GlossaryText.vue';

const props = defineProps({
    value:   { type: [String, Number], required: true },
    label:   { type: String,           required: true },
    icon:    { type: String,           required: true },
    color:   {
        type:    String,
        default: 'blue',
        validator: v => ['blue','green','amber','red','violet','cyan','gold','magenta','navy'].includes(v),
    },
    trend:   { type: String,  default: null },
    trendUp: { type: Boolean, default: true },
    loading: { type: Boolean, default: false },
    href:    { type: String,  default: null },
});

const colorMap = {
    blue:    { bg: 'bg-secondary/10',                style: 'color:#1a237e', rgb: '26,35,126' },
    navy:    { bg: '',                               style: 'background:rgba(13, 20, 82,0.10);color:#0d1452', rgb: '13,20,82' },
    green:   { bg: 'bg-green-500/10',                style: 'color:#059669', rgb: '5,150,105' },
    amber:   { bg: 'bg-amber-500/10',                style: 'color:#d97706', rgb: '217,119,6' },
    red:     { bg: 'bg-red-500/10',                  style: 'color:#dc2626', rgb: '220,38,38' },
    violet:  { bg: '',                               style: 'background:rgba(217,18,227,0.10);color:#d912e3', rgb: '217,18,227' },
    cyan:    { bg: '',                               style: 'background:rgba(18,217,227,0.12);color:#0e8a93', rgb: '14,138,147' },
    gold:    { bg: '',                               style: 'background:rgba(255,215,0,0.14);color:#b88a08', rgb: '184,138,8' },
    magenta: { bg: '',                               style: 'background:rgba(217,18,227,0.10);color:#d912e3', rgb: '217,18,227' },
};

const iconStyle = computed(() => colorMap[props.color] ?? colorMap.blue);
const accentRgb = computed(() => iconStyle.value.rgb ?? '26,35,126');

// ── Count-up animation for numeric values ───────────────────────────
// Renders the value from 0 to its target over ~700ms with an ease-out
// curve. Respects prefers-reduced-motion. Non-numeric values pass through
// unchanged (e.g. "—", "GHS 500.00", "Sun 31 May").
const displayValue = ref(props.value);
const isNumeric = (v) => typeof v === 'number' || /^[\d.]+$/.test(String(v ?? ''));

function animateTo(target) {
    if (! isNumeric(target)) { displayValue.value = target; return; }
    if (matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        displayValue.value = target;
        return;
    }
    const start = 0;
    const end = parseFloat(target);
    const decimals = String(target).includes('.') ? (String(target).split('.')[1].length || 0) : 0;
    const duration = 700;
    const startTime = performance.now();
    function frame(now) {
        const t = Math.min(1, (now - startTime) / duration);
        const eased = 1 - Math.pow(1 - t, 3); // ease-out cubic
        const v = start + (end - start) * eased;
        displayValue.value = decimals ? v.toFixed(decimals) : Math.round(v);
        if (t < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
}

onMounted(() => animateTo(props.value));
watch(() => props.value, (v) => animateTo(v));
</script>

<template>
    <component
        :is="href ? 'a' : 'div'"
        :href="href ?? undefined"
        :style="`--accent: ${accentRgb}`"
        class="group relative overflow-hidden rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-5 shadow-card block transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg hover:border-[rgb(var(--accent))]/30"
    >
        <!-- Decorative accent rail (left) — color-coded, fades in on hover -->
        <span
            class="pointer-events-none absolute inset-y-0 left-0 w-1 rounded-l-2xl bg-[rgb(var(--accent))] opacity-0 transition-opacity duration-300 group-hover:opacity-80"
        ></span>

        <!-- Decorative glow blob (top-right) — color-coded, fades in on hover -->
        <span
            class="pointer-events-none absolute -top-12 -right-12 h-32 w-32 rounded-full opacity-0 blur-2xl transition-opacity duration-500 group-hover:opacity-30"
            :style="`background: radial-gradient(closest-side, rgba(var(--accent), 0.45), transparent 70%)`"
        ></span>

        <!-- Loading skeleton -->
        <template v-if="loading">
            <div class="flex items-start justify-between">
                <div class="h-10 w-10 rounded-xl animate-pulse bg-surface-container-low"></div>
                <div class="h-5 w-14 rounded-full animate-pulse bg-surface-container-low"></div>
            </div>
            <div class="mt-4 h-8 w-24 rounded-lg animate-pulse bg-surface-container-low"></div>
            <div class="mt-2 h-3.5 w-32 rounded-md animate-pulse bg-surface-container-low"></div>
        </template>

        <!-- Content -->
        <template v-else>
            <div class="relative flex items-start justify-between">
                <!-- Icon — fills on hover, micro-rotate, subtle glow -->
                <div
                    :class="['h-10 w-10 rounded-xl flex items-center justify-center flex-shrink-0 transition-all duration-300 group-hover:scale-110 group-hover:rotate-3 group-hover:shadow-[0_4px_16px_-2px_rgba(var(--accent),0.45)]', iconStyle.bg]"
                    :style="iconStyle.style"
                >
                    <span class="material-symbols-outlined text-[20px] transition-all duration-300"
                          style="font-variation-settings:'FILL' 1">{{ icon }}</span>
                </div>

                <!-- Trend badge -->
                <span
                    v-if="trend"
                    :class="[
                        'flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[11px] font-bold transition-transform duration-300 group-hover:scale-105',
                        trendUp
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                            : 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
                    ]"
                >
                    <span class="material-symbols-outlined text-[13px]">
                        {{ trendUp ? 'trending_up' : 'trending_down' }}
                    </span>
                    {{ trend }}
                </span>
            </div>

            <!-- Value (count-up animated) -->
            <p class="relative mt-4 text-[28px] font-black text-on-surface tracking-tight leading-none tabular-nums">
                {{ displayValue }}
            </p>

            <!-- Label -->
            <p class="relative mt-1.5 text-[12px] font-semibold text-on-surface-variant">
                <GlossaryText :text="label" />
            </p>

            <!-- Thin accent bar at the bottom — slides in from left on hover -->
            <span
                class="pointer-events-none absolute bottom-0 left-0 h-0.5 w-0 bg-[rgb(var(--accent))] transition-all duration-500 group-hover:w-full"
            ></span>
        </template>
    </component>
</template>

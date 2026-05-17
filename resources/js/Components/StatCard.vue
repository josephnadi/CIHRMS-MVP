<script setup>
import { computed } from 'vue';

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
    blue:    { bg: 'bg-secondary/10',                style: 'color:#1a237e' },
    navy:    { bg: '',                               style: 'background:rgba(13, 20, 82,0.10);color:#0d1452' },
    green:   { bg: 'bg-green-500/10',                style: 'color:#059669' },
    amber:   { bg: 'bg-amber-500/10',                style: 'color:#d97706' },
    red:     { bg: 'bg-red-500/10',                  style: 'color:#dc2626' },
    violet:  { bg: '',                               style: 'background:rgba(217,18,227,0.10);color:#d912e3' },
    cyan:    { bg: '',                               style: 'background:rgba(18,217,227,0.12);color:#0e8a93' },
    gold:    { bg: '',                               style: 'background:rgba(255,215,0,0.14);color:#b88a08' },
    magenta: { bg: '',                               style: 'background:rgba(217,18,227,0.10);color:#d912e3' },
};

const iconStyle = computed(() => colorMap[props.color] ?? colorMap.blue);
</script>

<template>
    <component
        :is="href ? 'a' : 'div'"
        :href="href ?? undefined"
        class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 p-5 shadow-card card-lift block"
    >
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
            <div class="flex items-start justify-between">
                <!-- Icon -->
                <div :class="['h-10 w-10 rounded-xl flex items-center justify-center flex-shrink-0 transition-transform group-hover:scale-105', iconStyle.bg]"
                     :style="iconStyle.style">
                    <span class="material-symbols-outlined text-[20px]" style="font-variation-settings:'FILL' 1">{{ icon }}</span>
                </div>

                <!-- Trend badge -->
                <span
                    v-if="trend"
                    :class="[
                        'flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[11px] font-bold',
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

            <!-- Value -->
            <p class="mt-4 text-[28px] font-black text-on-surface tracking-tight leading-none">
                {{ value }}
            </p>

            <!-- Label -->
            <p class="mt-1.5 text-[12px] font-semibold text-on-surface-variant">
                {{ label }}
            </p>
        </template>
    </component>
</template>

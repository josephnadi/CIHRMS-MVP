<script setup>
import { computed } from 'vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
});

const visible = computed(() => Array.isArray(props.items) ? props.items : []);

const formatTime = (iso) => {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <div class="w-full">
        <p class="text-[10px] font-black uppercase tracking-[0.32em] text-white/35 mb-3 text-center">
            <span class="inline-block h-1.5 w-1.5 rounded-full bg-[#10b981] mr-2 align-middle animate-pulse"></span>
            Live · today's wall
        </p>
        <div v-if="visible.length === 0" class="text-center text-[13px] italic text-white/30">
            No punches yet today.
        </div>
        <div v-else class="flex flex-wrap items-center justify-center gap-2">
            <div
                v-for="(p, i) in visible"
                :key="i"
                class="inline-flex items-center gap-2 rounded-full bg-white/[0.05] border border-white/10 px-3 py-1.5 backdrop-blur-sm"
            >
                <span
                    class="material-symbols-outlined text-[14px]"
                    :class="p.direction === 'in' ? 'text-[#10b981]' : 'text-[#7cb6e8]'"
                    style="font-variation-settings:'FILL' 1"
                >{{ p.direction === 'in' ? 'login' : 'logout' }}</span>
                <span class="text-[13px] font-bold text-white">{{ p.first_name }}</span>
                <span class="font-mono text-[11px] text-white/55 tabular-nums">{{ formatTime(p.event_at) }}</span>
            </div>
        </div>
    </div>
</template>

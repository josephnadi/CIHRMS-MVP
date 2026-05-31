<script setup>
defineProps({
    variables: { type: Array, default: () => [] },
});

const ob = '{{';
const cb = '}}';
</script>

<template>
    <div class="rounded-xl border border-outline-variant/60 bg-surface-container-low p-4">
        <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-2">
            Available variables
        </p>
        <ul class="space-y-1.5">
            <li v-for="v in variables" :key="v" class="font-mono text-[12px] text-on-surface">
                <span class="text-primary">{{ ob }} {{ v }} {{ cb }}</span>
            </li>
        </ul>
        <p class="mt-3 text-[11px] text-on-surface-variant/70">
            Unknown variables render as empty. Click a variable to copy it.
        </p>
    </div>
</template>

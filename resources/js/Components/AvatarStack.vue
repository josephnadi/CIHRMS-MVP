<script setup>
import { computed } from 'vue';

const props = defineProps({
    users: {
        type: Array,
        default: () => [],
        // Each: { name: String, avatar?: String }
    },
    max: {
        type: Number,
        default: 4,
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
});

// Size map: pixel dimensions + text sizes
const sizeMap = {
    sm: { dim: 'w-6 h-6',   text: 'text-[9px]',  ring: 'ring-1',  overflow: 'w-6 h-6 text-[9px]'  },
    md: { dim: 'w-8 h-8',   text: 'text-[11px]', ring: 'ring-2',  overflow: 'w-8 h-8 text-[11px]' },
    lg: { dim: 'w-10 h-10', text: 'text-[13px]', ring: 'ring-2',  overflow: 'w-10 h-10 text-[13px]' },
};

// 6 rotating colors for initials background
const colorPalette = [
    'bg-blue-500',
    'bg-green-500',
    'bg-amber-500',
    'bg-blue-500',
    'bg-red-500',
    'bg-cyan-500',
];

function getColorClass(name) {
    // Simple hash from name chars
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = (hash * 31 + name.charCodeAt(i)) >>> 0;
    }
    return colorPalette[hash % colorPalette.length];
}

function getInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
}

const visibleUsers = computed(() => props.users.slice(0, props.max));
const overflowCount = computed(() => Math.max(0, props.users.length - props.max));

const sizeConfig = computed(() => sizeMap[props.size] ?? sizeMap.md);
</script>

<template>
    <div class="flex items-center">
        <!-- Visible avatars -->
        <div
            v-for="(user, index) in visibleUsers"
            :key="index"
            class="relative rounded-full ring-white dark:ring-sidebar flex items-center justify-center shrink-0 overflow-hidden"
            :class="[
                sizeConfig.dim,
                sizeConfig.ring,
                index > 0 ? '-ml-2' : '',
            ]"
            :title="user.name"
            style="transition: transform 0.15s ease;"
            @mouseenter="($event.currentTarget as HTMLElement).style.transform = 'scale(1.12) translateZ(0)'; ($event.currentTarget as HTMLElement).style.zIndex = '10';"
            @mouseleave="($event.currentTarget as HTMLElement).style.transform = ''; ($event.currentTarget as HTMLElement).style.zIndex = '';"
        >
            <!-- Photo avatar -->
            <img
                v-if="user.avatar"
                :src="user.avatar"
                :alt="user.name"
                class="w-full h-full object-cover rounded-full"
                loading="lazy"
            />
            <!-- Initials fallback -->
            <div
                v-else
                class="w-full h-full flex items-center justify-center text-white font-bold rounded-full select-none"
                :class="[getColorClass(user.name), sizeConfig.text]"
            >
                {{ getInitials(user.name) }}
            </div>
        </div>

        <!-- Overflow badge -->
        <div
            v-if="overflowCount > 0"
            class="relative rounded-full ring-white dark:ring-sidebar bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center shrink-0 font-bold -ml-2"
            :class="[sizeConfig.dim, sizeConfig.ring, sizeConfig.text]"
            :title="`${overflowCount} more: ${users.slice(max).map(u => u.name).join(', ')}`"
        >
            +{{ overflowCount }}
        </div>
    </div>
</template>

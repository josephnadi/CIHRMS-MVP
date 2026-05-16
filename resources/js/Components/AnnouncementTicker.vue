<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page  = usePage();
const items = computed(() => page.props.announcementTicker ?? []);

const paused   = ref(false);
const dismissed = ref(false);

const speedSec = computed(() => Math.max(28, items.value.length * 6));

const severityClass = (sev) => ({
    info:      'text-secondary',
    important: 'text-brand-gold-deep',
    urgent:    'text-brand-magenta',
}[sev] || 'text-on-surface-variant');

const typeAccentClass = (type) => ({
    notice:   'before:bg-secondary',
    event:    'before:bg-brand-cyan',
    birthday: 'before:bg-brand-magenta',
    task:     'before:bg-brand-gold',
    system:   'before:bg-on-surface-variant',
}[type] || 'before:bg-on-surface-variant/60');
</script>

<template>
    <div v-if="items.length && !dismissed"
         class="relative isolate flex h-9 w-full items-center overflow-hidden border-b border-outline-variant/60 bg-gradient-to-r from-surface-container-lowest via-surface-container-lowest to-surface-container-low"
         @mouseenter="paused = true"
         @mouseleave="paused = false">

        <!-- Leading label chip -->
        <div class="z-20 flex h-full items-center gap-2 border-r border-outline-variant/60 bg-brand-navy px-3 text-white">
            <span class="material-symbols-outlined text-[16px] text-brand-gold" style="font-variation-settings:'FILL' 1">campaign</span>
            <span class="text-[10px] font-black uppercase tracking-[0.15em]">Notice board</span>
        </div>

        <!-- Left fade -->
        <div class="pointer-events-none absolute left-[120px] top-0 z-10 h-full w-10 bg-gradient-to-r from-surface-container-lowest to-transparent"></div>

        <!-- Marquee track -->
        <div class="relative flex flex-1 overflow-hidden">
            <div class="flex shrink-0 items-center gap-7 whitespace-nowrap pl-6 pr-7 will-change-transform"
                 :class="paused ? 'tk-track tk-pause' : 'tk-track'"
                 :style="`--tk-duration:${speedSec}s`">
                <template v-for="item in items" :key="`a-${item.id}`">
                    <component :is="item.link_url ? 'a' : 'span'"
                               :href="item.link_url ?? undefined"
                               class="group relative inline-flex items-center gap-2 pl-3 text-[12.5px] font-medium text-on-surface
                                      before:absolute before:left-0 before:top-1/2 before:h-3 before:w-[2px] before:-translate-y-1/2 before:rounded-full
                                      transition-colors hover:text-secondary"
                               :class="typeAccentClass(item.type)">
                        <span class="material-symbols-outlined text-[15px]" :class="severityClass(item.severity)" style="font-variation-settings:'FILL' 1">{{ item.icon }}</span>
                        <span>{{ item.title }}</span>
                    </component>
                </template>
            </div>
            <!-- Duplicate track for seamless loop -->
            <div class="flex shrink-0 items-center gap-7 whitespace-nowrap pl-6 pr-7 will-change-transform"
                 :class="paused ? 'tk-track tk-pause' : 'tk-track'"
                 :style="`--tk-duration:${speedSec}s`"
                 aria-hidden="true">
                <template v-for="item in items" :key="`b-${item.id}`">
                    <component :is="item.link_url ? 'a' : 'span'"
                               :href="item.link_url ?? undefined"
                               class="group relative inline-flex items-center gap-2 pl-3 text-[12.5px] font-medium text-on-surface
                                      before:absolute before:left-0 before:top-1/2 before:h-3 before:w-[2px] before:-translate-y-1/2 before:rounded-full"
                               :class="typeAccentClass(item.type)">
                        <span class="material-symbols-outlined text-[15px]" :class="severityClass(item.severity)" style="font-variation-settings:'FILL' 1">{{ item.icon }}</span>
                        <span>{{ item.title }}</span>
                    </component>
                </template>
            </div>
        </div>

        <!-- Right fade -->
        <div class="pointer-events-none absolute right-9 top-0 z-10 h-full w-10 bg-gradient-to-l from-surface-container-lowest to-transparent"></div>

        <!-- Dismiss -->
        <button type="button" @click="dismissed = true"
                class="z-20 flex h-full w-9 items-center justify-center border-l border-outline-variant/60 text-on-surface-variant/60 transition-colors hover:bg-surface-container-low hover:text-on-surface"
                aria-label="Hide notice ticker">
            <span class="material-symbols-outlined text-[16px]">close</span>
        </button>
    </div>
</template>

<style scoped>
.tk-track {
    animation: tk-scroll var(--tk-duration, 40s) linear infinite;
}
.tk-pause {
    animation-play-state: paused;
}
@keyframes tk-scroll {
    from { transform: translateX(0); }
    to   { transform: translateX(-100%); }
}

@media (prefers-reduced-motion: reduce) {
    .tk-track { animation: none; }
}
</style>

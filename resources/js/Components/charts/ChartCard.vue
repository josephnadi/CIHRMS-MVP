<script setup>
import GlossaryText from '@/Components/GlossaryText.vue';

defineProps({
    title:     { type: String, default: '' },
    subtitle:  { type: String, default: '' },
    icon:      { type: String, default: '' },
    accent:    { type: String, default: '#1a237e' },
    isLive:    { type: Boolean, default: false },
    isSyncing: { type: Boolean, default: false },
    syncAgo:   { type: String, default: '' },
});

const emit = defineEmits(['refresh']);
</script>

<template>
    <article class="chart-card group">
        <!-- Header -->
        <header class="chart-card__head">
            <div class="chart-card__title-block">
                <div v-if="icon" class="chart-card__icon"
                     :style="{ background: `${accent}14`, color: accent }">
                    <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1">{{ icon }}</span>
                </div>
                <div>
                    <h3 class="chart-card__title"><GlossaryText :text="title" /></h3>
                    <p v-if="subtitle" class="chart-card__subtitle">{{ subtitle }}</p>
                </div>
            </div>

            <div class="chart-card__live" v-if="isLive">
                <span class="chart-card__pulse"
                      :class="{ 'is-syncing': isSyncing }"
                      :style="{ '--c': accent }">
                    <span class="chart-card__pulse-dot"></span>
                    <span class="chart-card__pulse-ring"></span>
                </span>
                <span class="chart-card__live-text">
                    {{ isSyncing ? 'syncing' : (syncAgo ? `live · ${syncAgo}` : 'live') }}
                </span>
                <button v-if="!isSyncing" type="button"
                        class="chart-card__refresh"
                        @click="emit('refresh')"
                        aria-label="Refresh">
                    <span class="material-symbols-outlined">refresh</span>
                </button>
            </div>
        </header>

        <!-- Body -->
        <div class="chart-card__body">
            <slot />
        </div>

        <!-- Footer slot (optional) -->
        <footer v-if="$slots.footer" class="chart-card__foot">
            <slot name="footer" />
        </footer>
    </article>
</template>

<style scoped>
.chart-card {
    position: relative;
    background: rgb(var(--ct-surface-lowest, 255 255 255));
    border: 1px solid rgb(var(--ct-outline-variant, 198 198 205) / 0.6);
    border-radius: 16px;
    overflow: hidden;
    transition: box-shadow 0.25s ease, transform 0.25s ease, border-color 0.2s ease;
}
.chart-card:hover {
    box-shadow: 0 12px 36px rgba(13, 20, 82,0.08), 0 2px 8px rgba(13, 20, 82,0.04);
    transform: translateY(-2px);
    border-color: rgb(var(--ct-outline-variant, 198 198 205) / 0.9);
}

/* Subtle top accent line (animated on hover) */
.chart-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, currentColor, transparent);
    color: var(--accent, transparent);
    opacity: 0;
    transition: opacity 0.25s ease;
}
.chart-card:hover::before { opacity: 0.7; }

/* Header */
.chart-card__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px 8px;
}
.chart-card__title-block {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.chart-card__icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    transition: transform 0.25s ease;
}
.chart-card:hover .chart-card__icon { transform: scale(1.06); }
.chart-card__icon .material-symbols-outlined { font-size: 18px; }
.chart-card__title {
    font-size: 13px;
    font-weight: 800;
    letter-spacing: -0.01em;
    color: rgb(var(--ct-primary, 10 38 71));
    line-height: 1.15;
    margin: 0;
}
.chart-card__subtitle {
    margin-top: 2px;
    font-size: 11px;
    font-weight: 500;
    color: rgb(var(--ct-on-surface-variant, 100 116 139));
    line-height: 1.2;
}

/* Live indicator */
.chart-card__live {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}
.chart-card__pulse {
    position: relative;
    width: 8px; height: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.chart-card__pulse-dot {
    width: 6px; height: 6px;
    background: var(--c);
    border-radius: 99px;
    box-shadow: 0 0 8px var(--c);
    position: relative;
    z-index: 1;
}
.chart-card__pulse-ring {
    position: absolute; inset: -2px;
    border-radius: 99px;
    border: 1.5px solid var(--c);
    opacity: 0.5;
    animation: cc-pulse 2.2s cubic-bezier(0.22, 1, 0.36, 1) infinite;
}
.chart-card__pulse.is-syncing .chart-card__pulse-dot {
    background: #12d9e3;
    box-shadow: 0 0 10px rgba(18,217,227,0.85);
}
@keyframes cc-pulse {
    0%   { transform: scale(0.6); opacity: 0.7; }
    80%  { transform: scale(2.2); opacity: 0; }
    100% { transform: scale(2.2); opacity: 0; }
}
.chart-card__live-text {
    font-family: 'JetBrains Mono', monospace;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: rgb(var(--ct-on-surface-variant, 100 116 139));
    white-space: nowrap;
}
.chart-card__refresh {
    width: 22px; height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: rgb(var(--ct-on-surface-variant, 100 116 139));
    background: transparent;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.2s ease, background 0.15s ease, color 0.15s ease;
}
.chart-card:hover .chart-card__refresh { opacity: 1; }
.chart-card__refresh:hover {
    color: rgb(var(--ct-primary, 10 38 71));
    background: rgb(var(--ct-surface-low, 242 244 246));
}
.chart-card__refresh .material-symbols-outlined { font-size: 14px; }

/* Body / footer */
.chart-card__body { padding: 6px 18px 16px; }
.chart-card__foot {
    padding: 10px 18px;
    border-top: 1px solid rgb(var(--ct-outline-variant, 198 198 205) / 0.4);
    background: rgb(var(--ct-surface-low, 242 244 246) / 0.4);
}

@media (prefers-reduced-motion: reduce) {
    .chart-card__pulse-ring { animation: none; }
}
</style>

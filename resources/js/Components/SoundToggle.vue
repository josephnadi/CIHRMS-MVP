<script setup>
import { ref, onBeforeUnmount, onMounted } from 'vue';
import { useSound } from '@/composables/useSound';

const {
    isMuted, toggleMute, setVolume, volume, play, presets,
    activePack, setPack, availablePacks,
} = useSound();
const open = ref(false);

const tryPreset = (k) => play(k);

const onVolume = (e) => setVolume(parseFloat(e.target.value));

const PACK_LABEL = {
    musical:   'Musical tones',
    cinematic: 'Real-world sounds',
    gamified:  'Gamified arcade',
};
const PACK_HINT = {
    musical:   'Abstract synthesised tones — minimal, unobtrusive.',
    cinematic: 'Doorbell, train horn, cash register, bell — like a real environment.',
    gamified:  'Arcade chiptune — longer notes, coin pickup, victory fanfares.',
};
const PACK_ICON = {
    musical:   'piano',
    cinematic: 'theater_comedy',
    gamified:  'stadia_controller',
};

const close = (e) => {
    if (!e.target.closest('.sfx-wrap')) open.value = false;
};
onMounted(() => document.addEventListener('click', close));
onBeforeUnmount(() => document.removeEventListener('click', close));
</script>

<template>
    <div class="sfx-wrap relative">
        <button type="button"
                class="sfx-btn"
                :class="isMuted ? 'is-muted' : 'is-live'"
                :title="isMuted ? 'Sound off — click to enable' : 'Sound on — click to mute'"
                @click.stop="toggleMute">
            <span class="material-symbols-outlined text-[19px]">
                {{ isMuted ? 'volume_off' : 'volume_up' }}
            </span>
            <!-- Live pulse when active -->
            <span v-if="!isMuted" class="sfx-pulse" aria-hidden="true"></span>
        </button>

        <!-- Settings popover -->
        <button type="button"
                class="sfx-gear"
                title="Sound settings"
                @click.stop="open = !open">
            <span class="material-symbols-outlined text-[12px]">tune</span>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <div v-if="open" class="sfx-pop">
                <div class="sfx-pop-head">
                    <span class="material-symbols-outlined text-[16px]" style="color:#1a237e">graphic_eq</span>
                    <span>Sound preferences</span>
                </div>

                <!-- Master toggle -->
                <label class="sfx-row">
                    <span class="sfx-label">Sound effects</span>
                    <button type="button"
                            class="sfx-switch"
                            :class="!isMuted ? 'is-on' : ''"
                            :aria-pressed="!isMuted"
                            @click="toggleMute">
                        <span class="sfx-switch-knob"></span>
                    </button>
                </label>

                <!-- Volume -->
                <div class="sfx-row sfx-row--col" :class="isMuted ? 'is-dim' : ''">
                    <div class="flex items-center justify-between w-full">
                        <span class="sfx-label">Volume</span>
                        <span class="sfx-vol-val">{{ Math.round(volume * 100) }}%</span>
                    </div>
                    <input aria-label="Sound effect volume" type="range" min="0" max="1" step="0.05"
                           class="sfx-range"
                           :value="volume"
                           :disabled="isMuted"
                           @input="onVolume"/>
                </div>

                <!-- Sound pack switcher -->
                <div class="sfx-pop-section" :class="isMuted ? 'is-dim' : ''">
                    <span class="sfx-section-label">Sound pack</span>
                    <div class="sfx-pack-grid">
                        <button v-for="pack in availablePacks" :key="pack"
                                type="button"
                                class="sfx-pack"
                                :class="{ 'is-active': activePack === pack }"
                                :disabled="isMuted"
                                @click="setPack(pack)">
                            <span class="material-symbols-outlined sfx-pack-icon">
                                {{ PACK_ICON[pack] ?? 'piano' }}
                            </span>
                            <span class="sfx-pack-label">{{ PACK_LABEL[pack] }}</span>
                            <span class="sfx-pack-hint">{{ PACK_HINT[pack] }}</span>
                        </button>
                    </div>
                </div>

                <!-- Preset previews -->
                <div class="sfx-pop-section">
                    <span class="sfx-section-label">Preview ({{ activePack }})</span>
                    <div class="sfx-presets">
                        <button v-for="k in presets" :key="k"
                                type="button"
                                class="sfx-preset"
                                :disabled="isMuted"
                                @click="tryPreset(k)">
                            <span class="material-symbols-outlined sfx-preset-icon">play_arrow</span>
                            {{ k }}
                        </button>
                    </div>
                </div>

                <p class="sfx-pop-note">
                    Sounds synthesised locally — no audio downloaded.
                    <span v-if="activePack === 'cinematic'">Real-world doorbell, train horn, bell, knock.</span>
                    Preference persists across sessions.
                </p>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.sfx-wrap { display: inline-flex; align-items: stretch; }

.sfx-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 36px;
    background: transparent;
    border: 1px solid rgb(var(--ct-outline-variant) / 0.55);
    border-radius: 10px 0 0 10px;
    color: rgb(var(--ct-on-surface-variant));
    cursor: pointer;
    transition: color 0.15s ease, background 0.15s ease, border-color 0.15s ease;
}
.sfx-btn:hover {
    color: #1a237e;
    background: rgb(var(--ct-surface-low));
    border-color: rgba(26, 35, 126,0.32);
}
.sfx-btn.is-muted   { color: rgb(var(--ct-on-surface-variant) / 0.6); }
.sfx-btn.is-live    { color: #1a237e; }
.sfx-btn.is-live::before {
    content: '';
    position: absolute;
    bottom: 4px; right: 4px;
    width: 6px; height: 6px;
    background: #12d9e3;
    border-radius: 99px;
    box-shadow: 0 0 8px rgba(18,217,227,0.7);
}
.sfx-pulse {
    position: absolute;
    bottom: 1px; right: 1px;
    width: 12px; height: 12px;
    border-radius: 99px;
    border: 1.5px solid rgba(18,217,227,0.5);
    animation: sfx-pulse 2s ease-out infinite;
    pointer-events: none;
}
@keyframes sfx-pulse {
    0%   { transform: scale(0.4); opacity: 0.7; }
    80%  { transform: scale(2.0); opacity: 0; }
    100% { transform: scale(2.0); opacity: 0; }
}

.sfx-gear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 36px;
    background: transparent;
    border: 1px solid rgb(var(--ct-outline-variant) / 0.55);
    border-left: none;
    border-radius: 0 10px 10px 0;
    color: rgb(var(--ct-on-surface-variant));
    cursor: pointer;
    transition: color 0.15s ease, background 0.15s ease;
}
.sfx-gear:hover {
    color: #1a237e;
    background: rgb(var(--ct-surface-low));
}

/* Popover */
.sfx-pop {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 280px;
    background: rgb(var(--ct-surface-lowest));
    border: 1px solid rgb(var(--ct-outline-variant) / 0.7);
    border-radius: 14px;
    box-shadow: 0 12px 36px rgba(13, 20, 82,0.18), 0 2px 8px rgba(13, 20, 82,0.06);
    padding: 12px 14px;
    z-index: 50;
}
.sfx-pop-head {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: rgb(var(--ct-primary));
    padding-bottom: 10px;
    border-bottom: 1px solid rgb(var(--ct-outline-variant) / 0.4);
    margin-bottom: 10px;
}
.sfx-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 0;
    gap: 12px;
}
.sfx-row--col {
    flex-direction: column;
    align-items: stretch;
    gap: 6px;
}
.sfx-row.is-dim { opacity: 0.45; }
.sfx-label {
    font-size: 12px;
    font-weight: 700;
    color: rgb(var(--ct-on-surface));
}
.sfx-vol-val {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10.5px;
    font-weight: 700;
    color: #1a237e;
}

/* Switch */
.sfx-switch {
    position: relative;
    width: 38px; height: 22px;
    background: rgb(var(--ct-outline-variant) / 0.7);
    border: none;
    border-radius: 99px;
    cursor: pointer;
    transition: background 0.2s ease;
}
.sfx-switch.is-on { background: linear-gradient(135deg, #1a237e, #3949ab); }
.sfx-switch-knob {
    position: absolute;
    top: 2px; left: 2px;
    width: 18px; height: 18px;
    background: #ffffff;
    border-radius: 99px;
    box-shadow: 0 2px 4px rgba(13, 20, 82,0.25);
    transition: transform 0.2s cubic-bezier(0.22, 1, 0.36, 1);
}
.sfx-switch.is-on .sfx-switch-knob { transform: translateX(16px); }

/* Range */
.sfx-range {
    -webkit-appearance: none;
    appearance: none;
    width: 100%;
    height: 4px;
    background: rgb(var(--ct-outline-variant) / 0.6);
    border-radius: 99px;
    outline: none;
}
.sfx-range::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 14px; height: 14px;
    background: #1a237e;
    border-radius: 99px;
    border: 2px solid #ffffff;
    box-shadow: 0 1px 4px rgba(13, 20, 82,0.3);
    cursor: pointer;
}
.sfx-range::-moz-range-thumb {
    width: 14px; height: 14px;
    background: #1a237e;
    border-radius: 99px;
    border: 2px solid #ffffff;
    cursor: pointer;
}

/* Preset previews */
.sfx-pop-section { padding-top: 8px; }
.sfx-section-label {
    display: block;
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: rgb(var(--ct-on-surface-variant));
    margin-bottom: 6px;
}
.sfx-presets {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 4px;
    max-height: 180px;
    overflow-y: auto;
    padding-right: 4px;
}
.sfx-preset {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 8px;
    background: rgb(var(--ct-surface-low));
    border: 1px solid transparent;
    border-radius: 7px;
    font-size: 10.5px;
    font-weight: 600;
    color: rgb(var(--ct-on-surface));
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: left;
}
.sfx-preset:hover:not(:disabled) {
    background: rgba(26, 35, 126,0.08);
    border-color: rgba(26, 35, 126,0.25);
    color: #1a237e;
}
.sfx-preset:disabled { opacity: 0.4; cursor: not-allowed; }
.sfx-preset-icon { font-size: 13px; color: #1a237e; }

/* Pack switcher — vertical stack so all three labels stay legible at 280px */
.sfx-pack-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 6px;
}
.sfx-pack {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    padding: 8px 10px;
    background: rgb(var(--ct-surface-low));
    border: 1px solid rgb(var(--ct-outline-variant) / 0.7);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: left;
    min-height: 70px;
}
.sfx-pack:hover:not(:disabled) {
    border-color: rgba(26, 35, 126,0.35);
    background: rgba(26, 35, 126,0.04);
}
.sfx-pack:disabled { opacity: 0.4; cursor: not-allowed; }
.sfx-pack.is-active {
    border-color: #1a237e;
    background: rgba(26, 35, 126,0.08);
    box-shadow: 0 0 0 1px rgba(26, 35, 126,0.25), 0 0 12px rgba(18,217,227,0.18);
}
.sfx-pack-icon {
    font-size: 16px;
    color: #1a237e;
    font-variation-settings: 'FILL' 1;
}
.sfx-pack.is-active .sfx-pack-icon {
    color: #ffd700;
}
.sfx-pack-label {
    font-size: 11px;
    font-weight: 800;
    color: rgb(var(--ct-on-surface));
    line-height: 1.2;
}
.sfx-pack-hint {
    font-size: 9.5px;
    font-weight: 500;
    color: rgb(var(--ct-on-surface-variant) / 0.8);
    line-height: 1.3;
}
.is-dim { opacity: 0.5; pointer-events: none; }

.sfx-pop-note {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid rgb(var(--ct-outline-variant) / 0.4);
    font-size: 10px;
    line-height: 1.4;
    color: rgb(var(--ct-on-surface-variant) / 0.8);
    font-style: italic;
}

@media (prefers-reduced-motion: reduce) {
    .sfx-pulse { animation: none; }
}
</style>

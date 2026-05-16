<script setup>
import { onMounted, ref } from 'vue';

defineProps({
    eyebrow: { type: String, default: 'CIHRM · Ghana' },
});

const mounted = ref(false);
onMounted(() => requestAnimationFrame(() => { mounted.value = true; }));
</script>

<template>
    <div class="sv-shell" :class="{ 'is-mounted': mounted }">

        <!-- ── Editorial column (left) · DEEP NAVY ─────────────────────── -->
        <aside class="sv-edit">

            <!-- Atmospheric mesh: navy base + faint cyan/magenta sparks -->
            <div class="sv-mesh" aria-hidden="true"></div>

            <!-- Grain overlay -->
            <div class="sv-grain" aria-hidden="true"></div>

            <!-- A single gold hairline — the 5% accent -->
            <div class="sv-hairline" aria-hidden="true"></div>

            <!-- Lockup -->
            <div class="sv-lockup">
                <span class="sv-mark" aria-hidden="true">
                    <svg viewBox="0 0 40 40" width="28" height="28">
                        <g fill="none" stroke="currentColor" stroke-width="1.4">
                            <rect x="3" y="3" width="34" height="34" rx="2" transform="rotate(45 20 20)"/>
                        </g>
                        <text x="20" y="25" text-anchor="middle"
                              font-family="Fraunces, serif" font-style="italic"
                              font-weight="500" font-size="18" fill="currentColor">C</text>
                    </svg>
                </span>
                <span class="sv-wordmark">CIHRM <span class="sv-wordmark-thin">Ghana</span></span>
            </div>

            <!-- Minimal display — one short line -->
            <h1 class="sv-display">
                Workforce, <em>registered.</em>
            </h1>

            <!-- Bottom institutional strip -->
            <footer class="sv-edit-foot">
                <span class="sv-foot-num">N° 2026</span>
                <span class="sv-foot-rule"></span>
                <span class="sv-foot-label">{{ eyebrow }}</span>
            </footer>
        </aside>

        <!-- ── Form panel (right) ─────────────────────────────────────── -->
        <main class="sv-panel">
            <div class="sv-panel-inner">
                <slot />
            </div>

            <footer class="sv-panel-foot">
                <span>© MMXXVI · CIHRM Ghana</span>
                <span class="sv-foot-dot">·</span>
                <a href="#" class="sv-foot-link">Charter</a>
                <span class="sv-foot-dot">·</span>
                <a href="#" class="sv-foot-link">Privacy</a>
            </footer>
        </main>
    </div>
</template>

<style scoped>
/* ────────────────────────────────────────────────────────────────────
   Sovereign Precision · auth shell
   Deep navy left (#0a2647) · clean ivory-white right.
   Gold appears only as ONE hairline + the CTA shimmer = ~5% of pixels.
   Cyan/magenta are atmospheric sparks in the mesh — barely there.
──────────────────────────────────────────────────────────────────── */

.sv-shell {
    --navy:        #0a2647;
    --navy-deep:   #06192f;
    --navy-soft:   #143a6e;
    --blue:        #205295;
    --blue-bright: #2c74b3;
    --gold:        #ffd700;
    --gold-deep:   #b88a08;
    --cyan:        #12d9e3;
    --magenta:     #d912e3;
    --ink-soft:    #5a6b80;

    position: relative;
    min-height: 100vh;
    background: #ffffff;
    color: #0a2647;
    font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;

    display: grid;
    grid-template-columns: minmax(0, 1fr);
    overflow: hidden;
}

@media (min-width: 960px) {
    .sv-shell { grid-template-columns: 1.1fr 1fr; }
}

/* ── Editorial column (left) ────────────────────────────────────── */
.sv-edit {
    position: relative;
    isolation: isolate;
    padding: 3.25rem 2.75rem 2.5rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 100vh;
    background: var(--navy);
    color: #eaf2ff;
    overflow: hidden;
}
@media (min-width: 960px) { .sv-edit { padding: 4rem 4rem 3rem; } }

/* Atmospheric blue mesh with whisper-quiet cyan & magenta sparks */
.sv-mesh {
    position: absolute; inset: 0; z-index: -2;
    background:
        radial-gradient(at 18% 22%, rgba(44,116,179,0.42) 0px, transparent 55%),
        radial-gradient(at 88% 12%, rgba(18,217,227,0.08) 0px, transparent 45%),
        radial-gradient(at 70% 95%, rgba(217,18,227,0.06) 0px, transparent 50%),
        radial-gradient(at 30% 85%, rgba(32,82,149,0.34) 0px, transparent 55%),
        linear-gradient(180deg, var(--navy) 0%, var(--navy-deep) 100%);
    opacity: 0;
    transition: opacity 1.2s ease;
}
.is-mounted .sv-mesh { opacity: 1; }

/* Subtle paper grain so the navy doesn't read flat */
.sv-grain {
    position: absolute; inset: 0; z-index: -1;
    pointer-events: none;
    opacity: 0.4;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='240'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 0.04 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    mix-blend-mode: screen;
}

/* The one gold accent: a single thin vertical hairline on the right edge */
.sv-hairline {
    position: absolute;
    top: 18%; bottom: 18%;
    right: 0;
    width: 1px;
    background: linear-gradient(180deg,
        transparent 0%,
        rgba(255,215,0,0.55) 30%,
        rgba(255,215,0,0.85) 50%,
        rgba(255,215,0,0.55) 70%,
        transparent 100%);
    transform-origin: top;
    transform: scaleY(0);
    animation: sv-scaleY 1.4s 0.7s cubic-bezier(0.22,1,0.36,1) forwards;
}

/* ── Lockup ─────────────────────────────────────────────────────── */
.sv-lockup {
    display: flex; align-items: center; gap: 0.7rem;
    color: #ffffff;
    opacity: 0;
    transform: translateY(-6px);
    animation: sv-rise 0.85s 0.15s cubic-bezier(0.22,1,0.36,1) forwards;
}
.sv-mark {
    width: 36px; height: 36px;
    display: grid; place-items: center;
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 4px;
    background: rgba(255,255,255,0.04);
    color: #ffffff;
}
.sv-wordmark {
    font-family: 'Fraunces', serif;
    font-size: 16px;
    font-weight: 500;
    letter-spacing: 0.01em;
    color: #ffffff;
}
.sv-wordmark-thin {
    font-weight: 300;
    font-style: italic;
    opacity: 0.7;
}

/* ── Display headline — one short line, generous space ─────────── */
.sv-display {
    font-family: 'Fraunces', serif;
    font-variation-settings: 'opsz' 144, 'SOFT' 0;
    font-weight: 350;
    font-size: clamp(2.4rem, 5.4vw, 4.2rem);
    line-height: 0.98;
    letter-spacing: -0.034em;
    color: #ffffff;
    margin: 0;
    max-width: 11ch;
    text-wrap: balance;
    opacity: 0;
    transform: translateY(14px);
    animation: sv-rise 1.1s 0.4s cubic-bezier(0.22,1,0.36,1) forwards;
}
.sv-display em {
    font-style: italic;
    font-variation-settings: 'opsz' 144, 'SOFT' 50, 'WONK' 1;
    color: #2c74b3;
    font-weight: 380;
}

/* ── Edit column footer ─────────────────────────────────────────── */
.sv-edit-foot {
    display: flex; align-items: center; gap: 0.85rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: 9.5px;
    font-weight: 500;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.6);
    opacity: 0;
    animation: sv-fade 1s 1.1s ease forwards;
}
.sv-foot-num   { color: var(--gold); font-weight: 700; }  /* gold accent — tiny */
.sv-foot-rule  { flex: 1; height: 1px; background: rgba(255,255,255,0.18); }
.sv-foot-label { color: rgba(255,255,255,0.85); }

/* ── Form panel (right) ─────────────────────────────────────────── */
.sv-panel {
    position: relative;
    background: #ffffff;
    padding: 4rem 2.5rem 1.75rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 100vh;
}
@media (min-width: 960px) { .sv-panel { padding: 5.5rem 4.5rem 1.75rem; } }

.sv-panel-inner {
    width: 100%;
    max-width: 420px;
    margin: 0 auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    opacity: 0;
    transform: translateY(12px);
    animation: sv-rise 1.05s 0.55s cubic-bezier(0.22,1,0.36,1) forwards;
}

.sv-panel-foot {
    margin: 2rem auto 0;
    display: flex; align-items: center; justify-content: center;
    gap: 0.45rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: 9.5px;
    font-weight: 500;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #6585a8;
    opacity: 0;
    animation: sv-fade 1s 1.3s ease forwards;
}
.sv-foot-dot { opacity: 0.5; }
.sv-foot-link {
    color: #5a6b80;
    text-decoration: none;
    border-bottom: 1px solid transparent;
    transition: border-color 0.2s ease, color 0.2s ease;
}
.sv-foot-link:hover { color: #0a2647; border-bottom-color: #205295; }

/* ── Animations ─────────────────────────────────────────────────── */
@keyframes sv-rise   { to { opacity: 1; transform: translateY(0); } }
@keyframes sv-fade   { to { opacity: 1; } }
@keyframes sv-scaleY { to { transform: scaleY(1); } }

/* ── Reduced motion ─────────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    .sv-mesh, .sv-hairline, .sv-lockup, .sv-display,
    .sv-edit-foot, .sv-panel-inner, .sv-panel-foot {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
    }
}

/* ── Small screens — hide the navy column, form takes full width ── */
@media (max-width: 959px) {
    .sv-edit { display: none; }
    .sv-panel { min-height: 100vh; padding: 3rem 1.75rem; }
}
</style>

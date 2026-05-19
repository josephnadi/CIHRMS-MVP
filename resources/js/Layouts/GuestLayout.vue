<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({
    eyebrow: { type: String, default: 'CIHRM · Ghana' },
});

const mounted = ref(false);

// ── Brand typewriter ─────────────────────────────────────────────────────────
// Cycles between the acronym and the full institute name on a loop:
// type → hold → erase → switch → type. The blinking caret is a separate
// interval so it keeps pulsing during holds. Honors prefers-reduced-motion
// by collapsing to a static "CIHRM" string with no caret animation.
const PHRASES = ['CIHRM', 'Chartered Institute of Human Resource Management'];
const TYPE_MS  = 65;
const ERASE_MS = 32;
const HOLD_MS  = 2200;
const GAP_MS   = 380;

const brandText    = ref('');
const caretVisible = ref(true);

let typeTimer  = null;
let caretTimer = null;

onMounted(() => {
    requestAnimationFrame(() => { mounted.value = true; });

    const reduced = typeof window !== 'undefined'
        && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    if (reduced) {
        brandText.value = PHRASES[0];
        return;
    }

    let phraseIdx = 0;
    let charIdx   = 0;
    let phase     = 'typing'; // 'typing' | 'holding' | 'erasing' | 'gap'

    const tick = () => {
        const target = PHRASES[phraseIdx];

        if (phase === 'typing') {
            charIdx += 1;
            brandText.value = target.slice(0, charIdx);
            if (charIdx >= target.length) {
                phase = 'holding';
                typeTimer = setTimeout(tick, HOLD_MS);
                return;
            }
            typeTimer = setTimeout(tick, TYPE_MS);
            return;
        }

        if (phase === 'holding') {
            phase = 'erasing';
            typeTimer = setTimeout(tick, ERASE_MS);
            return;
        }

        if (phase === 'erasing') {
            charIdx -= 1;
            brandText.value = target.slice(0, Math.max(0, charIdx));
            if (charIdx <= 0) {
                phase = 'gap';
                phraseIdx = (phraseIdx + 1) % PHRASES.length;
                typeTimer = setTimeout(tick, GAP_MS);
                return;
            }
            typeTimer = setTimeout(tick, ERASE_MS);
            return;
        }

        // gap
        phase   = 'typing';
        charIdx = 0;
        typeTimer = setTimeout(tick, TYPE_MS);
    };

    typeTimer  = setTimeout(tick, 600);
    caretTimer = setInterval(() => { caretVisible.value = !caretVisible.value; }, 530);
});

onBeforeUnmount(() => {
    if (typeTimer)  clearTimeout(typeTimer);
    if (caretTimer) clearInterval(caretTimer);
});
</script>

<template>
    <div class="sv-shell" :class="{ 'is-mounted': mounted }">

        <!-- WCAG 2.4.1 + 4.1.3 — accessibility primitives, first in the DOM. -->
        <SkipLink />
        <AriaLiveAnnouncer />

        <!-- â”€â”€ Editorial column (left) Â· DEEP NAVY â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <aside class="sv-edit">

            <!-- Atmospheric mesh: navy base + faint cyan/magenta sparks -->
            <div class="sv-mesh" aria-hidden="true"></div>

            <!-- Grain overlay -->
            <div class="sv-grain" aria-hidden="true"></div>

            <!-- A single gold hairline â€” the 5% accent -->
            <div class="sv-hairline" aria-hidden="true"></div>

            <!-- Lockup — typewriter that cycles between the acronym and the
                 full institute name. Static fallback "CIHRM" under prefers-
                 reduced-motion. The aria-label tells screen readers the full
                 brand once; the visible animation is decorative thereafter. -->
            <h2 class="sv-brand" aria-label="CIHRM — Chartered Institute of Human Resource Management">
                <span class="sv-brand-text">{{ brandText }}</span><span
                    class="sv-brand-caret"
                    :class="{ 'is-hidden': !caretVisible }"
                    aria-hidden="true"
                >|</span>
            </h2>

            <!-- Minimal display â€” one short line -->
            <h1 class="sv-display">
                Workforce, <em>registered.</em>
            </h1>

            <!-- Bottom institutional strip -->
            <footer class="sv-edit-foot">
                <span class="sv-foot-num">NÂ° 2026</span>
                <span class="sv-foot-rule"></span>
                <span class="sv-foot-label">{{ eyebrow }}</span>
            </footer>
        </aside>

        <!-- â”€â”€ Form panel (right) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <main id="main-content" tabindex="-1" class="sv-panel">
            <div class="sv-panel-inner">
                <slot />
            </div>

            <footer class="sv-panel-foot">
                <span>Â© MMXXVI Â· CIHRM Ghana</span>
                <span class="sv-foot-dot">Â·</span>
                <a href="#" class="sv-foot-link">Charter</a>
                <span class="sv-foot-dot">Â·</span>
                <a href="#" class="sv-foot-link">Privacy</a>
            </footer>
        </main>
    </div>
</template>

<style scoped>
/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   Sovereign Precision Â· auth shell
   Deep navy left (#0d1452) Â· clean ivory-white right.
   Gold appears only as ONE hairline + the CTA shimmer = ~5% of pixels.
   Cyan/magenta are atmospheric sparks in the mesh â€” barely there.
â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.sv-shell {
    --navy:        #0d1452;
    --navy-deep:   #070b3a;
    --navy-soft:   #143a6e;
    --blue:        #1a237e;
    --blue-bright: #3949ab;
    --gold:        #ffd700;
    --gold-deep:   #b88a08;
    --cyan:        #12d9e3;
    --magenta:     #d912e3;
    --ink-soft:    #5a6b80;

    position: relative;
    min-height: 100vh;
    background: #ffffff;
    color: #0d1452;
    font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;

    display: grid;
    grid-template-columns: minmax(0, 1fr);
    overflow: hidden;
}

@media (min-width: 960px) {
    .sv-shell { grid-template-columns: 1.1fr 1fr; }
}

/* â”€â”€ Editorial column (left) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.sv-edit {
    position: relative;
    isolation: isolate;
    padding: 3.25rem 2.75rem 5.5rem;          /* extra bottom padding leaves room for the absolutely-positioned footer */
    display: flex;
    flex-direction: column;
    justify-content: center;                  /* brand + headline cluster vertically centered */
    align-items: center;                       /* and horizontally centered */
    text-align: center;
    gap: 1.25rem;                              /* tight gap between the typewriter and the headline */
    min-height: 100vh;
    background: var(--navy);
    color: #eaf2ff;
    overflow: hidden;
}
@media (min-width: 960px) { .sv-edit { padding: 4rem 4rem 6rem; } }

/* Atmospheric blue mesh with whisper-quiet cyan & magenta sparks */
.sv-mesh {
    position: absolute; inset: 0; z-index: -2;
    background:
        radial-gradient(at 18% 22%, rgba(57, 73, 171,0.42) 0px, transparent 55%),
        radial-gradient(at 88% 12%, rgba(18,217,227,0.08) 0px, transparent 45%),
        radial-gradient(at 70% 95%, rgba(217,18,227,0.06) 0px, transparent 50%),
        radial-gradient(at 30% 85%, rgba(26, 35, 126,0.34) 0px, transparent 55%),
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

/* ── Brand typewriter ───────────────────────────────────────────────────────
   Bold display of "CIHRM" that animates open into the full institute name and
   back, with a blinking gold caret. Centered in the column; the headline
   immediately below sits tight against it. */
.sv-brand {
    margin: 0;
    color: #ffffff;
    font-weight: 900;
    font-size: clamp(1.7rem, 2.6vw, 2.35rem);
    line-height: 1.12;
    letter-spacing: -0.025em;
    text-wrap: balance;
    text-align: center;
    min-height: 2.5em;       /* enough room for the long phrase wrapping to 2 lines */
    max-width: 26ch;
    opacity: 0;
    transform: translateY(-6px);
    animation: sv-rise 0.85s 0.15s cubic-bezier(0.22,1,0.36,1) forwards;
}
.sv-brand-text {
    background: linear-gradient(180deg, #ffffff 0%, #cfd9ff 100%);
    -webkit-background-clip: text;
            background-clip: text;
    -webkit-text-fill-color: transparent;
            color: transparent;
}
.sv-brand-caret {
    display: inline-block;
    margin-left: 0.06em;
    color: #ffd700;            /* gold — the 5% accent reused */
    font-weight: 300;
    transition: opacity 0.06s linear;
}
.sv-brand-caret.is-hidden { opacity: 0; }

/* ── Display headline — one short line, generous space ─────────────── */
.sv-display {
    font-weight: 350;
    font-size: clamp(2.4rem, 5.4vw, 4.2rem);
    line-height: 0.98;
    letter-spacing: -0.034em;
    color: #ffffff;
    margin: 0;
    max-width: 11ch;
    text-align: center;
    text-wrap: balance;
    opacity: 0;
    transform: translateY(14px);
    animation: sv-rise 1.1s 0.4s cubic-bezier(0.22,1,0.36,1) forwards;
}
.sv-display em {
    font-style: italic;
    color: #3949ab;
    font-weight: 380;
}

/* ── Edit column footer ─────────────────────────────────────────────── */
.sv-edit-foot {
    position: absolute;
    left: 2.75rem;
    right: 2.75rem;
    bottom: 2.5rem;
    display: flex; align-items: center; gap: 0.85rem;
    font-size: 9.5px;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.6);
    opacity: 0;
    animation: sv-fade 1s 1.1s ease forwards;
}
@media (min-width: 960px) {
    .sv-edit-foot { left: 4rem; right: 4rem; bottom: 3rem; }
}
.sv-foot-num   { color: var(--gold); font-weight: 700; }  /* gold accent â€” tiny */
.sv-foot-rule  { flex: 1; height: 1px; background: rgba(255,255,255,0.18); }
.sv-foot-label { color: rgba(255,255,255,0.85); }

/* â”€â”€ Form panel (right) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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
    font-size: 9.5px;
    font-weight: 600;
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
.sv-foot-link:hover { color: #0d1452; border-bottom-color: #1a237e; }

/* â”€â”€ Animations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
@keyframes sv-rise   { to { opacity: 1; transform: translateY(0); } }
@keyframes sv-fade   { to { opacity: 1; } }
@keyframes sv-scaleY { to { transform: scaleY(1); } }

/* â”€â”€ Reduced motion â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
@media (prefers-reduced-motion: reduce) {
    .sv-mesh, .sv-hairline, .sv-brand, .sv-display,
    .sv-edit-foot, .sv-panel-inner, .sv-panel-foot {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
    }
    .sv-brand-caret { display: none; }
}

/* â”€â”€ Small screens â€” hide the navy column, form takes full width â”€â”€ */
@media (max-width: 959px) {
    .sv-edit { display: none; }
    .sv-panel { min-height: 100vh; padding: 3rem 1.75rem; }
}
</style>

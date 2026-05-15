<script setup>
import { onMounted, ref } from 'vue';

defineProps({
    accent:  { type: String, default: 'gold' },
    eyebrow: { type: String, default: 'CIHRM Ghana · Charter MMXXIV' },
});

const mounted = ref(false);
onMounted(() => requestAnimationFrame(() => { mounted.value = true; }));

// Headline split into words for stagger reveal
const headlineWords = ['HR', 'with', 'the', 'dignity', 'of', 'a', 'charter.'];
</script>

<template>
    <div class="dm-shell" :data-accent="accent" :class="{ 'is-mounted': mounted }">

        <!-- Paper grain overlay (sits above gradient, below content) -->
        <div class="dm-grain" aria-hidden="true"></div>

        <!-- Decorative concentric arcs — one bold motif, low opacity -->
        <svg class="dm-arc" aria-hidden="true" viewBox="0 0 600 600">
            <defs>
                <linearGradient id="dm-arc-grad" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#0a1f5c" stop-opacity="0.10"/>
                    <stop offset="1" stop-color="#5b9fd9" stop-opacity="0.06"/>
                </linearGradient>
            </defs>
            <g fill="none" stroke="url(#dm-arc-grad)" stroke-width="1.4">
                <circle cx="300" cy="300" r="60"/>
                <circle cx="300" cy="300" r="120"/>
                <circle cx="300" cy="300" r="190"/>
                <circle cx="300" cy="300" r="270"/>
            </g>
            <g fill="none" stroke="#f29111" stroke-width="1.2" opacity="0.34">
                <path d="M 60 300 A 240 240 0 0 1 540 300" stroke-dasharray="3 7"/>
            </g>
        </svg>

        <!-- ── Editorial column (left) ────────────────────────────────────── -->
        <aside class="dm-edit">
            <!-- Top row: monogram lockup -->
            <div class="dm-lockup">
                <span class="dm-plate" aria-hidden="true">
                    <!-- Inline crest: chartered diamond + serif C -->
                    <svg viewBox="0 0 48 48" width="32" height="32" aria-hidden="true">
                        <g fill="none" stroke="currentColor" stroke-width="1.4">
                            <rect x="3" y="3" width="42" height="42" rx="2" transform="rotate(45 24 24)"/>
                            <rect x="9" y="9" width="30" height="30" rx="2" transform="rotate(45 24 24)"/>
                        </g>
                        <text x="24" y="30" text-anchor="middle"
                              font-family="Fraunces, serif" font-style="italic" font-weight="700"
                              font-size="22" fill="currentColor">C</text>
                    </svg>
                </span>
                <div class="dm-lockup-text">
                    <p class="dm-lockup-name">Chartered Institute of Human Resource <span class="dm-lockup-italic">Management</span></p>
                    <p class="dm-lockup-meta">{{ eyebrow }} · Accra · Ghana</p>
                </div>
            </div>

            <!-- Editorial display headline -->
            <h1 class="dm-display">
                <span class="dm-display-line">
                    <template v-for="(w, i) in headlineWords" :key="i">
                        <span class="dm-word" :style="`--i:${i}`">
                            <em v-if="i === 5">{{ w }}</em>
                            <template v-else-if="i === 6">{{ w.replace('.', '') }}<em class="dm-stop">.</em></template>
                            <template v-else>{{ w }}</template>
                        </span>
                    </template>
                </span>
            </h1>

            <!-- Standfirst -->
            <p class="dm-stand">
                A unified register for the Institute's workforce — leave, payroll, recruitment, governance —
                administered with the deliberate restraint of a public charter, not a SaaS tool.
            </p>

            <!-- Footnote rule + meta strip -->
            <div class="dm-rule"></div>
            <div class="dm-meta">
                <span><span class="dm-meta-num">§ I.</span> Mandate · Act 1020 of 2024</span>
                <span><span class="dm-meta-num">§ II.</span> Patrons · Council of Trustees</span>
                <span><span class="dm-meta-num">§ III.</span> Records · 1,284 in active register</span>
            </div>

            <!-- Quotation pull -->
            <figure class="dm-quote">
                <blockquote>
                    "An institution is the lengthened shadow of a single
                    <em>discipline,</em> kept honest by its records."
                </blockquote>
                <figcaption>— Charter preamble, transcribed from Council minutes, 2024.</figcaption>
            </figure>
        </aside>

        <!-- ── Form panel (right) ─────────────────────────────────────────── -->
        <main class="dm-panel">
            <div class="dm-panel-inner">
                <slot />
            </div>

            <!-- Bottom institutional strip -->
            <footer class="dm-footer">
                <div class="dm-footer-rule"></div>
                <div class="dm-footer-row">
                    <span>© MMXXVI · CIHRM Ghana</span>
                    <span class="dm-footer-dot">·</span>
                    <a href="#" class="dm-footer-link">Charter</a>
                    <span class="dm-footer-dot">·</span>
                    <a href="#" class="dm-footer-link">Privacy</a>
                    <span class="dm-footer-dot">·</span>
                    <a href="#" class="dm-footer-link">Code of conduct</a>
                </div>
            </footer>
        </main>
    </div>
</template>

<style scoped>
/* ────────────────────────────────────────────────────────────────────
   Diasporic Modern · auth aesthetic
   Ivory paper · midnight ink · sunrise gold · kente coral (in reserve)
──────────────────────────────────────────────────────────────────── */

.dm-shell {
    --paper:        #f4efe6;
    --paper-deep:   #ece4d4;
    --ink:          #0a1f5c;
    --ink-soft:     #475569;
    --gold:         #f29111;
    --gold-deep:    #b56a0a;
    --coral:        #d62782;
    --olive:        #5b9fd9;

    position: relative;
    min-height: 100vh;
    background:
        radial-gradient(1200px 700px at 18% 20%, #faf6ec 0%, var(--paper) 55%, var(--paper-deep) 110%);
    color: var(--ink);
    font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
    overflow: hidden;

    display: grid;
    grid-template-columns: minmax(0, 1fr);
}

@media (min-width: 960px) {
    .dm-shell { grid-template-columns: 1.15fr 1fr; }
}

/* Paper grain overlay */
.dm-grain {
    position: absolute;
    inset: 0;
    pointer-events: none;
    opacity: 0.55;
    z-index: 1;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='240'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.92' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 0.06  0 0 0 0 0.10  0 0 0 0 0.17  0 0 0 0.05 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}

/* Concentric arc motif */
.dm-arc {
    position: absolute;
    left: -12vw;
    top: 50%;
    transform: translateY(-50%) rotate(-12deg);
    width: 80vh;
    max-width: 920px;
    height: auto;
    pointer-events: none;
    z-index: 0;
    color: var(--ink);
    transition: transform 1.4s cubic-bezier(0.22, 1, 0.36, 1);
}
.is-mounted .dm-arc { transform: translateY(-50%) rotate(0deg); }

@media (min-width: 960px) {
    .dm-arc { left: 2vw; width: 92vh; }
}

/* ── Editorial column ────────────────────────────────────────────── */
.dm-edit {
    position: relative;
    z-index: 2;
    padding: 4rem 3rem 3rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 100vh;
    max-width: 720px;
}
@media (min-width: 960px) { .dm-edit { padding: 5rem 4.5rem 4rem; } }

.dm-lockup { display: flex; align-items: flex-start; gap: 0.95rem; opacity: 0; transform: translateY(-6px); animation: dm-rise 0.8s 0.05s cubic-bezier(0.22,1,0.36,1) forwards; }
.dm-plate {
    flex-shrink: 0;
    color: var(--ink);
    width: 44px; height: 44px;
    display: grid; place-items: center;
    border: 1px solid color-mix(in srgb, var(--ink) 18%, transparent);
    border-radius: 6px;
    background: rgba(255,255,255,0.4);
}
.dm-lockup-text { padding-top: 2px; }
.dm-lockup-name {
    font-family: 'Fraunces', serif;
    font-size: 13.5px;
    font-weight: 500;
    letter-spacing: -0.005em;
    color: var(--ink);
    line-height: 1.25;
    max-width: 28ch;
}
.dm-lockup-italic { font-style: italic; font-weight: 400; }
.dm-lockup-meta {
    margin-top: 4px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 9.5px;
    font-weight: 500;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ink-soft);
}

/* ── Display headline ───────────────────────────────────────────── */
.dm-display {
    font-family: 'Fraunces', serif;
    font-variation-settings: 'opsz' 144, 'SOFT' 0, 'WONK' 0;
    font-weight: 360;
    font-size: clamp(2.6rem, 6.4vw, 5.4rem);
    line-height: 0.96;
    letter-spacing: -0.038em;
    color: var(--ink);
    margin: 2.4rem 0 1.8rem;
    text-wrap: balance;
}
.dm-display-line { display: block; }
.dm-word {
    display: inline-block;
    margin-right: 0.22em;
    opacity: 0;
    transform: translateY(14px);
    animation: dm-rise 0.95s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    animation-delay: calc(0.18s + var(--i) * 0.07s);
}
.dm-display em {
    font-style: italic;
    font-variation-settings: 'opsz' 144, 'SOFT' 60, 'WONK' 1;
    color: var(--gold-deep);
    font-weight: 380;
}
.dm-stop {
    color: var(--coral);
    font-style: normal;
    font-weight: 500;
    margin-left: 0.04em;
}

/* ── Standfirst ─────────────────────────────────────────────────── */
.dm-stand {
    max-width: 44ch;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 14.5px;
    font-weight: 400;
    line-height: 1.55;
    color: var(--ink-soft);
    letter-spacing: 0.005em;
    opacity: 0;
    transform: translateY(8px);
    animation: dm-rise 1.1s 0.85s cubic-bezier(0.22,1,0.36,1) forwards;
}

/* ── Hairline rule + meta strip ─────────────────────────────────── */
.dm-rule {
    margin: 2.4rem 0 1rem;
    height: 1px;
    width: 64%;
    background: linear-gradient(to right, var(--ink) 0, var(--ink) 28%, transparent 100%);
    opacity: 0.4;
    transform-origin: left;
    transform: scaleX(0);
    animation: dm-scaleX 1s 1.05s cubic-bezier(0.22,1,0.36,1) forwards;
}
.dm-meta {
    display: flex; flex-wrap: wrap; gap: 1.6rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: 10.5px; font-weight: 500;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--ink-soft);
    opacity: 0;
    animation: dm-fade 1s 1.2s ease forwards;
}
.dm-meta-num { color: var(--gold-deep); margin-right: 6px; font-weight: 700; }

/* ── Pull quote ─────────────────────────────────────────────────── */
.dm-quote {
    margin: 2.6rem 0 0;
    border-left: 2px solid var(--gold);
    padding: 0.4rem 0 0.4rem 1.2rem;
    max-width: 46ch;
    opacity: 0;
    transform: translateX(-6px);
    animation: dm-rise-x 1s 1.4s cubic-bezier(0.22,1,0.36,1) forwards;
}
.dm-quote blockquote {
    font-family: 'Fraunces', serif;
    font-variation-settings: 'opsz' 36;
    font-weight: 380;
    font-size: 17.5px;
    line-height: 1.42;
    color: var(--ink);
    letter-spacing: -0.01em;
    quotes: "\201C" "\201D";
}
.dm-quote em { font-style: italic; color: var(--gold-deep); font-variation-settings: 'opsz' 36, 'WONK' 1; }
.dm-quote figcaption {
    margin-top: 0.6rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: 9.5px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--ink-soft);
    opacity: 0.8;
}

/* ── Form panel (right) ─────────────────────────────────────────── */
.dm-panel {
    position: relative;
    z-index: 2;
    background:
        linear-gradient(180deg, rgba(255,255,255,0.6) 0, rgba(255,255,255,0.85) 100%);
    border-left: 1px solid color-mix(in srgb, var(--ink) 8%, transparent);
    padding: 4rem 3rem 1.5rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 100vh;
}
@media (min-width: 960px) { .dm-panel { padding: 5.5rem 4.5rem 1.75rem; } }

.dm-panel-inner {
    width: 100%;
    max-width: 420px;
    margin: 0 auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    opacity: 0;
    transform: translateY(12px);
    animation: dm-rise 1.05s 0.4s cubic-bezier(0.22,1,0.36,1) forwards;
}

/* ── Footer ─────────────────────────────────────────────────────── */
.dm-footer {
    margin-top: 2.5rem;
    opacity: 0;
    animation: dm-fade 1s 1.5s ease forwards;
}
.dm-footer-rule {
    height: 1px;
    background: linear-gradient(to right, transparent, color-mix(in srgb, var(--ink) 18%, transparent), transparent);
    margin-bottom: 0.9rem;
}
.dm-footer-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: center;
    gap: 0.45rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: 9.5px;
    font-weight: 500;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ink-soft);
}
.dm-footer-dot { opacity: 0.4; }
.dm-footer-link {
    color: var(--ink-soft);
    text-decoration: none;
    border-bottom: 1px solid transparent;
    transition: border-color 0.2s ease, color 0.2s ease;
}
.dm-footer-link:hover { color: var(--ink); border-bottom-color: var(--gold); }

/* ── Animations ─────────────────────────────────────────────────── */
@keyframes dm-rise {
    to { opacity: 1; transform: translateY(0); }
}
@keyframes dm-rise-x {
    to { opacity: 1; transform: translateX(0); }
}
@keyframes dm-scaleX {
    to { transform: scaleX(1); }
}
@keyframes dm-fade {
    to { opacity: 1; }
}

/* ── Reduced motion ─────────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    .dm-arc { transition: none; }
    .dm-word, .dm-lockup, .dm-stand, .dm-rule, .dm-meta,
    .dm-quote, .dm-panel-inner, .dm-footer {
        animation: none !important;
        opacity: 1 !important;
        transform: none !important;
    }
}

/* Hide editorial column on small screens — form panel takes full width */
@media (max-width: 959px) {
    .dm-edit { display: none; }
    .dm-panel { border-left: none; min-height: 100vh; padding: 3rem 1.75rem; }
}
</style>

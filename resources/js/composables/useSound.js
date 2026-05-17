/**
 * useSound — Web Audio-synthesised UI sound effects with pluggable sound packs.
 *
 *   import { useSound } from '@/composables/useSound';
 *   const { play, mute, unmute, isMuted, setVolume, setPack } = useSound();
 *   play('task.completed');
 *
 * SOUND PACKS — switch via setPack('musical'|'cinematic'):
 *  • 'musical'   — pleasant abstract tones, the original CIHRMS palette
 *  • 'cinematic' — real-world sound approximations (doorbell, train horn,
 *                  cash register, glass clink, etc.) via cinematic-synth.js
 *
 * FILE OVERRIDES — drop an MP3/OGG at `public/sounds/<pack>/<key>.{mp3,ogg}`
 * and useSound will prefer the real audio file over its synthesised fallback.
 * See docs/sound_pack_sources.md for curated CC0 audio sources.
 *
 * Behaviour:
 *  • Respects a persistent mute toggle (localStorage 'sfx.muted')
 *  • Respects a persistent volume (localStorage 'sfx.volume', 0-1)
 *  • Respects a persistent pack choice (localStorage 'sfx.pack')
 *  • Caches a single AudioContext lazily after first user gesture
 *  • Throttles identical events within 600ms so toast bursts stay pleasant
 *  • Lazily fetches & decodes audio files on first request; cached thereafter
 */

import { ref, reactive, computed } from 'vue';
import { CINEMATIC_SYNTHS } from '@/utils/cinematic-synth';

const LS_MUTE   = 'sfx.muted';
const LS_VOLUME = 'sfx.volume';
const LS_PACK   = 'sfx.pack';

// ── Reactive state shared across composable consumers ────────────
const isMuted    = ref(localStorage.getItem(LS_MUTE) === '1');
const volume     = ref(Math.min(1, Math.max(0, parseFloat(localStorage.getItem(LS_VOLUME) ?? '0.45'))));
// Default to the cinematic pack — real-world doorbell / train horn / cash
// register / chime are the requested institutional feel. Users who prefer
// the older musical tones can switch via the SoundToggle popover.
const activePack = ref(localStorage.getItem(LS_PACK) || 'cinematic');
const stats      = reactive({ lastPlayedAt: 0, lastKey: '' });

// Decoded audio buffer cache, keyed by `${pack}/${eventKey}`.
const bufferCache = new Map();
// Files we've already tried and failed to load — never retry.
const failedFiles = new Set();

let ctx = null;
const getCtx = () => {
    if (ctx) return ctx;
    const Klass = window.AudioContext || window.webkitAudioContext;
    if (!Klass) return null;
    try { ctx = new Klass(); } catch { ctx = null; }
    return ctx;
};

// Resume context after user gesture (autoplay policy)
const resume = () => {
    const c = getCtx();
    if (c && c.state === 'suspended') c.resume().catch(() => {});
};
if (typeof window !== 'undefined') {
    const once = () => {
        resume();
        window.removeEventListener('click',    once);
        window.removeEventListener('keydown',  once);
        window.removeEventListener('touchend', once);
    };
    window.addEventListener('click',    once, { passive: true });
    window.addEventListener('keydown',  once);
    window.addEventListener('touchend', once, { passive: true });
}

// ── Tone synthesiser ──────────────────────────────────────────────
/**
 * A tone is a list of notes: { freq, start, dur, gain?, type?, slideTo? }
 * - freq    Hz (or 0 for silence/rest)
 * - start   seconds from now
 * - dur     seconds duration
 * - gain    peak gain (default 0.4 * master)
 * - type    oscillator type ('sine' | 'triangle' | 'square' | 'sawtooth')
 * - slideTo optional target freq for a glide
 */
function playSequence(notes, master = 1) {
    const c = getCtx();
    if (!c) return;
    if (c.state === 'suspended') c.resume().catch(() => {});

    const now = c.currentTime;
    const out = c.createGain();
    out.gain.value = master * volume.value;
    out.connect(c.destination);

    // Soft low-pass to round the synthesised edges — keeps tones musical
    const filt = c.createBiquadFilter();
    filt.type = 'lowpass';
    filt.frequency.value = 4200;
    filt.Q.value = 0.6;
    filt.connect(out);

    notes.forEach(n => {
        if (!n.freq) return;
        const osc = c.createOscillator();
        const g   = c.createGain();
        osc.type = n.type ?? 'sine';
        osc.frequency.setValueAtTime(n.freq, now + n.start);
        if (n.slideTo) {
            osc.frequency.exponentialRampToValueAtTime(
                Math.max(40, n.slideTo),
                now + n.start + n.dur,
            );
        }

        // ADSR envelope — short attack, gentle decay, fast release
        const peak = (n.gain ?? 0.4);
        g.gain.setValueAtTime(0, now + n.start);
        g.gain.linearRampToValueAtTime(peak, now + n.start + 0.012);
        g.gain.exponentialRampToValueAtTime(peak * 0.4, now + n.start + n.dur * 0.5);
        g.gain.exponentialRampToValueAtTime(0.0001, now + n.start + n.dur);

        osc.connect(g).connect(filt);
        osc.start(now + n.start);
        osc.stop(now + n.start + n.dur + 0.02);
    });
}

// ── MUSICAL preset library ────────────────────────────────────────
// Pleasant abstract tones. Frequencies kept inside a pentatonic-adjacent
// range so close repeats stay consonant. Every preset includes a
// sustained tail tone — it's the long fade at the end that makes a UI
// sound feel "musical" instead of "blippy".
const MUSICAL_PRESETS = {
    // Generic notification — gentle 3-note ping with sustained bell tail
    'notification': [
        { freq: 880,  start: 0.00, dur: 0.18, type: 'sine',     gain: 0.40 },
        { freq: 1175, start: 0.10, dur: 0.24, type: 'triangle', gain: 0.38 },
        { freq: 1568, start: 0.22, dur: 0.32, type: 'sine',     gain: 0.34 },
        { freq: 1175, start: 0.42, dur: 0.85, type: 'sine',     gain: 0.26 },  // sustained tail
        { freq: 587,  start: 0.42, dur: 0.85, type: 'sine',     gain: 0.16 },  // lower harmony
    ],

    // Toast success — full C major arpeggio with chord tail
    'success': [
        { freq: 523,  start: 0.00, dur: 0.18, type: 'sine',     gain: 0.34 },
        { freq: 659,  start: 0.10, dur: 0.18, type: 'sine',     gain: 0.34 },
        { freq: 784,  start: 0.20, dur: 0.20, type: 'sine',     gain: 0.36 },
        { freq: 988,  start: 0.30, dur: 0.28, type: 'triangle', gain: 0.40 },
        { freq: 1319, start: 0.44, dur: 0.95, type: 'sine',     gain: 0.32 },  // sustain
        { freq: 659,  start: 0.44, dur: 0.95, type: 'sine',     gain: 0.18 },  // root drone
        { freq: 988,  start: 0.44, dur: 0.95, type: 'sine',     gain: 0.16 },  // 5th drone
    ],

    // Toast error — descending minor cluster with low rumble tail
    'error': [
        { freq: 466,  start: 0.00, dur: 0.18, type: 'triangle', gain: 0.40 },
        { freq: 392,  start: 0.12, dur: 0.22, type: 'triangle', gain: 0.38 },
        { freq: 311,  start: 0.26, dur: 0.32, type: 'sine',     gain: 0.36, slideTo: 246 },
        { freq: 220,  start: 0.42, dur: 0.95, type: 'sine',     gain: 0.30 },  // low sustain
        { freq: 165,  start: 0.42, dur: 0.95, type: 'triangle', gain: 0.18 },  // sub harmony
    ],

    // Toast warning — three-note hold with sustained drone
    'warning': [
        { freq: 587,  start: 0.00, dur: 0.24, type: 'triangle', gain: 0.36 },
        { freq: 494,  start: 0.18, dur: 0.26, type: 'sine',     gain: 0.34 },
        { freq: 392,  start: 0.36, dur: 0.30, type: 'triangle', gain: 0.32 },
        { freq: 294,  start: 0.56, dur: 1.00, type: 'sine',     gain: 0.26 },  // sustained tail
        { freq: 587,  start: 0.56, dur: 1.00, type: 'sine',     gain: 0.14 },  // octave above
    ],

    // New event created — calendar chime: ascending 5-note flourish with bell ring
    'event.created': [
        { freq: 698,  start: 0.00, dur: 0.16, type: 'sine',     gain: 0.32 },
        { freq: 880,  start: 0.10, dur: 0.16, type: 'sine',     gain: 0.34 },
        { freq: 1047, start: 0.20, dur: 0.20, type: 'triangle', gain: 0.36 },
        { freq: 1319, start: 0.32, dur: 0.24, type: 'sine',     gain: 0.36 },
        { freq: 1568, start: 0.46, dur: 0.30, type: 'triangle', gain: 0.34 },
        { freq: 1047, start: 0.62, dur: 1.05, type: 'sine',     gain: 0.28 },  // bell sustain
        { freq: 1568, start: 0.62, dur: 1.05, type: 'sine',     gain: 0.18 },  // shimmer overtone
    ],

    // Assigned to you — distinctive "you've got mail" cadence with hold
    'assigned.you': [
        { freq: 698,  start: 0.00, dur: 0.14, type: 'triangle', gain: 0.38 },
        { freq: 932,  start: 0.10, dur: 0.14, type: 'triangle', gain: 0.38 },
        { freq: 1175, start: 0.20, dur: 0.22, type: 'sine',     gain: 0.40 },
        { freq: 1397, start: 0.34, dur: 0.20, type: 'triangle', gain: 0.40 },
        { freq: 1175, start: 0.50, dur: 0.28, type: 'sine',     gain: 0.36 },
        { freq: 932,  start: 0.68, dur: 0.34, type: 'sine',     gain: 0.32 },
        { freq: 698,  start: 0.90, dur: 1.10, type: 'sine',     gain: 0.28 },  // hold
        { freq: 1397, start: 0.90, dur: 1.10, type: 'sine',     gain: 0.16 },  // shimmer
    ],

    // Task completed — full celebratory flourish + chord tail
    'task.completed': [
        { freq: 523,  start: 0.00, dur: 0.14, type: 'triangle', gain: 0.36 },
        { freq: 659,  start: 0.10, dur: 0.14, type: 'triangle', gain: 0.36 },
        { freq: 784,  start: 0.20, dur: 0.14, type: 'triangle', gain: 0.36 },
        { freq: 988,  start: 0.30, dur: 0.14, type: 'sine',     gain: 0.38 },
        { freq: 1319, start: 0.42, dur: 0.18, type: 'sine',     gain: 0.40 },
        { freq: 1568, start: 0.56, dur: 0.22, type: 'triangle', gain: 0.40 },
        { freq: 1976, start: 0.72, dur: 0.32, type: 'sine',     gain: 0.40 },
        // Chord triad sustain
        { freq: 1319, start: 0.92, dur: 1.20, type: 'sine',     gain: 0.30 },
        { freq: 1568, start: 0.92, dur: 1.20, type: 'sine',     gain: 0.22 },
        { freq: 988,  start: 0.92, dur: 1.20, type: 'sine',     gain: 0.20 },
        { freq: 659,  start: 0.92, dur: 1.20, type: 'sine',     gain: 0.14 },
    ],

    // New message / chat — gentle double-blip with soft tail
    'message': [
        { freq: 1175, start: 0.00, dur: 0.12, type: 'sine',     gain: 0.36 },
        { freq: 1319, start: 0.12, dur: 0.16, type: 'sine',     gain: 0.36 },
        { freq: 1568, start: 0.26, dur: 0.22, type: 'triangle', gain: 0.32 },
        { freq: 1175, start: 0.42, dur: 0.80, type: 'sine',     gain: 0.22 },
    ],

    // Announcement / broadcast — bell-like with long resonant decay
    'announcement': [
        { freq: 880,  start: 0.00, dur: 0.32, type: 'triangle', gain: 0.36 },
        { freq: 1320, start: 0.06, dur: 0.40, type: 'sine',     gain: 0.30 },
        { freq: 1760, start: 0.18, dur: 0.46, type: 'sine',     gain: 0.28 },
        // Long ringing tail simulating a bell's overtone series
        { freq: 880,  start: 0.34, dur: 1.40, type: 'sine',     gain: 0.32 },
        { freq: 1760, start: 0.34, dur: 1.40, type: 'sine',     gain: 0.18 },
        { freq: 2637, start: 0.34, dur: 1.20, type: 'sine',     gain: 0.10 },  // top shimmer
        { freq: 440,  start: 0.34, dur: 1.40, type: 'sine',     gain: 0.16 },  // sub octave
    ],

    // Button submit — a satisfying click with brief tail
    'submit': [
        { freq: 660,  start: 0.00, dur: 0.06, type: 'sine',     gain: 0.26 },
        { freq: 880,  start: 0.04, dur: 0.12, type: 'sine',     gain: 0.28 },
        { freq: 660,  start: 0.14, dur: 0.40, type: 'sine',     gain: 0.18 },
    ],

    // Form validation fail — muted bonk with dampened tail
    'invalid': [
        { freq: 220,  start: 0.00, dur: 0.18, type: 'triangle', gain: 0.36 },
        { freq: 165,  start: 0.12, dur: 0.26, type: 'sine',     gain: 0.32 },
        { freq: 110,  start: 0.28, dur: 0.75, type: 'sine',     gain: 0.24 },
    ],

    // Approval granted — warm rise with ka-chink and sustain
    'approved': [
        { freq: 523,  start: 0.00, dur: 0.10, type: 'sine',     gain: 0.30 },
        { freq: 698,  start: 0.08, dur: 0.14, type: 'sine',     gain: 0.34 },
        { freq: 988,  start: 0.18, dur: 0.20, type: 'triangle', gain: 0.38 },
        { freq: 1175, start: 0.32, dur: 0.28, type: 'sine',     gain: 0.36 },
        { freq: 988,  start: 0.50, dur: 1.05, type: 'sine',     gain: 0.30 },  // sustain
        { freq: 1568, start: 0.50, dur: 1.05, type: 'sine',     gain: 0.16 },
    ],

    // Rejected / declined — disappointed descending cadence with low tail
    'rejected': [
        { freq: 466,  start: 0.00, dur: 0.18, type: 'triangle', gain: 0.36 },
        { freq: 415,  start: 0.14, dur: 0.20, type: 'sine',     gain: 0.34 },
        { freq: 311,  start: 0.30, dur: 0.28, type: 'sine',     gain: 0.32, slideTo: 246 },
        { freq: 220,  start: 0.50, dur: 0.40, type: 'triangle', gain: 0.30, slideTo: 175 },
        { freq: 175,  start: 0.84, dur: 0.95, type: 'sine',     gain: 0.24 },  // low sustain
    ],

    // Sign-in / welcome — five-note rising fanfare with chord hold
    'welcome': [
        { freq: 523,  start: 0.00, dur: 0.22, type: 'sine',     gain: 0.32 },
        { freq: 659,  start: 0.16, dur: 0.22, type: 'sine',     gain: 0.32 },
        { freq: 784,  start: 0.32, dur: 0.24, type: 'sine',     gain: 0.34 },
        { freq: 1047, start: 0.50, dur: 0.30, type: 'triangle', gain: 0.36 },
        { freq: 1319, start: 0.72, dur: 0.40, type: 'sine',     gain: 0.38 },
        // Held major chord on top
        { freq: 1047, start: 1.00, dur: 1.40, type: 'sine',     gain: 0.30 },
        { freq: 1319, start: 1.00, dur: 1.40, type: 'sine',     gain: 0.22 },
        { freq: 1568, start: 1.00, dur: 1.40, type: 'sine',     gain: 0.20 },
        { freq: 523,  start: 1.00, dur: 1.40, type: 'sine',     gain: 0.16 },
    ],

    // Sign-out — gentle descending farewell with held minor tail
    'goodbye': [
        { freq: 784,  start: 0.00, dur: 0.20, type: 'sine',     gain: 0.32 },
        { freq: 659,  start: 0.16, dur: 0.22, type: 'sine',     gain: 0.32 },
        { freq: 523,  start: 0.32, dur: 0.26, type: 'sine',     gain: 0.32 },
        { freq: 392,  start: 0.50, dur: 0.34, type: 'triangle', gain: 0.30 },
        { freq: 261,  start: 0.78, dur: 1.10, type: 'sine',     gain: 0.26 },  // sustain
        { freq: 523,  start: 0.78, dur: 1.10, type: 'sine',     gain: 0.14 },  // octave shimmer
    ],
};

// ── CINEMATIC preset library ──────────────────────────────────────
// Maps event keys to a synth function in cinematic-synth.js plus its
// per-event options. Designed to feel like real-world sounds: a doorbell
// for a notification, a cash-register chime for payroll-disbursed, a train
// horn for a major broadcast, a wood knock for an error, etc.
//
// Each event also has an optional `file` field — the relative path under
// `public/sounds/cinematic/` that, if present on disk, plays instead of
// the synth. This lets the design team drop in real audio assets without
// touching code (the URL is the contract).
const CINEMATIC_PRESETS = {
    notification:     { kind: 'doorbell',     file: 'notification.mp3' },
    success:          { kind: 'bell',         opts: { freq: 1175, decayMul: 0.9 }, file: 'success.mp3' },
    error:            { kind: 'woodKnock',    opts: { pitch: 'low'  },              file: 'error.mp3' },
    warning:          { kind: 'trainHorn',    opts: { distant: true, duration: 1.2 }, file: 'warning.mp3' },
    'event.created':  { kind: 'bell',         opts: { freq: 1568, decayMul: 0.7 }, file: 'event-created.mp3' },
    'assigned.you':   { kind: 'emailPop',     file: 'assigned.mp3' },
    'task.completed': { kind: 'cashRegister', file: 'task-completed.mp3' },
    message:          { kind: 'phoneRing',    opts: { bursts: 1 },                  file: 'message.mp3' },
    announcement:     { kind: 'stationBell',  file: 'announcement.mp3' },
    submit:           { kind: 'woodKnock',    opts: { pitch: 'high' },              file: 'submit.mp3' },
    invalid:          { kind: 'buzzer',       opts: { low: false },                 file: 'invalid.mp3' },
    approved:         { kind: 'bell',         opts: { freq: 880, decayMul: 1.3 },   file: 'approved.mp3' },
    rejected:         { kind: 'buzzer',       opts: { low: true },                  file: 'rejected.mp3' },
    welcome:          { kind: 'stationBell',  file: 'welcome.mp3' },
    goodbye:          { kind: 'trainHorn',    opts: { duration: 1.4, chordHz: [110, 165] }, file: 'goodbye.mp3' },
};

// ── Audio-file loader ─────────────────────────────────────────────
// Returns the decoded AudioBuffer (cached) or null if the file is
// missing / fails to load. Network errors are silent — the synth
// fallback takes over.
async function tryLoadFile(pack, file) {
    const cacheKey = `${pack}/${file}`;
    if (bufferCache.has(cacheKey)) return bufferCache.get(cacheKey);
    if (failedFiles.has(cacheKey)) return null;

    const c = getCtx();
    if (!c) return null;

    try {
        const r = await fetch(`/sounds/${pack}/${file}`);
        if (!r.ok) { failedFiles.add(cacheKey); return null; }
        const arr = await r.arrayBuffer();
        const buf = await c.decodeAudioData(arr);
        bufferCache.set(cacheKey, buf);
        return buf;
    } catch {
        failedFiles.add(cacheKey);
        return null;
    }
}

function playBuffer(buf, master = 1) {
    const c = getCtx();
    if (!c) return;
    if (c.state === 'suspended') c.resume().catch(() => {});

    const src = c.createBufferSource();
    src.buffer = buf;

    const g = c.createGain();
    g.gain.value = master * volume.value;

    src.connect(g).connect(c.destination);
    src.start(c.currentTime);
}

function playCinematic(preset, master = 1) {
    const c = getCtx();
    if (!c) return;
    if (c.state === 'suspended') c.resume().catch(() => {});

    const synth = CINEMATIC_SYNTHS[preset.kind];
    if (!synth) {
        console.warn(`[useSound] unknown cinematic kind: ${preset.kind}`);
        return;
    }

    // Master gain bus so volume + master apply uniformly.
    const out = c.createGain();
    out.gain.value = master * volume.value;
    out.connect(c.destination);

    synth(c, out, preset.opts || {});
}

// ── Public API ────────────────────────────────────────────────────
function play(key, master = 1) {
    if (isMuted.value) return;

    // Throttle identical-key spam within 600ms — long tails would overlap chaotically otherwise
    const now = Date.now();
    if (stats.lastKey === key && (now - stats.lastPlayedAt) < 600) return;
    stats.lastKey = key;
    stats.lastPlayedAt = now;

    const pack = activePack.value;

    // 1. Cinematic pack — try real audio file first, then synth fallback.
    if (pack === 'cinematic') {
        const preset = CINEMATIC_PRESETS[key];
        if (!preset) {
            console.warn(`[useSound] unknown cinematic preset: ${key}`);
            return;
        }
        if (preset.file) {
            // Fire-and-forget: if the file is cached, play; if not, kick off a
            // background load and play the synth fallback for THIS request.
            // Next call with the same key will hit the cache and use the file.
            const cached = bufferCache.get(`${pack}/${preset.file}`);
            if (cached) { playBuffer(cached, master); return; }
            tryLoadFile(pack, preset.file);   // warm cache for next time
        }
        playCinematic(preset, master);
        return;
    }

    // 2. Musical pack — pure synth, no file overrides (the original behaviour).
    const tones = MUSICAL_PRESETS[key];
    if (!tones) {
        console.warn(`[useSound] unknown musical preset: ${key}`);
        return;
    }
    playSequence(tones, master);
}

function mute()   { isMuted.value = true;  localStorage.setItem(LS_MUTE, '1'); }
function unmute() { isMuted.value = false; localStorage.setItem(LS_MUTE, '0'); }
function toggleMute() {
    if (isMuted.value) unmute(); else mute();
    if (!isMuted.value) play('notification');   // audible confirmation
}
function setVolume(v) {
    volume.value = Math.min(1, Math.max(0, v));
    localStorage.setItem(LS_VOLUME, String(volume.value));
}

const VALID_PACKS = ['musical', 'cinematic'];
function setPack(pack) {
    if (! VALID_PACKS.includes(pack)) {
        console.warn(`[useSound] unknown pack: ${pack}`);
        return;
    }
    activePack.value = pack;
    localStorage.setItem(LS_PACK, pack);
    if (! isMuted.value) play('notification');   // audible confirmation in the new pack
}

// Sync mute / volume / pack across tabs
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (e) => {
        if (e.key === LS_MUTE)   isMuted.value    = e.newValue === '1';
        if (e.key === LS_VOLUME) volume.value     = parseFloat(e.newValue ?? '0.45');
        if (e.key === LS_PACK && VALID_PACKS.includes(e.newValue)) {
            activePack.value = e.newValue;
        }
    });
}

// Preset listings for the SoundToggle preview grid — exposes whichever pack
// is currently active so the preview buttons always match what users hear.
// Computed so Vue templates re-render when the pack changes at runtime.
const presetsRef = computed(() =>
    activePack.value === 'cinematic'
        ? Object.keys(CINEMATIC_PRESETS)
        : Object.keys(MUSICAL_PRESETS)
);

export function useSound() {
    return {
        play, mute, unmute, toggleMute, setVolume, setPack,
        isMuted, volume, activePack,
        presets: presetsRef,
        availablePacks: VALID_PACKS,
    };
}

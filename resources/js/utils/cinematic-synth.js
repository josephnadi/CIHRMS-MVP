/**
 * Cinematic sound synthesis — Web Audio approximations of real-world sounds.
 *
 *  - Each generator schedules sound on the supplied AudioContext starting "now".
 *  - The output is routed through `dest` (typically a master GainNode), so
 *    volume is controlled by the caller.
 *  - These are *approximations*. For broadcast-quality fidelity, drop a real
 *    audio file at `public/sounds/cinematic/<key>.mp3` and useSound will
 *    prefer the file over the synth. See docs/sound_pack_sources.md.
 *
 * Physical-modeling notes:
 *  - Bells & doorbells use additive synthesis with inharmonic partials
 *    (real bells have partials at non-integer ratios — that's why a bell
 *    doesn't sound like a flute).
 *  - Train horns layer 2-3 sawtooth oscillators tuned as a chord with
 *    vibrato — diesel locomotive horns are usually a minor third or
 *    perfect fifth dyad (e.g., Nathan AirChime K-3 or K-5).
 *  - Wood/glass use filtered noise bursts with a short percussive envelope.
 *  - Phone ring uses the classic US 440 + 480 Hz dual-tone.
 */

// ── Tunable bell partials (frequency ratios + decay-time multipliers) ──
// Derived from measurements of a real church bell (Hibbert 2008):
//   hum tone (0.5×), prime (1.0×), tierce (1.2×), quint (1.5×),
//   nominal (2.0×), deciem (2.4×), upper octave (3.0×), upper twelfth (4.5×).
const BELL_PARTIALS = [
    { ratio: 0.5,  amp: 0.50, decay: 3.20 },   // hum — long sub-tone
    { ratio: 1.0,  amp: 1.00, decay: 2.40 },   // prime
    { ratio: 1.2,  amp: 0.45, decay: 1.80 },   // tierce — gives bells their minor-third colour
    { ratio: 1.5,  amp: 0.35, decay: 1.40 },   // quint
    { ratio: 2.0,  amp: 0.50, decay: 1.00 },   // nominal (the perceived strike pitch)
    { ratio: 2.4,  amp: 0.18, decay: 0.65 },   // deciem
    { ratio: 3.0,  amp: 0.22, decay: 0.50 },   // upper octave
    { ratio: 4.5,  amp: 0.10, decay: 0.30 },   // upper twelfth
];

/** Cancel and free an oscillator after `t` seconds. */
function stopAt(node, ctx, t) {
    try { node.stop(ctx.currentTime + t); } catch (_) {}
}

// ── Room reverb send (built lazily per AudioContext) ─────────────────
// A short procedurally-generated impulse response gives every synth a
// believable acoustic tail — turns "synth beep" into "object in a room".
const _roomCache = new WeakMap();
function getRoomSend(ctx) {
    if (_roomCache.has(ctx)) return _roomCache.get(ctx);

    // Synthesize a ~1.4s decaying noise IR → mid-sized room character
    const dur     = 1.4;
    const len     = Math.floor(ctx.sampleRate * dur);
    const impulse = ctx.createBuffer(2, len, ctx.sampleRate);
    for (let ch = 0; ch < 2; ch++) {
        const data = impulse.getChannelData(ch);
        for (let i = 0; i < len; i++) {
            // Exponentially-decaying noise; pre-delay first 4ms = silence so
            // the early dry signal stays defined before the wash kicks in.
            const t = i / ctx.sampleRate;
            const pre = t < 0.004 ? 0 : 1;
            data[i] = (Math.random() * 2 - 1) * pre * Math.pow(1 - t / dur, 2.2);
        }
    }

    const convolver = ctx.createConvolver();
    convolver.buffer = impulse;
    const sendGain = ctx.createGain();
    sendGain.gain.value = 0.22;        // 22% wet — present but not soupy
    convolver.connect(sendGain);
    _roomCache.set(ctx, { convolver, sendGain });
    return _roomCache.get(ctx);
}

/**
 * Route a node through the room reverb. Pass the SAME `dest` the dry
 * signal goes to so the wet tail mixes with the original output bus.
 */
function withRoom(ctx, dest) {
    const room = getRoomSend(ctx);
    // Connect the reverb's wet output to the dest (idempotent: convolver
    // already feeds sendGain; we just ensure sendGain → dest is wired once).
    try { room.sendGain.connect(dest); } catch (_) {}
    // Return a "split" node — caller connects their signal here; we
    // both pass-through to dest AND feed the room.
    const split = ctx.createGain();
    split.gain.value = 1.0;
    split.connect(dest);
    split.connect(room.convolver);
    return split;
}

// ── BELL — single strike, additive partials ──────────────────────────
export function makeBell(ctx, dest, opts = {}) {
    const {
        freq = 880,
        startOffset = 0,
        gain = 0.6,
        decayMul = 1.0,
        strike = true,   // include the noise-burst strike transient
        room   = true,   // route through the room-reverb send
    } = opts;

    const t0 = ctx.currentTime + startOffset;
    // Split node lets the bell feed both the dry bus AND the room reverb.
    const out = room ? withRoom(ctx, dest) : dest;

    // Strike transient — short filtered noise burst gives the bell its initial attack
    if (strike) {
        const buf = ctx.createBuffer(1, ctx.sampleRate * 0.04, ctx.sampleRate);
        const ch  = buf.getChannelData(0);
        for (let i = 0; i < ch.length; i++) ch[i] = (Math.random() * 2 - 1) * (1 - i / ch.length);
        const src = ctx.createBufferSource();
        src.buffer = buf;
        const bp = ctx.createBiquadFilter();
        bp.type = 'bandpass';
        bp.frequency.value = freq * 3;
        bp.Q.value = 4;
        const sg = ctx.createGain();
        sg.gain.setValueAtTime(gain * 0.45, t0);
        sg.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.06);
        src.connect(bp).connect(sg).connect(out);
        src.start(t0);
        src.stop(t0 + 0.08);
    }

    // Additive partials
    BELL_PARTIALS.forEach(p => {
        const osc = ctx.createOscillator();
        osc.type = 'sine';
        osc.frequency.value = freq * p.ratio;

        const g = ctx.createGain();
        const peak = gain * p.amp * 0.18;
        g.gain.setValueAtTime(0, t0);
        g.gain.linearRampToValueAtTime(peak, t0 + 0.008);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + p.decay * decayMul);

        osc.connect(g).connect(out);
        osc.start(t0);
        stopAt(osc, ctx, startOffset + p.decay * decayMul + 0.1);
    });
}

// ── DOORBELL — classic two-strike "ding-dong" (Westminster cadence) ──
export function makeDoorbell(ctx, dest, opts = {}) {
    const { gain = 0.55, low = false } = opts;
    // Westminster cadence: descending major third (E5 → C5 typical residential bell)
    // "low" produces a deeper Big-Ben-ish doorbell (G4 → E4)
    const high = low ? 392 : 659;
    const lo   = low ? 330 : 523;

    makeBell(ctx, dest, { freq: high, gain,         decayMul: 1.6, startOffset: 0.00 });
    makeBell(ctx, dest, { freq: lo,   gain: gain*0.95, decayMul: 1.8, startOffset: 0.42 });
}

// ── TRAIN HORN — 3-note diesel locomotive chord with vibrato ─────────
export function makeTrainHorn(ctx, dest, opts = {}) {
    const {
        startOffset = 0,
        duration   = 1.6,
        gain       = 0.55,
        chordHz    = [165, 220, 277],   // E3, A3, C#4 — classic K-3 air horn dyad/triad
        distant    = false,
    } = opts;

    const t0 = ctx.currentTime + startOffset;

    // Sub-master low-pass to add "distant" character if requested
    const sub = ctx.createBiquadFilter();
    sub.type = 'lowpass';
    sub.frequency.value = distant ? 1100 : 4500;
    sub.Q.value = 0.7;
    sub.connect(dest);

    // Slight beat-frequency LFO for that air-horn "growl"
    const lfo = ctx.createOscillator();
    const lfoGain = ctx.createGain();
    lfo.frequency.value = 6.8;            // ~7 Hz vibrato
    lfoGain.gain.value = 4.0;             // ±4 Hz frequency wobble
    lfo.connect(lfoGain);
    lfo.start(t0);
    stopAt(lfo, ctx, startOffset + duration + 0.2);

    chordHz.forEach((f, i) => {
        const osc = ctx.createOscillator();
        osc.type = 'sawtooth';
        osc.frequency.value = f;
        lfoGain.connect(osc.frequency);

        // Mild low-pass per voice to tame the sawtooth edge
        const tone = ctx.createBiquadFilter();
        tone.type = 'lowpass';
        tone.frequency.value = 1800 + (i * 200);
        tone.Q.value = 0.9;

        const g = ctx.createGain();
        const peak = gain * (distant ? 0.22 : 0.35) * (i === 0 ? 1.0 : 0.75);
        // Attack swell — air horns aren't instant
        g.gain.setValueAtTime(0, t0);
        g.gain.linearRampToValueAtTime(peak, t0 + 0.12);
        g.gain.setValueAtTime(peak, t0 + duration * 0.7);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + duration);

        osc.connect(tone).connect(g).connect(sub);
        osc.start(t0);
        stopAt(osc, ctx, startOffset + duration + 0.1);
    });
}

// ── GLASS CLINK — short, bright, high-pitched chime ──────────────────
export function makeGlassClink(ctx, dest, opts = {}) {
    const { startOffset = 0, gain = 0.45, freq = 3200 } = opts;
    const t0 = ctx.currentTime + startOffset;

    // Crystal partials — high and slightly inharmonic
    [1.0, 2.07, 3.41, 4.83].forEach((ratio, i) => {
        const osc = ctx.createOscillator();
        osc.type = 'sine';
        osc.frequency.value = freq * ratio;
        const g = ctx.createGain();
        const peak = gain * (0.45 / (i + 1));
        g.gain.setValueAtTime(0, t0);
        g.gain.linearRampToValueAtTime(peak, t0 + 0.004);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.25 - i * 0.04);
        osc.connect(g).connect(dest);
        osc.start(t0);
        stopAt(osc, ctx, startOffset + 0.35);
    });
}

// ── WOOD KNOCK — band-passed noise burst at a single resonance ───────
export function makeWoodKnock(ctx, dest, opts = {}) {
    const { startOffset = 0, gain = 0.6, pitch = 'mid', room = true } = opts;
    const t0 = ctx.currentTime + startOffset;
    const out = room ? withRoom(ctx, dest) : dest;

    const buf = ctx.createBuffer(1, ctx.sampleRate * 0.08, ctx.sampleRate);
    const ch  = buf.getChannelData(0);
    for (let i = 0; i < ch.length; i++) ch[i] = (Math.random() * 2 - 1);

    const src = ctx.createBufferSource();
    src.buffer = buf;

    const bp = ctx.createBiquadFilter();
    bp.type = 'bandpass';
    bp.frequency.value = pitch === 'high' ? 1400 : pitch === 'low' ? 480 : 850;
    bp.Q.value = 8;

    const g = ctx.createGain();
    g.gain.setValueAtTime(gain * 0.95, t0);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.08);

    src.connect(bp).connect(g).connect(out);
    src.start(t0);
    src.stop(t0 + 0.10);
}

// ── CASH REGISTER — metallic click + bright ka-ching chime ───────────
export function makeCashRegister(ctx, dest, opts = {}) {
    const { gain = 0.55 } = opts;
    // Initial mechanical click
    makeWoodKnock(ctx, dest, { startOffset: 0,   gain: gain * 0.7, pitch: 'high' });
    // Bell ring — bright, slightly inharmonic
    makeBell(ctx, dest, { freq: 1568,            gain: gain * 0.85, startOffset: 0.04, decayMul: 0.7, strike: false });
    makeBell(ctx, dest, { freq: 2349,            gain: gain * 0.55, startOffset: 0.05, decayMul: 0.55, strike: false });
    // Cash drawer slide — short pink noise sweep
    const t0 = ctx.currentTime + 0.22;
    const buf = ctx.createBuffer(1, ctx.sampleRate * 0.18, ctx.sampleRate);
    const ch  = buf.getChannelData(0);
    for (let i = 0; i < ch.length; i++) ch[i] = (Math.random() * 2 - 1) * 0.6;
    const src = ctx.createBufferSource();
    src.buffer = buf;
    const lp = ctx.createBiquadFilter();
    lp.type = 'lowpass';
    lp.frequency.setValueAtTime(2400, t0);
    lp.frequency.exponentialRampToValueAtTime(600, t0 + 0.18);
    const g = ctx.createGain();
    g.gain.setValueAtTime(gain * 0.20, t0);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.18);
    src.connect(lp).connect(g).connect(dest);
    src.start(t0);
    src.stop(t0 + 0.20);
}

// ── ALARM — two-tone alternating siren ───────────────────────────────
export function makeAlarm(ctx, dest, opts = {}) {
    const { cycles = 2, hi = 880, lo = 660, period = 0.36, gain = 0.5 } = opts;
    let t = 0;
    for (let i = 0; i < cycles; i++) {
        [hi, lo].forEach(f => {
            const osc = ctx.createOscillator();
            osc.type = 'square';
            osc.frequency.value = f;
            const filt = ctx.createBiquadFilter();
            filt.type = 'lowpass';
            filt.frequency.value = 2200;
            const g = ctx.createGain();
            const t0 = ctx.currentTime + t;
            g.gain.setValueAtTime(0, t0);
            g.gain.linearRampToValueAtTime(gain * 0.45, t0 + 0.02);
            g.gain.setValueAtTime(gain * 0.45, t0 + period * 0.4);
            g.gain.exponentialRampToValueAtTime(0.0001, t0 + period * 0.5);
            osc.connect(filt).connect(g).connect(dest);
            osc.start(t0);
            stopAt(osc, ctx, t + period * 0.55);
            t += period * 0.5;
        });
    }
}

// ── PHONE RING — US 440+480 Hz dual-tone, 2 short bursts ─────────────
export function makePhoneRing(ctx, dest, opts = {}) {
    const { gain = 0.45, bursts = 2 } = opts;
    for (let b = 0; b < bursts; b++) {
        const t0 = ctx.currentTime + b * 0.35;
        [440, 480].forEach(f => {
            const osc = ctx.createOscillator();
            osc.type = 'sine';
            osc.frequency.value = f;
            const g = ctx.createGain();
            g.gain.setValueAtTime(0, t0);
            g.gain.linearRampToValueAtTime(gain * 0.4, t0 + 0.02);
            g.gain.setValueAtTime(gain * 0.4, t0 + 0.22);
            g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.26);
            osc.connect(g).connect(dest);
            osc.start(t0);
            stopAt(osc, ctx, b * 0.35 + 0.30);
        });
    }
}

// ── EMAIL POP — short plucked ascending major third ──────────────────
export function makeEmailPop(ctx, dest, opts = {}) {
    const { gain = 0.5, freq = 1175 } = opts;       // D6 typical
    [freq, freq * 1.26].forEach((f, i) => {
        const t0 = ctx.currentTime + i * 0.08;
        const osc = ctx.createOscillator();
        osc.type = 'triangle';
        osc.frequency.value = f;
        const g = ctx.createGain();
        g.gain.setValueAtTime(0, t0);
        g.gain.linearRampToValueAtTime(gain * 0.5, t0 + 0.005);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.22);
        osc.connect(g).connect(dest);
        osc.start(t0);
        stopAt(osc, ctx, i * 0.08 + 0.30);
    });
}

// ── BUZZER — low rejection buzz with slight detune ───────────────────
export function makeBuzzer(ctx, dest, opts = {}) {
    const { gain = 0.45, low = false } = opts;
    const f1 = low ? 110 : 196;
    const f2 = low ? 104 : 185;                     // detuned slightly for "buzz" beating
    [f1, f2].forEach(f => {
        const osc = ctx.createOscillator();
        osc.type = 'sawtooth';
        osc.frequency.value = f;
        const lp = ctx.createBiquadFilter();
        lp.type = 'lowpass';
        lp.frequency.value = 800;
        const g = ctx.createGain();
        const t0 = ctx.currentTime;
        g.gain.setValueAtTime(gain * 0.32, t0);
        g.gain.setValueAtTime(gain * 0.32, t0 + 0.45);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.55);
        osc.connect(lp).connect(g).connect(dest);
        osc.start(t0);
        stopAt(osc, ctx, 0.6);
    });
}

// ── STATION BELL — slow rising 3-bell chord, train-station / airport feel ─
export function makeStationBell(ctx, dest, opts = {}) {
    const { gain = 0.55 } = opts;
    // Classic SNCF jingle: three ascending bell strikes a major third apart
    makeBell(ctx, dest, { freq: 660, gain,         decayMul: 1.4, startOffset: 0.00 });
    makeBell(ctx, dest, { freq: 880, gain: gain*0.95, decayMul: 1.4, startOffset: 0.32 });
    makeBell(ctx, dest, { freq: 1175, gain,        decayMul: 1.6, startOffset: 0.64 });
}

// ── DISPATCH TABLE ───────────────────────────────────────────────────
// Maps a string `kind` from the preset map to its synth function.
export const CINEMATIC_SYNTHS = {
    bell:          makeBell,
    doorbell:      makeDoorbell,
    trainHorn:     makeTrainHorn,
    glassClink:    makeGlassClink,
    woodKnock:     makeWoodKnock,
    cashRegister:  makeCashRegister,
    alarm:         makeAlarm,
    phoneRing:     makePhoneRing,
    emailPop:      makeEmailPop,
    buzzer:        makeBuzzer,
    stationBell:   makeStationBell,
};

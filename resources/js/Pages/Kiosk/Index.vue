<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import RecentPunches from '@/Components/Kiosk/RecentPunches.vue';

const props = defineProps({
    serverTime: String,
});

// ── Live clock ───────────────────────────────────────────────────────────────
const now = ref(new Date(props.serverTime ?? Date.now()));
let clockInterval = null;
onMounted(() => { clockInterval = setInterval(() => { now.value = new Date(); }, 1000); });
onUnmounted(() => { clearInterval(clockInterval); });

// ── Today's wall — last 8 kiosk punches today, polled every 15s ─────────────
const recent = ref([]);
let recentInterval = null;
const fetchRecent = async () => {
    try {
        const { data } = await axios.get(route('kiosk.recent'));
        recent.value = data?.recent ?? [];
    } catch (_) { /* network blip — keep previous list */ }
};
onMounted(() => {
    fetchRecent();
    recentInterval = setInterval(fetchRecent, 15000);
});
onUnmounted(() => { if (recentInterval) clearInterval(recentInterval); });

const liveTime = computed(() =>
    now.value.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
);
const liveDate = computed(() =>
    now.value.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
);

// ── Flow state ───────────────────────────────────────────────────────────────
// stages: 'identify' → 'confirm' → 'success'
const stage = ref('identify');
const employeeNo = ref('');
const fullName = ref('');
const errorMessage = ref('');
const isSubmitting = ref(false);

const matched = ref(null);   // { employee_no, name, position, avatar_url, last_event, suggested_direction }
const lastRecord = ref(null); // { direction, event_at } on success

// Auto-reset to identify after success
let resetTimer = null;
const scheduleReset = (ms = 6000) => {
    clearTimeout(resetTimer);
    resetTimer = setTimeout(() => resetFlow(), ms);
};

const resetFlow = () => {
    stage.value = 'identify';
    employeeNo.value = '';
    fullName.value = '';
    errorMessage.value = '';
    matched.value = null;
    lastRecord.value = null;
    activeField.value = 'employee_no';
    shiftOn.value = true;
};

// ── On-screen keyboard ───────────────────────────────────────────────────────
// activeField tracks which input the kiosk keys type into. Mousedown.prevent
// on every key keeps the input focused so the caret never moves away.
const activeField = ref('employee_no'); // 'employee_no' | 'name'
const shiftOn = ref(true); // start capitalised — most names begin uppercase
const employeeNoInput = ref(null);
const nameInput = ref(null);

const numberRow = ['1','2','3','4','5','6','7','8','9','0','-'];
const rowQ = ['q','w','e','r','t','y','u','i','o','p'];
const rowA = ['a','s','d','f','g','h','j','k','l'];
const rowZ = ['z','x','c','v','b','n','m'];

const focusActive = () => {
    const el = activeField.value === 'employee_no' ? employeeNoInput.value : nameInput.value;
    el?.focus();
};

const setActive = (field) => {
    activeField.value = field;
};

const tapKey = (rawChar) => {
    errorMessage.value = '';
    const char = shiftOn.value ? rawChar.toUpperCase() : rawChar;

    if (activeField.value === 'employee_no') {
        if (employeeNo.value.length >= 20) return;
        employeeNo.value += char;
    } else {
        if (fullName.value.length >= 60) return;
        fullName.value += char;
        // After the first letter of a word, drop shift (caps-once behaviour)
        if (shiftOn.value && /[a-z]/i.test(rawChar)) shiftOn.value = false;
    }
    focusActive();
};

const tapSpace = () => {
    if (activeField.value !== 'name') return;
    if (fullName.value.length >= 60) return;
    fullName.value += ' ';
    shiftOn.value = true; // capitalise next word
    focusActive();
};

const backspace = () => {
    if (activeField.value === 'employee_no') {
        employeeNo.value = employeeNo.value.slice(0, -1);
    } else {
        fullName.value = fullName.value.slice(0, -1);
    }
    focusActive();
};

const clearActive = () => {
    if (activeField.value === 'employee_no') employeeNo.value = '';
    else fullName.value = '';
    focusActive();
};

const toggleShift = () => {
    shiftOn.value = !shiftOn.value;
    focusActive();
};

const nextField = () => {
    activeField.value = activeField.value === 'employee_no' ? 'name' : 'employee_no';
    focusActive();
};

// ── Verify ───────────────────────────────────────────────────────────────────
const verify = async () => {
    if (!employeeNo.value.trim() || !fullName.value.trim()) {
        errorMessage.value = 'Please enter both Employee ID and your name.';
        return;
    }
    errorMessage.value = '';
    isSubmitting.value = true;
    try {
        const { data } = await axios.post(route('kiosk.verify'), {
            employee_no: employeeNo.value.trim(),
            name: fullName.value.trim(),
        });
        if (data.ok) {
            matched.value = data.employee;
            stage.value = 'confirm';
        } else {
            errorMessage.value = data.message ?? 'Could not verify identity.';
        }
    } catch (e) {
        errorMessage.value = e?.response?.data?.message ?? 'Could not verify identity. Please try again.';
    } finally {
        isSubmitting.value = false;
    }
};

// ── Clock ────────────────────────────────────────────────────────────────────
const clock = async (direction) => {
    isSubmitting.value = true;
    errorMessage.value = '';
    try {
        const { data } = await axios.post(route('kiosk.clock'), {
            employee_no: employeeNo.value.trim(),
            name: fullName.value.trim(),
            direction,
        });
        if (data.ok) {
            matched.value = data.employee;
            lastRecord.value = data.record;
            stage.value = 'success';
            fetchRecent();
            scheduleReset(6000);
        } else {
            errorMessage.value = data.message ?? 'Could not record clock event.';
        }
    } catch (e) {
        errorMessage.value = e?.response?.data?.message ?? 'Could not record clock event. Please try again.';
    } finally {
        isSubmitting.value = false;
    }
};

// Face-scan path intentionally not shipped in v1 — see C4 in
// docs/MARKET_READY_PUNCHLIST.md + §9 of docs/deployment_production.md.
// High-trust contexts use hardware biometric devices via BiometricWebhookController.

const formattedEventTime = computed(() => {
    if (!lastRecord.value?.event_at) return '';
    return new Date(lastRecord.value.event_at).toLocaleTimeString('en-GB', {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
});
</script>

<template>
    <Head title="Attendance Kiosk" />

    <main class="kiosk-shell">
        <!-- Atmospheric backdrop -->
        <div class="kiosk-mesh" aria-hidden="true"></div>
        <div class="kiosk-grain" aria-hidden="true"></div>
        <span class="kiosk-gold-rail" aria-hidden="true"></span>

        <!-- Header: brand + live clock -->
        <header class="kiosk-header">
            <div class="kiosk-brand">
                <span class="kiosk-brand-mark">CI</span>
                <div class="leading-tight">
                    <div class="text-[10px] font-black uppercase tracking-[0.22em] text-white/55">Attendance</div>
                    <div class="text-sm font-bold tracking-tight text-white">CIHRMS Kiosk</div>
                </div>
            </div>
            <div class="kiosk-clock">
                <div class="font-mono text-5xl font-black tabular-nums tracking-tight text-white">{{ liveTime }}</div>
                <div class="mt-1 text-[11px] font-bold uppercase tracking-[0.22em] text-white/55">{{ liveDate }}</div>
            </div>
        </header>

        <!-- Stage: identify -->
        <section v-if="stage === 'identify'" class="kiosk-stage" key="identify">
            <div class="kiosk-card">
                <div class="kiosk-eyebrow">Step 1 of 2 · Identify</div>
                <h1 class="kiosk-title">Welcome.<br /><em class="text-[#2c74b3] not-italic font-black">Tap in to begin.</em></h1>
                <p class="kiosk-subtitle">Enter your Employee ID and full name. We'll match and confirm before recording.</p>

                <div class="mt-3 grid gap-2.5">
                    <label class="kiosk-field" :class="{ 'kiosk-field-active': activeField === 'employee_no' }">
                        <span>Employee ID</span>
                        <input aria-label="EmployeeNo"
                            ref="employeeNoInput"
                            v-model="employeeNo"
                            type="text"
                            autocomplete="off"
                            placeholder="e.g. GH-HR-821"
                            class="kiosk-input font-mono tracking-[0.18em]"
                            @focus="setActive('employee_no')"
                            @keydown.enter="verify"
                        />
                    </label>
                    <label class="kiosk-field" :class="{ 'kiosk-field-active': activeField === 'name' }">
                        <span>Your Name</span>
                        <input aria-label="FullName"
                            ref="nameInput"
                            v-model="fullName"
                            type="text"
                            autocomplete="off"
                            placeholder="First or full name"
                            class="kiosk-input"
                            @focus="setActive('name')"
                            @keydown.enter="verify"
                        />
                    </label>
                </div>

                <!-- Full on-screen keyboard -->
                <div class="kiosk-kbd" role="group" aria-label="On-screen keyboard">
                    <div class="kiosk-kbd-row">
                        <button
                            v-for="k in numberRow" :key="'n-'+k"
                            type="button" class="kiosk-key"
                            @mousedown.prevent @click="tapKey(k)"
                        >{{ k }}</button>
                        <button type="button" class="kiosk-key kiosk-key-wide kiosk-key-muted"
                                @mousedown.prevent @click="backspace" aria-label="Backspace">⌫</button>
                    </div>
                    <div class="kiosk-kbd-row">
                        <button
                            v-for="k in rowQ" :key="'q-'+k"
                            type="button" class="kiosk-key"
                            @mousedown.prevent @click="tapKey(k)"
                        >{{ shiftOn ? k.toUpperCase() : k }}</button>
                    </div>
                    <div class="kiosk-kbd-row kiosk-kbd-row-indent">
                        <button
                            v-for="k in rowA" :key="'a-'+k"
                            type="button" class="kiosk-key"
                            @mousedown.prevent @click="tapKey(k)"
                        >{{ shiftOn ? k.toUpperCase() : k }}</button>
                    </div>
                    <div class="kiosk-kbd-row">
                        <button
                            type="button"
                            class="kiosk-key kiosk-key-wide kiosk-key-muted"
                            :class="{ 'kiosk-key-shift-on': shiftOn }"
                            @mousedown.prevent @click="toggleShift"
                            aria-pressed="shiftOn ? 'true' : 'false'"
                        >⇧</button>
                        <button
                            v-for="k in rowZ" :key="'z-'+k"
                            type="button" class="kiosk-key"
                            @mousedown.prevent @click="tapKey(k)"
                        >{{ shiftOn ? k.toUpperCase() : k }}</button>
                        <button type="button" class="kiosk-key kiosk-key-wide kiosk-key-muted"
                                @mousedown.prevent @click="clearActive">Clear</button>
                    </div>
                    <div class="kiosk-kbd-row">
                        <button type="button" class="kiosk-key kiosk-key-muted kiosk-key-wide"
                                @mousedown.prevent @click="nextField">Tab ⇄</button>
                        <button type="button" class="kiosk-key kiosk-key-space"
                                @mousedown.prevent @click="tapSpace">space</button>
                        <button type="button" class="kiosk-key kiosk-key-enter"
                                @mousedown.prevent @click="verify">Enter ↵</button>
                    </div>
                </div>

                <p v-if="errorMessage" class="kiosk-error" role="alert">{{ errorMessage }}</p>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <button type="button" class="kiosk-cta" :disabled="isSubmitting" @click="verify">
                        <span v-if="!isSubmitting">Continue →</span>
                        <span v-else>Verifying…</span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Stage: confirm -->
        <section v-else-if="stage === 'confirm'" class="kiosk-stage" key="confirm">
            <div class="kiosk-card kiosk-card-confirm">
                <div class="kiosk-eyebrow">Step 2 of 2 · Confirm</div>
                <div class="mt-2 flex items-center gap-5">
                    <div class="kiosk-avatar">
                        <img v-if="matched?.avatar_url" :src="matched.avatar_url" :alt="matched.name" />
                        <span v-else>{{ (matched?.name || '?').slice(0, 1).toUpperCase() }}</span>
                    </div>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.22em] text-white/55">Is this you?</div>
                        <div class="mt-1 text-3xl font-black tracking-tight text-white">{{ matched?.name }}</div>
                        <div class="text-sm font-semibold text-white/65">
                            {{ matched?.position || '—' }} · <span class="font-mono">{{ matched?.employee_no }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="matched?.last_event" class="kiosk-last">
                    Last today: <strong class="uppercase">{{ matched.last_event.direction }}</strong>
                    at {{ new Date(matched.last_event.event_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) }}
                </div>
                <div v-else class="kiosk-last">No events recorded today yet.</div>

                <p v-if="errorMessage" class="kiosk-error" role="alert">{{ errorMessage }}</p>

                <div class="mt-7 grid gap-3 sm:grid-cols-2">
                    <button
                        type="button"
                        class="kiosk-action kiosk-action-in"
                        :disabled="isSubmitting"
                        :data-recommended="matched?.suggested_direction === 'in' ? 'true' : 'false'"
                        @click="clock('in')"
                    >
                        <span class="text-[10px] font-black uppercase tracking-[0.2em] opacity-70">Clock</span>
                        <span class="text-2xl font-black">In</span>
                    </button>
                    <button
                        type="button"
                        class="kiosk-action kiosk-action-out"
                        :disabled="isSubmitting"
                        :data-recommended="matched?.suggested_direction === 'out' ? 'true' : 'false'"
                        @click="clock('out')"
                    >
                        <span class="text-[10px] font-black uppercase tracking-[0.2em] opacity-70">Clock</span>
                        <span class="text-2xl font-black">Out</span>
                    </button>
                </div>

                <button type="button" class="kiosk-link mt-5" @click="resetFlow">← Not me, start over</button>
            </div>
        </section>

        <!-- Stage: success -->
        <section v-else-if="stage === 'success'" class="kiosk-stage" key="success">
            <div class="kiosk-card kiosk-card-success">
                <div class="kiosk-check" aria-hidden="true">✓</div>
                <div class="text-[10px] font-black uppercase tracking-[0.22em] text-white/55">
                    Clocked {{ lastRecord?.direction }}
                </div>
                <h2 class="mt-2 text-4xl font-black tracking-tight text-white">{{ matched?.name }}</h2>
                <div class="mt-1 text-sm font-semibold text-white/65 font-mono">{{ matched?.employee_no }}</div>
                <div class="kiosk-success-time">{{ formattedEventTime }}</div>
                <p class="mt-2 text-xs font-semibold uppercase tracking-[0.18em] text-white/45">
                    Have a productive day.
                </p>
                <button type="button" class="kiosk-link mt-6" @click="resetFlow">Done — next person</button>
            </div>
        </section>

        <!-- Today's wall — last 8 kiosk punches; doubles as a device-liveness indicator -->
        <div class="kiosk-wall">
            <RecentPunches :items="recent" />
        </div>

        <footer class="kiosk-footer">
            <span>CIHRMS · Attendance terminal</span>
            <span class="hidden sm:inline">Press <kbd>Enter</kbd> to submit</span>
        </footer>
    </main>
</template>

<style scoped>
.kiosk-shell {
    position: relative;
    height: 100vh;
    height: 100dvh;
    display: flex;
    flex-direction: column;
    background: linear-gradient(135deg, #06192f 0%, #0a2647 55%, #102d50 100%);
    color: #fff;
    overflow: hidden;
    isolation: isolate;
}
.kiosk-wall {
    position: relative;
    z-index: 1;
    padding: 12px 28px 4px;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
}
.kiosk-mesh {
    position: absolute; inset: 0; pointer-events: none; z-index: 0;
    background:
        radial-gradient(700px 500px at 12% 18%, rgba(18,217,227,0.10), transparent 60%),
        radial-gradient(900px 600px at 88% 82%, rgba(217,18,227,0.07), transparent 60%),
        radial-gradient(600px 500px at 50% 50%, rgba(32,82,149,0.22), transparent 70%);
}
.kiosk-grain {
    position: absolute; inset: 0; pointer-events: none; z-index: 0; opacity: 0.04;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>");
}
.kiosk-gold-rail {
    position: absolute; top: 10%; bottom: 10%; right: 2.5%; width: 1px; z-index: 1;
    background: linear-gradient(180deg, transparent, #ffd700 30%, #b88a08 70%, transparent);
    opacity: 0.55;
}
.kiosk-header {
    position: relative; z-index: 2;
    display: flex; align-items: flex-end; justify-content: space-between;
    padding: 0.85rem 1.5rem 0;
    flex-shrink: 0;
}
.kiosk-brand { display: flex; align-items: center; gap: 0.6rem; }
.kiosk-brand-mark {
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.55rem;
    background: linear-gradient(135deg, #205295, #2c74b3);
    font-weight: 900; font-size: 0.8rem; letter-spacing: 0.04em;
    box-shadow: 0 8px 24px rgba(32,82,149,0.45);
}
.kiosk-clock { text-align: right; }
.kiosk-clock .font-mono { font-size: 1.85rem !important; }
.kiosk-clock .font-mono + div { font-size: 9px !important; margin-top: 0.15rem !important; }

.kiosk-stage {
    position: relative; z-index: 2;
    flex: 1 1 auto; min-height: 0;
    display: flex; align-items: center; justify-content: center;
    padding: 0.75rem 1.5rem;
    animation: kiosk-fade-up 0.45s cubic-bezier(.16,1,.3,1) both;
    overflow: hidden;
}
@keyframes kiosk-fade-up {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

.kiosk-card {
    width: 100%; max-width: 540px;
    max-height: 100%;
    overflow: hidden;
    background: rgba(8, 23, 45, 0.72);
    border: 1px solid rgba(255,255,255,0.08);
    backdrop-filter: blur(14px);
    border-radius: 0.95rem;
    padding: 1.1rem 1.35rem 1.2rem;
    box-shadow:
        0 24px 60px rgba(0,0,0,0.45),
        inset 0 1px 0 rgba(255,255,255,0.06);
    display: flex; flex-direction: column;
}
.kiosk-card-confirm  { max-width: 560px; padding: 1.5rem 1.5rem 1.4rem; }
.kiosk-card-success  { max-width: 480px; text-align: center; padding: 1.75rem 1.5rem; }

.kiosk-eyebrow {
    font-size: 10px; font-weight: 900; letter-spacing: 0.22em;
    text-transform: uppercase; color: rgba(255,255,255,0.55);
}
.kiosk-title {
    margin-top: 0.35rem;
    font-size: clamp(1.35rem, 2.4vw, 1.7rem); line-height: 1.05;
    font-weight: 900; letter-spacing: -0.02em; color: #fff;
}
.kiosk-subtitle {
    margin-top: 0.35rem;
    font-size: 0.78rem;
    color: rgba(255,255,255,0.62); max-width: 42ch;
}

.kiosk-field { display: grid; gap: 0.3rem; }
.kiosk-field > span {
    font-size: 9px; font-weight: 900; letter-spacing: 0.18em;
    text-transform: uppercase; color: rgba(255,255,255,0.55);
}
.kiosk-input {
    width: 100%;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 0.55rem;
    padding: 0.55rem 0.8rem;
    font-size: 0.95rem; font-weight: 600;
    color: #fff;
    caret-color: #205295;
    transition: border-color 0.18s, background 0.18s, box-shadow 0.18s;
}
.kiosk-input::placeholder { color: rgba(255,255,255,0.30); }
.kiosk-input:focus {
    outline: none;
    border-color: #2c74b3;
    background: rgba(255,255,255,0.07);
    box-shadow: 0 0 0 4px rgba(44,116,179,0.18);
}

/* On-screen QWERTY keyboard */
.kiosk-kbd {
    margin-top: 0.7rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.4rem;
    background: rgba(0,0,0,0.18);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 0.65rem;
}
.kiosk-kbd-row {
    display: flex;
    gap: 0.22rem;
    justify-content: center;
}
.kiosk-kbd-row-indent { padding: 0 0.7rem; }

.kiosk-key {
    flex: 1 1 0;
    min-width: 0;
    padding: 0.5rem 0.2rem;
    font-size: 0.85rem; font-weight: 800;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.10);
    color: #fff;
    border-radius: 0.4rem;
    transition: transform 0.08s ease, background 0.18s, border-color 0.18s;
    user-select: none;
    line-height: 1;
}
.kiosk-key:hover { background: rgba(44,116,179,0.22); border-color: rgba(44,116,179,0.55); }
.kiosk-key:active { transform: scale(0.96); background: rgba(32,82,149,0.55); }
.kiosk-key-muted { font-size: 0.7rem; font-weight: 700; color: rgba(255,255,255,0.78); }
.kiosk-key-wide { flex: 1.6 1 0; }
.kiosk-key-space { flex: 5 1 0; font-size: 0.7rem; letter-spacing: 0.18em; text-transform: uppercase; color: rgba(255,255,255,0.7); }
.kiosk-key-enter {
    flex: 1.8 1 0;
    background: linear-gradient(135deg, #205295, #2c74b3);
    border-color: rgba(44,116,179,0.7);
    color: #fff;
    font-size: 0.75rem; letter-spacing: 0.06em;
}
.kiosk-key-enter:hover { filter: brightness(1.08); }
.kiosk-key-shift-on {
    background: rgba(255,215,0,0.18);
    border-color: rgba(255,215,0,0.55);
    color: #ffd700;
}

.kiosk-field-active .kiosk-input {
    border-color: #2c74b3;
    background: rgba(255,255,255,0.07);
    box-shadow: 0 0 0 4px rgba(44,116,179,0.18);
}
.kiosk-field-active > span { color: #2c74b3; }

.kiosk-error {
    margin-top: 0.55rem;
    color: #d912e3;
    background: rgba(217,18,227,0.08);
    border: 1px solid rgba(217,18,227,0.30);
    border-radius: 0.5rem;
    padding: 0.45rem 0.7rem;
    font-size: 0.78rem; font-weight: 600;
}

.kiosk-cta {
    flex: 1; padding: 0.7rem 1.2rem;
    background: linear-gradient(135deg, #205295, #2c74b3);
    color: #fff; border-radius: 0.6rem;
    font-weight: 800; letter-spacing: 0.02em;
    font-size: 0.9rem;
    box-shadow: 0 12px 30px rgba(32,82,149,0.40);
    transition: transform 0.1s ease, box-shadow 0.2s ease, filter 0.2s ease;
    position: relative; overflow: hidden;
}
.kiosk-cta::before {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(90deg, transparent, rgba(255,215,0,0.18), transparent);
    transform: translateX(-100%); transition: transform 0.6s ease;
}
.kiosk-cta:hover:not(:disabled) { filter: brightness(1.05); box-shadow: 0 16px 36px rgba(32,82,149,0.55); }
.kiosk-cta:hover:not(:disabled)::before { transform: translateX(100%); }
.kiosk-cta:disabled { opacity: 0.6; cursor: progress; }

.kiosk-ghost {
    flex: 1; padding: 0.7rem 1.2rem;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.12);
    color: rgba(255,255,255,0.85);
    border-radius: 0.6rem;
    font-weight: 700; letter-spacing: 0.02em;
    font-size: 0.85rem;
    transition: background 0.18s, border-color 0.18s;
}
.kiosk-ghost:hover:not(:disabled) {
    background: rgba(44,116,179,0.12);
    border-color: rgba(44,116,179,0.45);
}

.kiosk-avatar {
    width: 5.5rem; height: 5.5rem; border-radius: 1rem; overflow: hidden;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #205295, #2c74b3);
    font-size: 2rem; font-weight: 900; color: #fff;
    box-shadow: 0 12px 30px rgba(0,0,0,0.35);
    border: 1px solid rgba(255,255,255,0.10);
}
.kiosk-avatar img { width: 100%; height: 100%; object-fit: cover; }

.kiosk-last {
    margin-top: 1.4rem;
    padding: 0.75rem 1rem;
    background: rgba(255,255,255,0.04);
    border: 1px dashed rgba(255,255,255,0.12);
    border-radius: 0.65rem;
    font-size: 0.82rem; font-weight: 600;
    color: rgba(255,255,255,0.72);
    letter-spacing: 0.02em;
}

.kiosk-action {
    position: relative;
    padding: 1.5rem 1.25rem;
    border-radius: 1rem;
    display: flex; flex-direction: column; gap: 0.25rem;
    align-items: center;
    transition: transform 0.1s, filter 0.2s, box-shadow 0.2s;
    overflow: hidden;
}
.kiosk-action::after {
    content: ''; position: absolute; inset: -1px;
    border-radius: inherit; pointer-events: none;
    border: 1px solid transparent;
    transition: border-color 0.2s;
}
.kiosk-action[data-recommended="true"]::after {
    border-color: rgba(255,215,0,0.6);
    box-shadow: 0 0 0 3px rgba(255,215,0,0.10);
}
.kiosk-action-in  { background: linear-gradient(135deg, #205295, #2c74b3); color: #fff; }
.kiosk-action-out { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.14); color: #fff; }
.kiosk-action:hover:not(:disabled)  { filter: brightness(1.06); }
.kiosk-action:active:not(:disabled) { transform: scale(0.98); }
.kiosk-action:disabled { opacity: 0.55; cursor: progress; }

.kiosk-link {
    background: none; border: none;
    color: rgba(255,255,255,0.55);
    font-size: 0.82rem; font-weight: 700; letter-spacing: 0.04em;
    text-transform: uppercase;
}
.kiosk-link:hover { color: #2c74b3; }

.kiosk-check {
    width: 4.5rem; height: 4.5rem; margin: 0 auto 1rem;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
    background: linear-gradient(135deg, #205295, #2c74b3);
    color: #fff; font-size: 2rem; font-weight: 900;
    box-shadow: 0 12px 36px rgba(32,82,149,0.55);
    animation: kiosk-pop 0.45s cubic-bezier(.16,1.4,.3,1) both;
}
@keyframes kiosk-pop {
    from { transform: scale(0.3); opacity: 0; }
    to   { transform: scale(1);   opacity: 1; }
}
.kiosk-success-time {
    margin-top: 1.1rem;
    font-family: ui-monospace, "SF Mono", monospace;
    font-size: 2.5rem; font-weight: 900; letter-spacing: 0.04em;
    color: #ffd700;
    font-variant-numeric: tabular-nums;
}

.kiosk-footer {
    position: relative; z-index: 2;
    flex-shrink: 0;
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.55rem 1.5rem 0.7rem;
    font-size: 0.65rem; font-weight: 700; letter-spacing: 0.16em;
    text-transform: uppercase; color: rgba(255,255,255,0.35);
}
.kiosk-footer kbd {
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.14);
    padding: 0.1rem 0.45rem; border-radius: 0.3rem;
    font-family: ui-monospace, monospace;
}
</style>

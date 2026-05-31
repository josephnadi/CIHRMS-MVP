<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    start: {
        type: String,
        default: '',
    },
    end: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    min: {
        type: String,
        default: '',
    },
    max: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:start', 'update:end']);

const rangeWarning = ref('');

// Minimum date for the "end" input is always the selected start
const endMin = computed(() => props.start || props.min || '');

function onStartChange(value) {
    rangeWarning.value = '';
    emit('update:start', value);

    // If start is after end, clear end
    if (value && props.end && value > props.end) {
        emit('update:end', '');
        rangeWarning.value = '"To" date was cleared because it was before the new start date.';
        setTimeout(() => { rangeWarning.value = ''; }, 3500);
    }
}

function onEndChange(value) {
    rangeWarning.value = '';
    // Guard: end must be >= start
    if (value && props.start && value < props.start) {
        rangeWarning.value = '"To" date cannot be before "From" date.';
        emit('update:end', '');
        return;
    }
    emit('update:end', value);
}

function clearBoth() {
    rangeWarning.value = '';
    emit('update:start', '');
    emit('update:end', '');
}

// ── Presets ──────────────────────────────────────────────────────────────────
function toISODate(d) {
    return d.toISOString().split('T')[0];
}

function applyPreset(presetKey) {
    rangeWarning.value = '';
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let s, e;
    switch (presetKey) {
        case 'today': {
            s = e = toISODate(today);
            break;
        }
        case 'this-week': {
            const day = today.getDay(); // 0=Sun
            const mon = new Date(today);
            mon.setDate(today.getDate() - (day === 0 ? 6 : day - 1));
            const sun = new Date(mon);
            sun.setDate(mon.getDate() + 6);
            s = toISODate(mon);
            e = toISODate(sun);
            break;
        }
        case 'this-month': {
            const first = new Date(today.getFullYear(), today.getMonth(), 1);
            const last  = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            s = toISODate(first);
            e = toISODate(last);
            break;
        }
        case 'last-30': {
            const past = new Date(today);
            past.setDate(today.getDate() - 29);
            s = toISODate(past);
            e = toISODate(today);
            break;
        }
        default:
            return;
    }

    // Clamp to min/max bounds if provided
    if (props.min && s < props.min) s = props.min;
    if (props.max && e > props.max) e = props.max;

    emit('update:start', s);
    emit('update:end', e);
}

const hasBothDates = computed(() => !!props.start && !!props.end);

const presets = [
    { key: 'today',      label: 'Today'        },
    { key: 'this-week',  label: 'This Week'    },
    { key: 'this-month', label: 'This Month'   },
    { key: 'last-30',    label: 'Last 30 Days' },
];

// Shared input class
const inputClass = 'w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] font-medium text-on-surface placeholder-on-surface-variant/40 focus:outline-none focus:ring-2 focus:ring-secondary/30 focus:border-secondary transition-colors dark:bg-surface-container-low dark:border-outline-variant dark:text-on-surface';
</script>

<template>
    <div class="w-full">
        <!-- Optional label -->
        <label v-if="label" class="block text-[12px] font-semibold text-on-surface-variant mb-2 uppercase tracking-wide">
            {{ label }}
        </label>

        <div class="flex gap-3 flex-wrap items-end">
            <!-- From -->
            <div class="flex-1 min-w-[140px]">
                <label class="block text-[11px] font-semibold text-on-surface-variant/60 mb-1 uppercase tracking-wide">From</label>
                <div class="relative">
                    <input aria-label="From"
                        type="date"
                        :value="start"
                        :min="min"
                        :max="end || max"
                        :class="inputClass"
                        @change="onStartChange(($event.target as HTMLInputElement).value)"
                    />
                </div>
            </div>

            <!-- Arrow separator -->
            <div class="flex items-center justify-center h-[38px] shrink-0">
                <span class="material-symbols-outlined text-[18px] text-on-surface-variant/40">arrow_forward</span>
            </div>

            <!-- To -->
            <div class="flex-1 min-w-[140px]">
                <label class="block text-[11px] font-semibold text-on-surface-variant/60 mb-1 uppercase tracking-wide">To</label>
                <div class="relative">
                    <input aria-label="To"
                        type="date"
                        :value="end"
                        :min="endMin"
                        :max="max"
                        :class="inputClass"
                        @change="onEndChange(($event.target as HTMLInputElement).value)"
                    />
                </div>
            </div>

            <!-- Clear button -->
            <button
                v-if="start || end"
                type="button"
                @click="clearBoth"
                class="h-[38px] px-3 rounded-xl border border-outline-variant/60 text-[12px] font-semibold text-on-surface-variant/60 hover:text-red-500 hover:border-red-300 hover:bg-red-50/30 dark:hover:bg-red-950/20 transition-colors flex items-center gap-1 shrink-0"
                title="Clear dates"
            >
                <span class="material-symbols-outlined text-[15px]">close</span>
                <span class="hidden sm:inline">Clear</span>
            </button>
        </div>

        <!-- Preset buttons -->
        <div class="flex flex-wrap gap-1.5 mt-2.5">
            <button
                v-for="preset in presets"
                :key="preset.key"
                type="button"
                @click="applyPreset(preset.key)"
                class="px-2.5 py-1 rounded-lg text-[11px] font-semibold border transition-colors"
                :class="[
                    // Highlight if this preset matches current selection (basic check for 'today')
                    (preset.key === 'today' && start === end && start === new Date().toISOString().split('T')[0])
                        ? 'bg-secondary/10 text-secondary border-secondary/30'
                        : 'border-outline-variant/50 text-on-surface-variant/60 hover:border-secondary/40 hover:text-secondary hover:bg-secondary/5',
                ]"
            >
                {{ preset.label }}
            </button>

            <!-- Active range display -->
            <span
                v-if="hasBothDates"
                class="inline-flex items-center gap-1 ml-1 px-2.5 py-1 rounded-lg bg-secondary/8 text-secondary text-[11px] font-bold"
            >
                <span class="material-symbols-outlined text-[12px]">date_range</span>
                {{ start }} &ndash; {{ end }}
            </span>
        </div>

        <!-- Warning message -->
        <Transition name="fade-warn">
            <p
                v-if="rangeWarning"
                class="mt-2 flex items-center gap-1.5 text-amber-600 dark:text-amber-400 text-[12px] font-medium"
            >
                <span class="material-symbols-outlined text-[14px]">warning</span>
                {{ rangeWarning }}
            </p>
        </Transition>
    </div>
</template>

<style scoped>
.fade-warn-enter-active,
.fade-warn-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}
.fade-warn-enter-from,
.fade-warn-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

/* Native date input styling fix for dark mode */
input[type="date"]::-webkit-calendar-picker-indicator {
    opacity: 0.4;
    cursor: pointer;
}
</style>

import { reactive } from 'vue';
import { useSound } from '@/composables/useSound';

const sfx = useSound();

// Singleton toast queue shared across the app.
const state = reactive({
    list: [],
    _id:  0,
});

// Map toast types → sound preset keys.
// `info` and `warning` are first-class variants now (not aliases), so each
// type lands on its own sonic and visual signature.
const SOUND_FOR_TYPE = {
    success: 'success',
    error:   'error',
    info:    'notification',
    warning: 'warning',
};

// How long a toast stays on screen before auto-dismissing. The leave
// animation is owned by the TransitionGroup in Toast.vue, so we just
// splice the item out of the list and the transition handles the fade.
const AUTO_DISMISS_MS = 3000;

function push(type, message, opts = {}) {
    if (!message) return;
    const id = ++state._id;
    state.list.push({ id, type, message });
    setTimeout(() => dismiss(id), AUTO_DISMISS_MS);

    // Fire sound effect unless the caller opts out
    if (opts.silent !== true) {
        const key = opts.sound || SOUND_FOR_TYPE[type] || 'notification';
        sfx.play(key);
    }
}

function dismiss(id) {
    // Mutate the array in place so consumers that hold a reference to
    // `state.list` (e.g. destructured into `const { toasts } = useToast()`)
    // see the update. Reassigning `state.list = …` would replace the
    // reference and leak the stale array to those consumers, which is
    // exactly why the X button used to silently fail.
    const idx = state.list.findIndex(t => t.id === id);
    if (idx !== -1) state.list.splice(idx, 1);
}

export function useToast() {
    return {
        toasts:  state.list,
        success: (msg, opts) => push('success', msg, opts),
        error:   (msg, opts) => push('error',   msg, opts),
        info:    (msg, opts) => push('info',    msg, opts),
        warning: (msg, opts) => push('warning', msg, opts),
        // Domain-specific helpers — pick the right sound automatically
        eventCreated:  (msg) => push('success', msg, { sound: 'event.created'  }),
        assignedToYou: (msg) => push('info',    msg, { sound: 'assigned.you'   }),
        taskCompleted: (msg) => push('success', msg, { sound: 'task.completed' }),
        approved:      (msg) => push('success', msg, { sound: 'approved'       }),
        rejected:      (msg) => push('error',   msg, { sound: 'rejected'       }),
        comingSoon: (label) => push('success', `${label} is coming soon — backend in development.`),
        dismiss,
    };
}

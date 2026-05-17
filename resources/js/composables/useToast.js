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

function push(type, message, opts = {}) {
    if (!message) return;
    const id = ++state._id;
    state.list.push({ id, type, message, visible: false });
    setTimeout(() => {
        const t = state.list.find(t => t.id === id);
        if (t) t.visible = true;
    }, 16);
    setTimeout(() => dismiss(id), 4000);

    // Fire sound effect unless the caller opts out
    if (opts.silent !== true) {
        const key = opts.sound || SOUND_FOR_TYPE[type] || 'notification';
        sfx.play(key);
    }
}

function dismiss(id) {
    const t = state.list.find(t => t.id === id);
    if (!t) return;
    t.visible = false;
    setTimeout(() => {
        state.list = state.list.filter(t => t.id !== id);
    }, 350);
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

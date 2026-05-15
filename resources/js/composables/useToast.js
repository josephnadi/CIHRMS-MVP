import { reactive } from 'vue';

// Singleton toast queue shared across the app.
const state = reactive({
    list: [],
    _id:  0,
});

function push(type, message) {
    if (!message) return;
    const id = ++state._id;
    state.list.push({ id, type, message, visible: false });
    setTimeout(() => {
        const t = state.list.find(t => t.id === id);
        if (t) t.visible = true;
    }, 16);
    setTimeout(() => dismiss(id), 4000);
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
        success: (msg) => push('success', msg),
        error:   (msg) => push('error',   msg),
        info:    (msg) => push('success', msg),
        comingSoon: (label) => push('success', `${label} is coming soon — backend in development.`),
        dismiss,
    };
}

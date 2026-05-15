<script setup>
import { ref, computed, nextTick, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    type: {
        type: String,
        default: 'text',
        validator: (v) => ['text', 'number', 'select'].includes(v),
    },
    options: {
        type: Array,
        default: () => [],
        // For type='select': [{ value, label }]
    },
    placeholder: {
        type: String,
        default: '—',
    },
    readonly: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'save']);

const isEditing = ref(false);
const editValue = ref('');
const inputRef  = ref(null);

// Snapshot of the original value when editing starts (for cancel)
let originalValue = '';

function startEdit() {
    if (props.readonly || props.loading) return;
    originalValue = props.modelValue;
    editValue.value = String(props.modelValue ?? '');
    isEditing.value = true;
    nextTick(() => {
        inputRef.value?.focus();
        if (props.type !== 'select') {
            (inputRef.value)?.select?.();
        }
    });
}

function confirm() {
    if (props.loading) return;
    const newVal = props.type === 'number' ? Number(editValue.value) : editValue.value;
    isEditing.value = false;
    emit('update:modelValue', newVal);
    emit('save', newVal);
}

function cancel() {
    isEditing.value = false;
    editValue.value = String(originalValue ?? '');
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        cancel();
    } else if (event.key === 'Enter' && props.type !== 'select') {
        event.preventDefault();
        confirm();
    }
}

// When modelValue changes externally while not editing, sync display
watch(() => props.modelValue, (val) => {
    if (!isEditing.value) {
        editValue.value = String(val ?? '');
    }
});

// Computed display value for the view mode
const displayValue = computed(() => {
    const v = props.modelValue;
    if (v === null || v === undefined || v === '') return props.placeholder;
    if (props.type === 'select') {
        const opt = props.options.find(o => String(o.value) === String(v));
        return opt ? opt.label : String(v);
    }
    return String(v);
});

const isEmpty = computed(() =>
    props.modelValue === null || props.modelValue === undefined || props.modelValue === ''
);

// Shared input class
const inputClass = 'rounded-lg border border-secondary/50 bg-surface-container-low px-2 py-0.5 text-[13px] text-on-surface font-medium focus:outline-none focus:ring-2 focus:ring-secondary/30 focus:border-secondary transition-colors min-w-0 flex-1';
</script>

<template>
    <div class="inline-flex items-center min-w-0">
        <!-- ── View mode ── -->
        <div
            v-if="!isEditing"
            class="group flex items-center gap-1.5 cursor-pointer min-w-0"
            :class="readonly ? 'cursor-default' : 'cursor-text'"
            @click="startEdit"
            :title="readonly ? '' : 'Click to edit'"
        >
            <!-- Value text -->
            <span
                class="text-[13px] font-medium leading-snug min-w-0 truncate transition-colors"
                :class="[
                    isEmpty ? 'text-on-surface-variant/40 italic' : 'text-on-surface',
                    !readonly && 'group-hover:text-secondary',
                ]"
            >{{ displayValue }}</span>

            <!-- Loading spinner (visible when parent is saving) -->
            <svg
                v-if="loading"
                class="w-3.5 h-3.5 text-secondary animate-spin shrink-0"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
            </svg>

            <!-- Pencil icon (on hover, if not readonly and not loading) -->
            <span
                v-else-if="!readonly"
                class="material-symbols-outlined text-[13px] text-on-surface-variant/0 group-hover:text-secondary/60 transition-all duration-150 shrink-0"
                aria-hidden="true"
            >edit</span>
        </div>

        <!-- ── Edit mode ── -->
        <Transition name="inline-edit" mode="out-in">
            <div
                v-if="isEditing"
                class="flex items-center gap-1 min-w-0 w-full"
                @keydown="onKeydown"
            >
                <!-- Text / Number input -->
                <input
                    v-if="type !== 'select'"
                    ref="inputRef"
                    v-model="editValue"
                    :type="type"
                    :placeholder="placeholder"
                    :class="inputClass"
                    :disabled="loading"
                    autocomplete="off"
                    spellcheck="false"
                />

                <!-- Select input -->
                <select
                    v-else
                    ref="inputRef"
                    v-model="editValue"
                    :class="inputClass + ' appearance-none pr-6'"
                    :disabled="loading"
                >
                    <option value="" disabled>{{ placeholder }}</option>
                    <option
                        v-for="opt in options"
                        :key="opt.value"
                        :value="opt.value"
                    >{{ opt.label }}</option>
                </select>

                <!-- Confirm button (green check) -->
                <button
                    type="button"
                    @click="confirm"
                    :disabled="loading"
                    class="shrink-0 w-6 h-6 rounded-md flex items-center justify-center text-emerald-600 hover:bg-emerald-100 dark:hover:bg-emerald-950/40 disabled:opacity-40 transition-colors"
                    title="Confirm (Enter)"
                    aria-label="Confirm"
                >
                    <svg
                        v-if="loading"
                        class="w-3.5 h-3.5 text-secondary animate-spin"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                    <span
                        v-else
                        class="material-symbols-outlined text-[15px]"
                        style="font-variation-settings: 'FILL' 1, 'wght' 700"
                    >check</span>
                </button>

                <!-- Cancel button (gray ×) -->
                <button
                    type="button"
                    @click="cancel"
                    :disabled="loading"
                    class="shrink-0 w-6 h-6 rounded-md flex items-center justify-center text-on-surface-variant/50 hover:bg-surface-container hover:text-on-surface disabled:opacity-40 transition-colors"
                    title="Cancel (Esc)"
                    aria-label="Cancel"
                >
                    <span class="material-symbols-outlined text-[15px]">close</span>
                </button>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.inline-edit-enter-active,
.inline-edit-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.inline-edit-enter-from {
    opacity: 0;
    transform: scale(0.97);
}
.inline-edit-leave-to {
    opacity: 0;
    transform: scale(0.97);
}
</style>

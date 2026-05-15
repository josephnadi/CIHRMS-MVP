<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: [File, null],
        default: null,
    },
    accept: {
        type: String,
        default: '',
    },
    maxSizeMb: {
        type: Number,
        default: 10,
    },
    label: {
        type: String,
        default: 'Click to upload or drag & drop',
    },
});

const emit = defineEmits(['update:modelValue', 'error']);

const inputRef = ref(null);
const isDragOver = ref(false);
const errorMessage = ref('');

const file = computed(() => props.modelValue);

const fileSizeLabel = computed(() => {
    if (!file.value) return '';
    const bytes = file.value.size;
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
});

const fileTypeIcon = computed(() => {
    if (!file.value) return 'upload_file';
    const name = file.value.name.toLowerCase();
    if (name.endsWith('.pdf')) return 'picture_as_pdf';
    if (name.match(/\.(doc|docx)$/)) return 'description';
    if (name.match(/\.(xls|xlsx)$/)) return 'table_chart';
    if (name.match(/\.(png|jpg|jpeg|gif|webp|svg)$/)) return 'image';
    if (name.match(/\.(zip|rar|7z)$/)) return 'folder_zip';
    return 'insert_drive_file';
});

const acceptedTypesHint = computed(() => {
    if (!props.accept) return 'Any file type';
    return props.accept.replace(/\./g, '').replace(/,/g, ', ').toUpperCase();
});

function validateFile(f) {
    errorMessage.value = '';

    // Size check
    if (f.size > props.maxSizeMb * 1024 * 1024) {
        const msg = `File is too large. Maximum size is ${props.maxSizeMb} MB.`;
        errorMessage.value = msg;
        emit('error', msg);
        return false;
    }

    // Type check
    if (props.accept) {
        const accepted = props.accept.split(',').map(s => s.trim().toLowerCase());
        const fileName = f.name.toLowerCase();
        const mimeType = f.type.toLowerCase();

        const valid = accepted.some(a => {
            if (a.startsWith('.')) return fileName.endsWith(a);
            if (a.includes('*')) {
                const base = a.split('/')[0];
                return mimeType.startsWith(base);
            }
            return mimeType === a;
        });

        if (!valid) {
            const msg = `File type not allowed. Accepted: ${acceptedTypesHint.value}`;
            errorMessage.value = msg;
            emit('error', msg);
            return false;
        }
    }

    return true;
}

function handleFile(f) {
    if (!f) return;
    if (validateFile(f)) {
        emit('update:modelValue', f);
    } else {
        emit('update:modelValue', null);
    }
}

function onInputChange(event) {
    const f = (event.target).files?.[0];
    if (f) handleFile(f);
    // Reset input so the same file can be re-selected after removal
    if (inputRef.value) inputRef.value.value = '';
}

function onDrop(event) {
    event.preventDefault();
    isDragOver.value = false;
    const f = event.dataTransfer?.files?.[0];
    if (f) handleFile(f);
}

function onDragOver(event) {
    event.preventDefault();
    isDragOver.value = true;
}

function onDragLeave() {
    isDragOver.value = false;
}

function openFilePicker() {
    if (!file.value) {
        inputRef.value?.click();
    }
}

function removeFile() {
    errorMessage.value = '';
    emit('update:modelValue', null);
}

function truncateName(name, max = 40) {
    if (name.length <= max) return name;
    const ext = name.lastIndexOf('.');
    if (ext > 0) {
        const base = name.substring(0, ext);
        const extension = name.substring(ext);
        return base.substring(0, max - extension.length - 3) + '...' + extension;
    }
    return name.substring(0, max - 3) + '...';
}
</script>

<template>
    <div class="w-full">
        <!-- Drop zone -->
        <div
            @click="openFilePicker"
            @drop="onDrop"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            class="relative border-2 border-dashed rounded-xl p-6 text-center transition-colors duration-200"
            :class="[
                errorMessage
                    ? 'border-red-400 bg-red-50/30 dark:border-red-500/50 dark:bg-red-950/20'
                    : isDragOver
                        ? 'border-secondary bg-secondary/5 cursor-copy'
                        : file
                            ? 'border-secondary/30 bg-surface-container-lowest cursor-default'
                            : 'border-outline-variant bg-surface-container-low/50 hover:border-secondary/40 hover:bg-surface-container-low cursor-pointer',
            ]"
        >
            <!-- Hidden real input -->
            <input
                ref="inputRef"
                type="file"
                :accept="accept"
                class="hidden"
                @change="onInputChange"
                tabindex="-1"
            />

            <!-- Idle / drag-over state -->
            <div v-if="!file" class="flex flex-col items-center gap-3 select-none pointer-events-none">
                <div
                    class="w-12 h-12 rounded-2xl flex items-center justify-center transition-colors duration-200"
                    :class="isDragOver ? 'bg-secondary/15' : 'bg-surface-container'"
                >
                    <span
                        class="material-symbols-outlined text-[24px] transition-colors duration-200"
                        :class="isDragOver ? 'text-secondary' : 'text-on-surface-variant/50'"
                    >upload_file</span>
                </div>
                <div>
                    <p class="text-[13px] font-semibold text-on-surface">
                        <span
                            v-if="!isDragOver"
                            class="text-secondary"
                        >Click to upload</span>
                        <span v-else class="text-secondary">Drop file here</span>
                        <span v-if="!isDragOver" class="text-on-surface-variant/70"> or drag & drop</span>
                    </p>
                    <p class="mt-1 text-[11px] text-on-surface-variant/50 font-medium">
                        {{ acceptedTypesHint }}
                        &mdash; max {{ maxSizeMb }} MB
                    </p>
                </div>
            </div>

            <!-- File selected state -->
            <div
                v-else
                class="flex items-center gap-3 text-left pointer-events-none"
            >
                <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-secondary/10 shrink-0">
                    <span class="material-symbols-outlined text-[20px] text-secondary">{{ fileTypeIcon }}</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-[13px] font-semibold text-on-surface truncate">{{ truncateName(file.name) }}</p>
                    <p class="text-[11px] text-on-surface-variant/60 font-medium mt-0.5">
                        {{ fileSizeLabel }}
                        <span class="mx-1 opacity-40">&bull;</span>
                        {{ file.type || 'Unknown type' }}
                    </p>
                </div>
                <!-- Remove button — pointer-events-auto to allow click on it -->
                <button
                    type="button"
                    @click.stop="removeFile"
                    class="pointer-events-auto shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-on-surface-variant/50 hover:bg-red-100 hover:text-red-500 dark:hover:bg-red-950/40 dark:hover:text-red-400 transition-colors"
                    aria-label="Remove file"
                >
                    <span class="material-symbols-outlined text-[16px]">close</span>
                </button>
            </div>
        </div>

        <!-- Error message -->
        <Transition name="fade-error">
            <div
                v-if="errorMessage"
                class="mt-2 flex items-center gap-1.5 text-red-500 dark:text-red-400"
            >
                <span class="material-symbols-outlined text-[14px]">error</span>
                <span class="text-[12px] font-medium">{{ errorMessage }}</span>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.fade-error-enter-active,
.fade-error-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.fade-error-enter-from,
.fade-error-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>

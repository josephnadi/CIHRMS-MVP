<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue: { type: [Number, null], default: null },
});
const emit = defineEmits(['update:modelValue']);

const query = ref('');
const results = ref([]);
const selectedLabel = ref('');
const loading = ref(false);
const open = ref(false);
let debounceTimer = null;

watch(() => props.modelValue, (id) => {
    // If the parent clears the selection (e.g., form reset), clear our label too.
    if (id == null) {
        selectedLabel.value = '';
        query.value = '';
    }
});

function onInput() {
    open.value = true;
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(search, 200);
}

async function search() {
    const q = query.value.trim();
    if (q.length < 2) {
        results.value = [];
        return;
    }
    loading.value = true;
    try {
        const res = await fetch(route('documents.users.search') + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });
        const json = await res.json();
        results.value = json.data ?? [];
    } catch (e) {
        results.value = [];
    } finally {
        loading.value = false;
    }
}

function pick(user) {
    emit('update:modelValue', user.id);
    selectedLabel.value = `${user.name} (${user.staff_id})`;
    query.value = selectedLabel.value;
    open.value = false;
    results.value = [];
}

function clearSelection() {
    emit('update:modelValue', null);
    selectedLabel.value = '';
    query.value = '';
    results.value = [];
}

onBeforeUnmount(() => { if (debounceTimer) clearTimeout(debounceTimer); });
</script>

<template>
    <div class="relative flex-1">
        <div class="flex items-center gap-1">
            <input aria-label="Query" v-model="query"
                   @input="onInput"
                   @focus="open = results.length > 0"
                   placeholder="Search by name or staff ID…"
                   class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
            <button v-if="modelValue" type="button" @click="clearSelection"
                    class="text-on-surface-variant text-[14px] hover:text-rose-600"
                    title="Clear selection">✕</button>
        </div>
        <div v-if="open && (results.length || loading)"
             class="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-outline-variant bg-surface-container-lowest shadow-card-hover">
            <div v-if="loading" class="px-3 py-2 text-[11px] text-on-surface-variant">Searching…</div>
            <button v-for="u in results" :key="u.id"
                    type="button"
                    @mousedown.prevent="pick(u)"
                    class="block w-full text-left px-3 py-2 text-[12.5px] hover:bg-surface-container-low">
                <span class="font-black text-primary">{{ u.name }}</span>
                <span class="ml-2 font-mono text-[11px] text-on-surface-variant">{{ u.staff_id }}</span>
            </button>
            <div v-if="!loading && !results.length && query.length >= 2"
                 class="px-3 py-2 text-[11px] text-on-surface-variant">No matches.</div>
        </div>
    </div>
</template>

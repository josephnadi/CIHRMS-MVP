<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

const locales = computed(() => page.props.i18n?.supported ?? {
    en: { label: 'English',     native: 'English' },
    tw: { label: 'Twi',         native: 'Twi (Akan)' },
    ga: { label: 'Ga',          native: 'GÃ£' },
    ee: { label: 'Ewe',         native: 'EÊ‹egbe' },
});

const current = computed(() => page.props.i18n?.locale ?? 'en');

const change = (event) => {
    const code = event.target.value;
    if (code === current.value) return;
    router.post(route('locale.update'), { locale: code }, {
        preserveScroll: true,
        preserveState: false,   // full reload picks up new translations
    });
};
</script>

<template>
    <label class="locale-switcher" :title="locales[current]?.native">
        <span class="material-symbols-outlined locale-icon">language</span>
        <select :value="current" @change="change" class="locale-select" aria-label="Choose language">
            <option v-for="(meta, code) in locales" :key="code" :value="code">
                {{ meta.native }}
            </option>
        </select>
    </label>
</template>

<style scoped>
.locale-switcher {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.7rem;
    border: 1px solid rgba(10, 31, 92, 0.14);
    border-radius: 999px;
    background: #ffffff;
    color: #0d1452;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: border-color 0.18s ease, background 0.18s ease;
}
.locale-switcher:hover {
    border-color: rgba(10, 31, 92, 0.28);
    background: rgba(10, 31, 92, 0.03);
}
.locale-icon {
    font-size: 16px;
    color: #1a237e;
}
.locale-select {
    border: none;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
    padding-right: 0.25rem;
}
.locale-select:focus { outline: none; }
</style>

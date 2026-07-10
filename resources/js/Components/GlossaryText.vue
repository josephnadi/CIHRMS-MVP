<script setup>
import { computed } from 'vue';
import { AUTO_TERM_PATTERN } from '@/glossary';
import Term from '@/Components/Term.vue';

// Renders a plain label string, auto-wrapping any whole-word glossary
// abbreviation (AR, PAYE, SSNIT, …) in a <Term> tooltip and leaving the rest
// as text. Safe for UI chrome (headings, stat labels, table column headers) —
// NOT for free-form user data. Case-sensitive exact-token matching means
// ordinary words are never wrapped.
const props = defineProps({
    text: { type: [String, Number], default: '' },
});

const segments = computed(() => {
    const s = String(props.text ?? '');
    if (! AUTO_TERM_PATTERN || ! s) return [{ term: false, value: s }];

    const out = [];
    let rest = s;
    // Fresh 'g' regex per eval so lastIndex state never leaks between renders.
    const splitter = new RegExp(AUTO_TERM_PATTERN.source, 'g');
    let last = 0, m;
    while ((m = splitter.exec(rest)) !== null) {
        if (m.index > last) out.push({ term: false, value: rest.slice(last, m.index) });
        out.push({ term: true, value: m[1] });
        last = m.index + m[1].length;
    }
    if (last < rest.length) out.push({ term: false, value: rest.slice(last) });
    return out.length ? out : [{ term: false, value: s }];
});
</script>

<template>
    <span><template v-for="(seg, i) in segments" :key="i"><Term v-if="seg.term" :code="seg.value" /><template v-else>{{ seg.value }}</template></template></span>
</template>

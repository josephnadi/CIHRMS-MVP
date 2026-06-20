<script setup>
import { ref } from 'vue';
import { Bar } from 'vue-chartjs';
import {
    Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale,
} from 'chart.js';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale);

defineProps({
    data:    { type: Object, required: true },
    options: { type: Object, default: () => ({ responsive: true, maintainAspectRatio: false }) },
});

const chartRef = ref(null);
// Expose the underlying Chart.js instance for PNG export (ref.value.toBase64Image()).
defineExpose({
    toBase64Image: () => chartRef.value?.chart?.toBase64Image?.() ?? null,
});
</script>

<template>
    <Bar ref="chartRef" :data="data" :options="options" />
</template>

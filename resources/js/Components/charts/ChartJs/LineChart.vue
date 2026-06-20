<script setup>
import { Line } from 'vue-chartjs';
import {
    Chart as ChartJS, Title, Tooltip, Legend, LineElement, PointElement, CategoryScale, LinearScale, Filler,
} from 'chart.js';

ChartJS.register(Title, Tooltip, Legend, LineElement, PointElement, CategoryScale, LinearScale, Filler);

defineProps({
    data:    { type: Object, required: true },
    options: { type: Object, default: () => ({ responsive: true, maintainAspectRatio: false }) },
});
</script>

<template>
    <Line :data="data" :options="options" />
</template>

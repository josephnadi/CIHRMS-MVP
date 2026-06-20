<script setup>
import { Doughnut } from 'vue-chartjs';
import {
    Chart as ChartJS, Title, Tooltip, Legend, ArcElement,
} from 'chart.js';

ChartJS.register(Title, Tooltip, Legend, ArcElement);

defineProps({
    data:    { type: Object, required: true },
    options: { type: Object, default: () => ({ responsive: true, maintainAspectRatio: false }) },
});
</script>

<template>
    <Doughnut :data="data" :options="options" />
</template>

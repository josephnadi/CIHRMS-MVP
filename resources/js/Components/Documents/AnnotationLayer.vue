<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import DraggableAnnotation from './DraggableAnnotation.vue';

const props = defineProps({
    annotations: { type: Array, default: () => [] },
    page:        { type: Number, default: 1 },
    pageSize:    { type: Object, required: true },
    canPlace:    { type: Boolean, default: false },
    pending:     { type: Object, default: null },
    docUuid:     { type: String, required: true },
    docStatus:   { type: String, required: true },
    docOwnerId:  { type: Number, required: true },
});
const emit = defineEmits(['place']);

const pageInst = usePage();
const currentUserId = computed(() => pageInst.props.auth.user.id);
const visible = computed(() => props.annotations.filter(a => a.page === props.page));

function canManipulate(a) {
    if (a.user?.id === currentUserId.value) return a.route_status !== 'completed';
    if (props.docOwnerId === currentUserId.value && props.docStatus === 'draft') return true;
    return false;
}

function handleClick(e) {
    if (! props.canPlace || ! props.pending) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const x_pct = ((e.clientX - rect.left) / rect.width)  * 100;
    const y_pct = ((e.clientY - rect.top)  / rect.height) * 100;
    emit('place', { x_pct, y_pct, page: props.page });
}

function onAnnotationUpdate(a, geometry) {
    router.patch(
        route('documents.annotations.update', { document: props.docUuid, annotation: a.id }),
        geometry,
        { preserveScroll: true, preserveState: true },
    );
}

function onAnnotationDelete(a) {
    if (! confirm('Remove this annotation?')) return;
    router.delete(
        route('documents.annotations.destroy', { document: props.docUuid, annotationId: a.id }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <div data-annotation-layer class="absolute inset-0" :class="canPlace ? 'cursor-crosshair' : ''" @click="handleClick">
        <DraggableAnnotation v-for="a in visible" :key="a.id"
            :annotation="a"
            :can-manipulate="canManipulate(a)"
            @update="g => onAnnotationUpdate(a, g)"
            @delete="onAnnotationDelete(a)" />
    </div>
</template>

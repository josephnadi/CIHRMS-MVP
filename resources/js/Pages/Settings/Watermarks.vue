<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    templates:    { type: Object, required: true },
    canManageOrg: { type: Boolean, default: false },
    departmentId: { type: Number, default: null },
});

const scope = ref('personal');
const form = useForm({
    name: '', owner_scope: 'personal', owner_id: null,
    type: 'text', text: '', color: '#dc2626', opacity: 0.18, angle_deg: -30, file: null,
});

function submit() {
    form.transform((d) => ({
        ...d,
        owner_id: d.owner_scope === 'department' ? props.departmentId : null,
    })).post(route('settings.watermarks.store'), {
        forceFormData: true, onSuccess: () => form.reset(),
    });
}

function remove(t) {
    if (! confirm(`Remove watermark "${t.name}"?`)) return;
    router.delete(route('settings.watermarks.destroy', t.id), { preserveScroll: true });
}

const SCOPES = ['personal', 'department', 'organization'];
</script>

<template>
    <Head title="Watermarks" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">SETTINGS · WATERMARKS</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Watermark templates</h1>
                <p class="mt-1 text-[13px] text-on-surface-variant">Text or PNG watermarks applied to burned PDFs.</p>
            </div>
        </Teleport>

        <form @submit.prevent="submit" enctype="multipart/form-data"
              class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card mb-6 grid md:grid-cols-6 gap-3">
            <div>
                <input aria-label="Name" v-model="form.name" required placeholder="Name"
                       class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
                <p v-if="form.errors.name" class="mt-1 text-[11px] text-rose-600">{{ form.errors.name }}</p>
            </div>
            <div>
                <select v-model="form.owner_scope" aria-label="Ownership scope" class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                    <option v-for="s in SCOPES" :key="s" :value="s"
                            :disabled="(s === 'organization' && !canManageOrg) || (s === 'department' && !departmentId)">{{ s }}</option>
                </select>
                <p v-if="form.errors.owner_id" class="mt-1 text-[11px] text-rose-600">{{ form.errors.owner_id }}</p>
            </div>
            <select v-model="form.type" aria-label="Watermark type" class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                <option value="text">Text</option>
                <option value="image">Image (PNG)</option>
            </select>
            <template v-if="form.type === 'text'">
                <div>
                    <input aria-label="Text" v-model="form.text" required placeholder="WATERMARK TEXT"
                           class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px] font-bold uppercase" />
                    <p v-if="form.errors.text" class="mt-1 text-[11px] text-rose-600">{{ form.errors.text }}</p>
                </div>
                <div>
                    <input v-model="form.color" type="color" aria-label="Watermark colour" class="rounded-lg border border-outline-variant w-full h-10" />
                </div>
                <div>
                    <input v-model.number="form.opacity" type="number" step="0.01" min="0.05" max="1" aria-label="Opacity"
                           class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]" placeholder="Opacity" />
                    <p v-if="form.errors.opacity" class="mt-1 text-[11px] text-rose-600">{{ form.errors.opacity }}</p>
                </div>
                <div>
                    <input v-model.number="form.angle_deg" type="number" min="-90" max="90" aria-label="Angle degrees"
                           class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]" placeholder="Angle°" />
                    <p v-if="form.errors.angle_deg" class="mt-1 text-[11px] text-rose-600">{{ form.errors.angle_deg }}</p>
                </div>
            </template>
            <template v-else>
                <div class="md:col-span-2">
                    <input type="file" required accept="image/png" aria-label="Watermark file (PNG)"
                           @change="e => form.file = e.target.files[0]" class="text-[12px]" />
                    <p v-if="form.errors.file" class="mt-1 text-[11px] text-rose-600">{{ form.errors.file }}</p>
                </div>
            </template>
            <button type="submit" :disabled="form.processing"
                    class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                {{ form.processing ? 'Saving…' : 'Save' }}
            </button>
        </form>

        <div class="flex gap-2 mb-3">
            <button v-for="s in SCOPES" :key="s" @click="scope = s"
                    :class="['rounded-xl px-3 py-1.5 text-[11px] font-black uppercase tracking-widest',
                             scope === s ? 'bg-secondary/10 text-secondary' : 'text-on-surface-variant']">{{ s }}</button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div v-for="t in templates.data.filter(x => x.owner_scope === scope)" :key="t.id"
                 class="rounded-xl border border-outline-variant/50 bg-surface-container-lowest p-3 shadow-card">
                <img v-if="t.type === 'image'" :src="t.preview_url" :alt="t.name" class="h-24 w-full object-contain bg-white rounded" />
                <div v-else class="flex items-center justify-center h-24 bg-white rounded">
                    <span :style="{ color: t.color, opacity: t.opacity, transform: `rotate(${t.angle_deg}deg)` }"
                          class="font-black text-[18px] tracking-wider">{{ t.text }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <p class="text-[12px] font-black truncate">{{ t.name }}</p>
                    <button @click="remove(t)" class="text-[11px] font-black text-rose-600">Remove</button>
                </div>
            </div>
            <p v-if="!templates.data.some(x => x.owner_scope === scope)" class="col-span-full text-center text-on-surface-variant text-[12px] py-6">
                No {{ scope }} watermarks yet.
            </p>
        </div>
    </div>
</template>

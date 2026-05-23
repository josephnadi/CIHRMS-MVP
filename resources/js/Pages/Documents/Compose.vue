<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

defineProps({
    activeModule: { type: String, default: 'documents' },
});

// ─── Composer state ────────────────────────────────────────────────────────
// `body_html` is the contenteditable's innerHTML. We mirror it into a ref so
// Inertia useForm() sees changes, and into the live preview iframe.
const form = useForm({
    title:           '',
    description:    '',
    confidentiality: 'internal',
    letterhead_id:   null,
    body_html:       '<p>Dear &lt;recipient&gt;,</p><p>&nbsp;</p><p>Body of your letter goes here…</p><p>&nbsp;</p><p>Yours faithfully,</p><p>&lt;Your name&gt;</p>',
});

// ─── Letterhead templates ──────────────────────────────────────────────────
// Pulled from the Settings/Letterheads index endpoint via XHR so we can
// populate the dropdown without an Inertia page reload. The endpoint returns
// an Inertia page payload — we just want the props.templates.data array.
const templates = ref([]);

async function loadTemplates() {
    const res = await axios.get(route('settings.letterheads.index'), {
        headers: {
            'X-Inertia': 'true',
            'X-Inertia-Version': '0',
            Accept: 'application/json',
        },
    });
    templates.value = res.data?.props?.templates?.data ?? [];
    if (! form.letterhead_id) {
        const def = templates.value.find(t => t.is_default);
        if (def) form.letterhead_id = def.id;
    }
}

const selectedTemplate = computed(() => templates.value.find(t => t.id === form.letterhead_id));

const editorRef  = ref(null);
const previewRef = ref(null);

// Sync editor → form.body_html on input.
function syncFromEditor() {
    if (! editorRef.value) return;
    form.body_html = editorRef.value.innerHTML;
}

// ─── Toolbar commands ──────────────────────────────────────────────────────
// `execCommand` is "deprecated" but every browser still implements it and the
// alternative (custom selection/range manipulation) is a large project of its
// own. For a v1 in-portal letter editor it's more than sufficient.
function cmd(name, value = null) {
    if (! editorRef.value) return;
    editorRef.value.focus();
    document.execCommand(name, false, value);
    syncFromEditor();
}

function insertHeading(level) {
    cmd('formatBlock', `<h${level}>`);
}

function insertList(ordered) {
    cmd(ordered ? 'insertOrderedList' : 'insertUnorderedList');
}

function align(direction) {
    cmd('justify' + direction);
}

function insertLink() {
    const url = prompt('Link URL');
    if (! url) return;
    cmd('createLink', url);
}

function insertHorizontalRule() {
    cmd('insertHorizontalRule');
}

function clearFormatting() {
    cmd('removeFormat');
    cmd('formatBlock', '<p>');
}

// ─── Live preview ──────────────────────────────────────────────────────────
// Render the current `body_html` in an iframe with the same letterhead
// styling that TCPDF will use, so the user can see (approximately) what the
// final PDF will look like before they hit Save.
const previewHtml = computed(() => {
    const lh = selectedTemplate.value
        ? `<header style="border-bottom:1px solid #c9a227;padding-bottom:8px;margin-bottom:18px;">
             <img src="${selectedTemplate.value.preview_url}" style="width:100%;max-height:${selectedTemplate.value.header_height_mm * 3}px;object-fit:contain;" />
           </header>`
        : '';
    return `<!doctype html>
<html><head><meta charset="utf-8"><style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #1a1a1a; line-height: 1.5; padding: 18mm 20mm 20mm; margin: 0; background: #fff; }
  h1, h2, h3 { font-family: Helvetica, Arial, sans-serif; color: #0d1452; }
  h1 { font-size: 16pt; } h2 { font-size: 14pt; } h3 { font-size: 12pt; }
  p { margin: 0 0 10px; }
  ul, ol { margin: 0 0 10px 20px; }
  blockquote { border-left: 3px solid #ccc; padding-left: 10px; color: #555; margin: 10px 0; }
  hr { border: 0; border-top: 1px solid #ddd; margin: 14px 0; }
  a { color: #205295; }
</style></head>
<body>${lh}${form.body_html}</body></html>`;
});

function refreshPreview() {
    if (! previewRef.value) return;
    previewRef.value.srcdoc = previewHtml.value;
}

watch([() => form.body_html, () => form.letterhead_id, templates], refreshPreview, { deep: true });
onMounted(() => {
    // Initial editor content + initial preview render.
    if (editorRef.value) editorRef.value.innerHTML = form.body_html;
    refreshPreview();
    loadTemplates();
});

// ─── Submit ────────────────────────────────────────────────────────────────
function submit() {
    syncFromEditor();
    form.post(route('documents.compose.store'));
}

// Tiny toolbar metadata.
const TOOLS = [
    { cmd: () => cmd('bold'),         icon: 'format_bold',         title: 'Bold (Ctrl+B)' },
    { cmd: () => cmd('italic'),       icon: 'format_italic',       title: 'Italic (Ctrl+I)' },
    { cmd: () => cmd('underline'),    icon: 'format_underlined',   title: 'Underline (Ctrl+U)' },
    null,
    { cmd: () => insertHeading(1),    icon: 'title',               title: 'Heading 1', label: 'H1' },
    { cmd: () => insertHeading(2),    icon: 'subtitles',           title: 'Heading 2', label: 'H2' },
    { cmd: () => insertHeading(3),    icon: 'segment',             title: 'Heading 3', label: 'H3' },
    null,
    { cmd: () => insertList(false),   icon: 'format_list_bulleted',title: 'Bulleted list' },
    { cmd: () => insertList(true),    icon: 'format_list_numbered',title: 'Numbered list' },
    null,
    { cmd: () => align('Left'),       icon: 'format_align_left',   title: 'Align left' },
    { cmd: () => align('Center'),     icon: 'format_align_center', title: 'Center' },
    { cmd: () => align('Right'),      icon: 'format_align_right',  title: 'Align right' },
    null,
    { cmd: () => cmd('formatBlock', '<blockquote>'), icon: 'format_quote', title: 'Blockquote' },
    { cmd: insertLink,                icon: 'link',                title: 'Insert link' },
    { cmd: insertHorizontalRule,      icon: 'horizontal_rule',     title: 'Horizontal rule' },
    null,
    { cmd: clearFormatting,           icon: 'format_clear',        title: 'Clear formatting' },
];
</script>

<template>
    <Head title="Compose Document" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">edit_note</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">COMPOSE LETTER</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">New document</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Write a letter or memo directly in the portal — attach the institutional letterhead and turn it into a routable document in one click.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <select v-model="form.letterhead_id" aria-label="Letterhead template"
                            class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-black">
                        <option :value="null">No letterhead</option>
                        <option v-for="t in templates" :key="t.id" :value="t.id">
                            {{ t.name }} ({{ t.owner_scope }})
                        </option>
                    </select>
                    <button @click="submit" :disabled="form.processing || ! form.title || ! form.body_html"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px disabled:opacity-50"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                        <span class="material-symbols-outlined text-[17px]">save</span>
                        {{ form.processing ? 'Saving…' : 'Save as Document' }}
                    </button>
                </div>
            </div>
        </Teleport>

        <div class="grid lg:grid-cols-2 gap-6">
            <!-- ─── Composer pane ───────────────────────────────────── -->
            <section class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card space-y-3">
                <!-- Meta -->
                <div class="grid grid-cols-1 gap-3">
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant mb-1">Title</label>
                        <input v-model="form.title" required maxlength="255"
                               class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px] font-bold"
                               placeholder="e.g. Memo to Finance — Q2 budget submission" />
                        <p v-if="form.errors.title" class="text-rose-600 text-xs mt-1">{{ form.errors.title }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant mb-1">Confidentiality</label>
                            <select v-model="form.confidentiality" aria-label="Confidentiality level" class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                                <option value="internal">Internal</option>
                                <option value="confidential">Confidential</option>
                                <option value="restricted">Restricted</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant mb-1">Description (optional)</label>
                            <input v-model="form.description" maxlength="2000"
                                   class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]"
                                   placeholder="Short note for the audit trail" />
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center gap-1 rounded-lg border border-outline-variant/50 bg-surface-container-low p-1.5">
                    <template v-for="(tool, i) in TOOLS" :key="i">
                        <div v-if="tool === null" class="h-5 w-px bg-outline-variant/40 mx-1"></div>
                        <button v-else type="button"
                                @click="tool.cmd"
                                :title="tool.title"
                                class="flex items-center justify-center rounded-md h-8 min-w-[32px] px-1.5 hover:bg-secondary/10 hover:text-secondary text-on-surface-variant transition-colors">
                            <span v-if="tool.label" class="text-[11px] font-black">{{ tool.label }}</span>
                            <span v-else class="material-symbols-outlined text-[18px]">{{ tool.icon }}</span>
                        </button>
                    </template>
                </div>

                <!-- Editor -->
                <div class="rounded-lg border border-outline-variant/60 bg-white">
                    <div ref="editorRef"
                         contenteditable="true"
                         @input="syncFromEditor"
                         @paste="syncFromEditor"
                         spellcheck="true"
                         class="min-h-[420px] max-h-[640px] overflow-y-auto px-5 py-4 text-[13.5px] leading-relaxed text-slate-900 focus:outline-none prose-compose">
                    </div>
                </div>

                <p v-if="form.errors.body_html" class="text-rose-600 text-xs">{{ form.errors.body_html }}</p>
            </section>

            <!-- ─── Live preview ───────────────────────────────────── -->
            <section class="rounded-2xl border border-outline-variant/50 bg-surface-container-low p-3 shadow-card">
                <div class="flex items-center justify-between mb-2 px-1">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary">PDF Preview</p>
                    <p class="text-[10px] font-mono text-on-surface-variant">
                        {{ selectedTemplate ? `${selectedTemplate.name} · A4` : 'plain · A4' }}
                    </p>
                </div>
                <iframe ref="previewRef" class="w-full bg-white rounded-lg border border-outline-variant"
                        style="height: 720px;"
                        sandbox="allow-same-origin"
                        title="Live preview">
                </iframe>
                <p class="mt-2 text-[11px] text-on-surface-variant px-1">
                    The exported PDF uses the same fonts and layout. After saving you can sign, stamp, route, and print it like any uploaded document.
                </p>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* Keep the editor's typography close to the rendered PDF so the live preview
   matches what the user types. Scoped so it doesn't leak. */
.prose-compose :deep(h1) { font-size: 1.4rem; font-weight: 900; color: #0d1452; margin: 0.6rem 0; }
.prose-compose :deep(h2) { font-size: 1.2rem; font-weight: 800; color: #0d1452; margin: 0.5rem 0; }
.prose-compose :deep(h3) { font-size: 1.05rem; font-weight: 800; color: #0d1452; margin: 0.4rem 0; }
.prose-compose :deep(p)  { margin: 0 0 0.55rem; }
.prose-compose :deep(ul), .prose-compose :deep(ol) { margin: 0 0 0.55rem 1.4rem; }
.prose-compose :deep(blockquote) { border-left: 3px solid #cbd5e1; padding-left: 0.6rem; color: #475569; margin: 0.4rem 0; }
.prose-compose :deep(hr) { border: 0; border-top: 1px solid #e2e8f0; margin: 0.7rem 0; }
.prose-compose :deep(a)  { color: #205295; text-decoration: underline; }
</style>

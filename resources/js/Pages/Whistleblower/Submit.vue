<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
defineProps({
    categories: Array,
});

const form = useForm({
    category:           '',
    subject_summary:    '',
    description:        '',
    desired_outcome:    '',
    incident_location:  '',
    incident_date:      '',
    is_anonymous:       true,
    submitter_contact:  '',
    subjects: [{ label: '', role_context: '' }],
    evidence: [],
});

const addSubject = () => form.subjects.push({ label: '', role_context: '' });
const removeSubject = (i) => form.subjects.splice(i, 1);

const onFile = (e) => { form.evidence = Array.from(e.target.files); };

const submit = () => form.post(route('whistleblower.submit'), {
    forceFormData: true,
});

const charCount = computed(() => (form.description || '').length);
</script>

<template>
    <Head title="Confidential Disclosure — CIHRM Ghana" />

    <div class="min-h-screen bg-background text-on-surface">
        <!-- Stripped-down header — no logged-in chrome, deliberately minimal for privacy -->
        <header class="border-b border-outline-variant/40">
            <div class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="h-9 w-9 rounded-xl bg-brand-navy flex items-center justify-center">
                        <span class="material-symbols-outlined text-white text-[20px]" style="font-variation-settings:'FILL' 1">shield</span>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/60">CIHRM Ghana</p>
                        <h1 class="text-base font-bold tracking-tight">Confidential Disclosure Channel</h1>
                    </div>
                </div>
                <Link :href="route('whistleblower.track')" class="text-sm text-secondary hover:underline">Track an existing case →</Link>
            </div>
        </header>

        <main class="max-w-3xl mx-auto px-6 py-10 space-y-8">
            <!-- Legal & privacy notice -->
            <div class="rounded-2xl bg-brand-navy/[0.04] border border-brand-navy/15 p-6 space-y-3">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Your protection under the law</p>
                <p class="text-sm text-on-surface-variant leading-relaxed">
                    This channel is established under the <strong>Whistleblower Act 2006 (Act 720)</strong> of Ghana.
                    You can disclose corruption, fraud, harassment, safety risks, or other improper conduct.
                    <strong>Retaliation against a whistleblower is a criminal offence.</strong>
                </p>
                <p class="text-sm text-on-surface-variant leading-relaxed">
                    Your disclosure is encrypted on submission. If you choose <strong>anonymous</strong>, we do not store
                    your name, IP address, or any contact details. You will receive a one-time <strong>tracking code</strong>
                    to check status and exchange messages with the investigator.
                    For urgent matters you may also disclose directly to CHRAJ, the Auditor-General, or the Police.
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Anonymity toggle — most prominent control -->
                <div class="rounded-xl border border-outline-variant/40 p-5 bg-surface-container-lowest">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input v-model="form.is_anonymous" type="checkbox"
                               class="mt-0.5 h-5 w-5 rounded border-outline-variant text-secondary focus:ring-secondary">
                        <div>
                            <p class="font-semibold text-sm">Submit anonymously</p>
                            <p class="text-xs text-on-surface-variant mt-1">
                                Recommended. We will not record your identity. Communication is one-way via your tracking code.
                            </p>
                        </div>
                    </label>
                    <div v-if="! form.is_anonymous" class="mt-4 ml-8">
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">
                            Contact (email or phone) — encrypted, visible only to the investigator
                        </label>
                        <input v-model="form.submitter_contact" aria-label="Contact details (email or phone)" class="w-full rounded-lg border-outline-variant text-sm">
                        <p v-if="form.errors.submitter_contact" class="text-rose-600 text-xs mt-1">{{ form.errors.submitter_contact }}</p>
                    </div>
                </div>

                <!-- Category -->
                <div>
                    <label class="block text-sm font-semibold mb-2">What is this about?</label>
                    <select v-model="form.category" aria-label="Report category" class="w-full rounded-lg border-outline-variant" required>
                        <option value="">Select a category…</option>
                        <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                    <p v-if="form.errors.category" class="text-rose-600 text-xs mt-1">{{ form.errors.category }}</p>
                </div>

                <!-- Short title -->
                <div>
                    <label class="block text-sm font-semibold mb-2">Short title</label>
                    <input aria-label="Short title" v-model="form.subject_summary" maxlength="255" required
                           placeholder="A one-line summary the investigator will see in the dashboard"
                           class="w-full rounded-lg border-outline-variant">
                    <p v-if="form.errors.subject_summary" class="text-rose-600 text-xs mt-1">{{ form.errors.subject_summary }}</p>
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-semibold mb-2">Tell us what happened</label>
                    <textarea aria-label="Tell us what happened" v-model="form.description" rows="8" required minlength="30" maxlength="20000"
                              class="w-full rounded-lg border-outline-variant"
                              placeholder="Describe the incident in your own words. Include who, what, where, when, and how you know."></textarea>
                    <div class="flex justify-between text-xs text-on-surface-variant/70 mt-1">
                        <span v-if="form.errors.description" class="text-rose-600">{{ form.errors.description }}</span>
                        <span></span>
                        <span>{{ charCount }} / 20000 — encrypted</span>
                    </div>
                </div>

                <!-- Optional details -->
                <details class="rounded-xl border border-outline-variant/40">
                    <summary class="px-5 py-3 cursor-pointer font-semibold text-sm">Optional details (location, date, people involved)</summary>
                    <div class="px-5 pb-5 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-on-surface-variant mb-1">When did it happen?</label>
                                <input v-model="form.incident_date" aria-label="Incident date" type="date" class="w-full rounded-lg border-outline-variant">
                                <p v-if="form.errors.incident_date" class="text-rose-600 text-xs mt-1">{{ form.errors.incident_date }}</p>
                            </div>
                            <div>
                                <label class="block text-xs text-on-surface-variant mb-1">Where did it happen?</label>
                                <input aria-label="Where did it happen?" v-model="form.incident_location" class="w-full rounded-lg border-outline-variant"
                                       placeholder="Office, department, location">
                                <p v-if="form.errors.incident_location" class="text-rose-600 text-xs mt-1">{{ form.errors.incident_location }}</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs text-on-surface-variant mb-2">People involved (optional)</label>
                            <div v-for="(s, i) in form.subjects" :key="i" class="mb-2">
                                <div class="flex gap-2">
                                    <input v-model="s.label" :aria-label="`Subject ${i + 1} name or role`" placeholder="Name or role" class="flex-1 rounded-lg border-outline-variant text-sm">
                                    <input v-model="s.role_context" :aria-label="`Subject ${i + 1} role in incident`" placeholder="Their role in the incident" class="flex-1 rounded-lg border-outline-variant text-sm">
                                    <button type="button" @click="removeSubject(i)" class="text-rose-600 text-sm hover:underline">Remove</button>
                                </div>
                                <div class="flex gap-2 mt-1">
                                    <p v-if="form.errors[`subjects.${i}.label`]" class="flex-1 text-rose-600 text-xs">{{ form.errors[`subjects.${i}.label`] }}</p>
                                    <p v-if="form.errors[`subjects.${i}.role_context`]" class="flex-1 text-rose-600 text-xs">{{ form.errors[`subjects.${i}.role_context`] }}</p>
                                </div>
                            </div>
                            <button type="button" @click="addSubject" class="text-secondary text-xs hover:underline">+ Add another person</button>
                        </div>

                        <div>
                            <label class="block text-xs text-on-surface-variant mb-1">Desired outcome</label>
                            <textarea aria-label="Desired outcome" v-model="form.desired_outcome" rows="3" class="w-full rounded-lg border-outline-variant"
                                      placeholder="What would resolution look like for you?"></textarea>
                            <p v-if="form.errors.desired_outcome" class="text-rose-600 text-xs mt-1">{{ form.errors.desired_outcome }}</p>
                        </div>

                        <div>
                            <label class="block text-xs text-on-surface-variant mb-1">Attach evidence (optional, max 10 files, 10MB each)</label>
                            <input type="file" multiple @change="onFile" aria-label="Attach evidence"
                                   accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.txt,.mp3,.mp4"
                                   class="text-sm">
                            <p v-if="form.errors.evidence" class="text-rose-600 text-xs mt-1">{{ form.errors.evidence }}</p>
                        </div>
                    </div>
                </details>

                <div class="flex items-center justify-between pt-2">
                    <p class="text-xs text-on-surface-variant/70">
                        By submitting, you confirm the information is true to the best of your knowledge.
                    </p>
                    <PrimaryButton type="submit" :disabled="form.processing">
                        Submit disclosure
                    </PrimaryButton>
                </div>
            </form>
        </main>
    </div>
</template>

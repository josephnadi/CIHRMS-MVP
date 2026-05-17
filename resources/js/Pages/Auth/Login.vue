<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

// Active SSO identity providers (shared via HandleInertiaRequests).
const page = usePage();
const ssoProviders = computed(() => page.props.ssoProviders ?? []);

const form = useForm({
    name:     '',
    staff_id: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('staff_id'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Sign In · CIHRM Ghana" />

        <!-- SSO sign-in buttons (Phase 4 / WS19). Visible only when at least
             one identity_provider row is active. Routes through the normal
             login flow if no SSO providers are configured. -->
        <div v-if="ssoProviders.length > 0" class="sso-stack">
            <a v-for="p in ssoProviders" :key="p.slug"
               :href="route('sso.initiate', { slug: p.slug })"
               class="sso-btn">
                <span class="material-symbols-outlined sso-btn-icon">{{ p.button_icon }}</span>
                <span>{{ p.button_label }}</span>
            </a>
            <div class="sso-divider"><span>or sign in with your staff number</span></div>
        </div>

        <header class="auth-folio">
            <span class="auth-folio-num">01</span>
            <span class="auth-folio-rule"></span>
            <span class="auth-folio-label">Sign in</span>
        </header>

        <h2 class="auth-title">
            Welcome <em>back.</em>
        </h2>
        <p class="auth-deck">
            Name and staff number, please.
        </p>

        <!-- Status flash -->
        <div v-if="status" class="auth-status">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6">
                <circle cx="12" cy="12" r="9"/>
                <path d="m8 12 3 3 5-6"/>
            </svg>
            <span>{{ status }}</span>
        </div>

        <form @submit.prevent="submit" class="auth-form" novalidate>

            <!-- Full name -->
            <div class="field">
                <label for="name" class="field-label">
                    <span>Full name</span>
                </label>
                <input id="name" type="text"
                       v-model="form.name"
                       autocomplete="name" autofocus required
                       placeholder="Kwame Mensah"
                       class="field-input" />
                <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
            </div>

            <!-- Staff ID — gets the mono treatment -->
            <div class="field">
                <label for="staff_id" class="field-label">
                    <span>Staff number</span>
                </label>
                <div class="field-mono-wrap">
                    <span class="field-mono-prefix" aria-hidden="true">/</span>
                    <input id="staff_id" type="text"
                           v-model="form.staff_id"
                           required
                           placeholder="GH-HR-001"
                           class="field-input field-input-mono" />
                </div>
                <p v-if="form.errors.staff_id" class="field-error">{{ form.errors.staff_id }}</p>
            </div>

            <!-- Remember + recover -->
            <div class="field-row">
                <label class="check">
                    <input type="checkbox" v-model="form.remember" aria-label="Remember me on this device" />
                    <span class="check-box" aria-hidden="true">
                        <svg viewBox="0 0 16 16" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m3 8 3 3 7-8"/>
                        </svg>
                    </span>
                    <span class="check-label">Keep me signed in</span>
                </label>
                <Link v-if="canResetPassword" :href="route('password.request')" class="recover-link">
                    Recover credentials
                </Link>
            </div>

            <!-- Submit -->
            <button type="submit"
                    :disabled="form.processing"
                    class="auth-submit"
                    :class="{ 'is-busy': form.processing }">
                <span class="auth-submit-shimmer" aria-hidden="true"></span>
                <span class="auth-submit-label">
                    <template v-if="form.processing">Signing in</template>
                    <template v-else>Sign in</template>
                </span>
                <span class="auth-submit-arrow" aria-hidden="true">
                    <svg v-if="!form.processing" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M5 12h14m-5-5 5 5-5 5"/>
                    </svg>
                    <svg v-else class="spin" viewBox="0 0 24 24" width="16" height="16" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5" opacity="0.2"/>
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </span>
            </button>

            <!-- Sister-link to register -->
            <p class="sister-link">
                New here?
                <Link :href="route('register')">Request access.</Link>
            </p>
        </form>
    </GuestLayout>
</template>

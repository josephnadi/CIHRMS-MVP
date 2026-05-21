<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Enrol · CIHRM Ghana" />

        <header class="auth-folio">
            <span class="auth-folio-num">02</span>
            <span class="auth-folio-rule"></span>
            <span class="auth-folio-label">Request access</span>
        </header>

        <h2 class="auth-title">
            Request <em>access.</em>
        </h2>
        <p class="auth-deck">
            HR will issue your staff number after review.
        </p>

        <form @submit.prevent="submit" class="auth-form" novalidate>

            <!-- Full name -->
            <div class="field">
                <label for="name" class="field-label">
                    <span>Full name</span>
                </label>
                <input id="name" type="text"
                       v-model="form.name"
                       autocomplete="name" autofocus required
                       placeholder="Akua Asante"
                       class="field-input" />
                <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
            </div>

            <!-- Email -->
            <div class="field">
                <label for="email" class="field-label">
                    <span>Institutional email</span>
                </label>
                <input id="email" type="email"
                       v-model="form.email"
                       autocomplete="username" required
                       placeholder="a.asante@cihrm.gov.gh"
                       class="field-input" />
                <p v-if="form.errors.email" class="field-error">{{ form.errors.email }}</p>
            </div>

            <!-- Password -->
            <div class="field">
                <label for="password" class="field-label">
                    <span>Password</span>
                    <span class="field-hint">12+ characters</span>
                </label>
                <input id="password" type="password"
                       v-model="form.password"
                       autocomplete="new-password" required
                       placeholder="••••••••••••"
                       class="field-input" />
                <p v-if="form.errors.password" class="field-error">{{ form.errors.password }}</p>
            </div>

            <!-- Confirm -->
            <div class="field">
                <label for="password_confirmation" class="field-label">
                    <span>Confirm password</span>
                </label>
                <input id="password_confirmation" type="password"
                       v-model="form.password_confirmation"
                       autocomplete="new-password" required
                       placeholder="••••••••••••"
                       class="field-input" />
                <p v-if="form.errors.password_confirmation" class="field-error">{{ form.errors.password_confirmation }}</p>
            </div>

            <p class="auth-consent">
                I acknowledge the <a href="#">Charter</a> and <a href="#">privacy notice</a>.
            </p>

            <!-- Submit -->
            <button type="submit"
                    :disabled="form.processing"
                    class="auth-submit"
                    :class="{ 'is-busy': form.processing }">
                <span class="auth-submit-shimmer" aria-hidden="true"></span>
                <span class="auth-submit-label">
                    <template v-if="form.processing">Submitting</template>
                    <template v-else>Submit request</template>
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

            <p class="sister-link">
                Already have access?
                <Link :href="route('login')">Sign in.</Link>
            </p>
        </form>
    </GuestLayout>
</template>

<style scoped>
/* Page-specific overrides only — the rest lives in app.css */
.auth-consent {
    margin: 0.4rem 0 0;
    font-family: 'Open Sans', serif;
    font-style: italic;
    font-size: 12.5px;
    line-height: 1.55;
    color: #5a6b80;
    max-width: 40ch;
}
.auth-consent a {
    color: #0d1452;
    text-decoration: none;
    border-bottom: 1px solid rgba(13, 20, 82,0.3);
    transition: color 0.18s ease, border-color 0.18s ease;
}
.auth-consent a:hover { color: #1a237e; border-bottom-color: #1a237e; }
</style>

import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Lightweight i18n bridge for Inertia + Laravel translations.
 *
 * Translations are shared by HandleInertiaRequests under `props.i18n.lines`
 * (keyed by file: `common`, `payroll`, `leave`). Use as:
 *
 *   import { useI18n } from '@/composables/useI18n';
 *   const { t } = useI18n();
 *   t('common.welcome');
 *   t('payroll.sms_payslip_ready', { period: '2026-05', net: '4,300.00' });
 *
 * The placeholder syntax matches Laravel's: `:name` is replaced by the value
 * of `name` in the second argument. Unknown keys return the key itself so
 * missing strings are visible during development.
 */
export function useI18n() {
    const page = usePage();

    const lines = computed(() => page.props.i18n?.lines ?? {});
    const locale = computed(() => page.props.i18n?.locale ?? 'en');

    /**
     * Resolve a key like `common.welcome` and interpolate :placeholders.
     */
    function t(key, replacements = {}) {
        if (!key || typeof key !== 'string') return key ?? '';

        const dotIndex = key.indexOf('.');
        if (dotIndex < 0) return key;

        const file = key.slice(0, dotIndex);
        const stringKey = key.slice(dotIndex + 1);
        const bag = lines.value[file] ?? {};
        let template = bag[stringKey];
        if (template === undefined || template === null) return key;

        template = String(template);
        for (const [k, v] of Object.entries(replacements)) {
            template = template.split(`:${k}`).join(String(v));
        }
        return template;
    }

    /**
     * Pluralisation shim — picks `singular` / `plural` based on count.
     * For Akan, Ga, and Ewe we use the same English rule (singular if count === 1)
     * since these languages don't grammatically pluralise the way English does.
     */
    function tn(count, singular, plural, replacements = {}) {
        return t(count === 1 ? singular : plural, { ...replacements, n: count });
    }

    return { t, tn, locale, lines };
}

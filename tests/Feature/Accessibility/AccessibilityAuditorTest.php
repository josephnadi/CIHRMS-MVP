<?php

use App\Services\Accessibility\AccessibilityAuditor;

it('flags <img> without an alt attribute (WCAG 1.1.1)', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue', <<<'VUE'
        <template>
            <img src="/logo.png">
        </template>
        VUE);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['rule'])->toBe('img-missing-alt')
        ->and($findings[0]['wcag'])->toBe('1.1.1');
});

it('accepts <img> with empty alt for decorative images', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue', '<img src="/divider.svg" alt="">');
    expect($findings)->toBeEmpty();
});

it('accepts v-bind:alt as dynamic alt text', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue', '<img :src="u" :alt="employee.name">');
    expect($findings)->toBeEmpty();
});

it('flags icon-only <button> without aria-label (WCAG 4.1.2)', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue', <<<'VUE'
        <button class="icon-btn"><svg /></button>
        VUE);

    expect(collect($findings)->pluck('rule')->all())
        ->toContain('icon-button-missing-aria-label');
});

it('accepts icon-only <button> with aria-label', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue',
        '<button aria-label="Close dialog"><svg /></button>');
    expect(collect($findings)->pluck('rule')->all())
        ->not->toContain('icon-button-missing-aria-label');
});

it('flags <input> without label or aria-label (WCAG 3.3.2)', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue', '<input type="text" name="q">');
    expect(collect($findings)->pluck('rule')->all())->toContain('input-missing-label');
});

it('accepts <input id="x"> with matching <label for="x"> in the same file', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue', <<<'VUE'
        <label for="email">Email</label>
        <input id="email" type="email">
        VUE);
    expect(collect($findings)->pluck('rule')->all())->not->toContain('input-missing-label');
});

it('skips type="hidden" inputs', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue', '<input type="hidden" name="_token" value="x">');
    expect($findings)->toBeEmpty();
});

it('flags empty <a> tags (WCAG 2.4.4)', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue', '<a href="/x"></a>');
    expect(collect($findings)->pluck('rule')->all())->toContain('anchor-empty-text');
});

it('flags positive tabindex (WCAG 2.4.3)', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue', '<div tabindex="2">x</div>');
    expect(collect($findings)->pluck('rule')->all())->toContain('positive-tabindex');
});

it('accepts tabindex="-1" and tabindex="0"', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Test.vue',
        '<main tabindex="-1">m</main><div tabindex="0">d</div>');
    expect(collect($findings)->pluck('rule')->all())->not->toContain('positive-tabindex');
});

it('reports correct line numbers across a multi-line file', function () {
    $findings = (new AccessibilityAuditor())->auditSource('Multi.vue', <<<'VUE'
        <template>
            <div>
                <img src="/a.png">
            </div>
        </template>
        VUE);
    expect($findings[0]['line'])->toBe(3);
});

it('audits the live project tree without crashing', function () {
    $findings = (new AccessibilityAuditor())->audit(base_path());
    expect($findings)->toBeArray();
});

it('artisan a11y:audit runs against the project tree', function () {
    $exit = Illuminate\Support\Facades\Artisan::call('a11y:audit', ['--json' => true]);
    expect($exit)->toBeIn([0, 1]); // success or a known finding-failure
});

<?php

declare(strict_types=1);

// The SlidePanel component takes a required `open` prop and emits `close` — it
// has NO v-model (modelValue) support. Using `<SlidePanel v-model="...">` leaves
// `open` unset, so the panel silently never opens (e.g. the "Create payroll run"
// button appeared dead). This guards every page against that wiring mistake.

it('no Vue page wires SlidePanel with v-model (it has no modelValue support)', function () {
    $pages = base_path('resources/js');

    $offenders = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pages, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (preg_match('/<SlidePanel\s+v-model/', $contents)) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([], 'SlidePanel must use :open + @close, not v-model: ' . implode(', ', $offenders));
});

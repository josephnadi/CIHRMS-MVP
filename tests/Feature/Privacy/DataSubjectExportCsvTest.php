<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Privacy\DataSubjectExportBuilder;

/**
 * Asserts that every JSON file in a DSR export has a matching CSV file
 * inside the ZIP, as required by Act 843 §17(2). CSV is the lowest-common
 * format every data subject can open in Excel/Numbers/LibreOffice; without
 * it we'd be shipping JSON to people who don't know what JSON is.
 */

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Kofi Asante', 'email' => 'kofi@example.com']);
    Employee::factory()->create([
        'department_id' => Department::factory()->create()->id,
        'user_id'       => $this->user->id,
    ]);
});

it('emits a CSV alongside every JSON in the export ZIP', function () {
    $builder = new DataSubjectExportBuilder();
    $bundle  = $builder->buildFor($this->user->fresh(), 'TEST-DSR-001');

    expect(file_exists($bundle['path']))->toBeTrue();
    expect($bundle['sha256'])->not->toBeEmpty();

    $zip = new \ZipArchive();
    $zip->open($bundle['path']);

    expect($zip->numFiles)->toBeGreaterThan(0);

    $jsonFiles = [];
    $csvFiles  = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_ends_with($name, '.json')) $jsonFiles[] = substr($name, 0, -5);
        if (str_ends_with($name, '.csv'))  $csvFiles[]  = substr($name, 0, -4);
    }
    $zip->close();

    sort($jsonFiles);
    sort($csvFiles);

    expect($jsonFiles)->not->toBeEmpty();
    expect($csvFiles)->toBe($jsonFiles); // every JSON has a CSV twin

    @unlink($bundle['path']);
});

it('flattens a list-of-records JSON into a header + N-row CSV', function () {
    $builder = new DataSubjectExportBuilder();
    $bundle  = $builder->buildFor($this->user->fresh(), 'TEST-DSR-002');

    $zip = new \ZipArchive();
    $zip->open($bundle['path']);

    // Locate the account-CSV by suffix match — path separator inside the
    // zip varies between Windows and Linux, so we don't pin the exact name.
    $csv = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_ends_with($name, '00-account.csv')) {
            $csv = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();

    expect($csv)->toBeString();
    expect($csv)->toContain('field,value');
    expect($csv)->toContain('Kofi Asante');
    expect($csv)->toContain('email');
    expect($csv)->toContain('kofi@example.com');

    @unlink($bundle['path']);
});

it('escapes CSV-hostile characters (commas, quotes, newlines) per RFC 4180', function () {
    // Bake in a name that exercises every CSV special character.
    $this->user->update(['name' => "Asante, \"K\"\nNewline"]);

    $builder = new DataSubjectExportBuilder();
    $bundle  = $builder->buildFor($this->user->fresh(), 'TEST-DSR-003');

    $zip = new \ZipArchive();
    $zip->open($bundle['path']);
    $csv = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_ends_with($name, '00-account.csv')) {
            $csv = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();

    expect($csv)->toBeString();
    // PHP's fputcsv quotes any field containing commas/quotes/newlines;
    // embedded quotes get doubled. The wrapping quote pair must be present.
    expect($csv)->toContain('"Asante, ""K""');
    @unlink($bundle['path']);
});

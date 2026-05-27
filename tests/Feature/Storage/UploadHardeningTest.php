<?php

use App\Http\Controllers\DocumentController;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Rules\RealImageContent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

it('rejects a file with PNG extension but JPEG bytes (M10/L3)', function () {
    // Real JPEG bytes saved with a .png extension
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAr/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AL+P/9k=');
    $path = tempnam(sys_get_temp_dir(), 'spoof') . '.png';
    file_put_contents($path, $jpeg);
    $file = new UploadedFile($path, 'spoof.png', 'image/png', null, true);

    $v = Validator::make(['file' => $file], ['file' => [new RealImageContent(['png'])]]);
    expect($v->fails())->toBeTrue();

    @unlink($path);
});

it('accepts a real PNG (M10/L3)', function () {
    $png  = UploadedFile::fake()->image('real.png', 10, 10);
    $v = Validator::make(['file' => $png], ['file' => [new RealImageContent(['png'])]]);
    expect($v->passes())->toBeTrue();
});

it('removes avatar + documents from disk when an employee is force-deleted (L4)', function () {
    Storage::fake('local');
    Storage::disk('local')->put('avatars/u.png', 'fake');
    Storage::disk('local')->put('employee-documents/d.pdf', 'fake');

    $emp = Employee::factory()->create(['avatar_path' => 'avatars/u.png']);
    EmployeeDocument::create([
        'employee_id' => $emp->id,
        'title'       => 'CV',
        'file_path'   => 'employee-documents/d.pdf',
        'mime_type'   => 'application/pdf',
    ]);

    $emp->forceDelete();

    Storage::disk('local')->assertMissing('avatars/u.png');
    Storage::disk('local')->assertMissing('employee-documents/d.pdf');
});

it('sanitises malicious download filenames (L2)', function () {
    $ctrl = app(DocumentController::class);
    $m = new ReflectionMethod($ctrl, 'sanitiseDownloadName');
    $m->setAccessible(true);

    expect($m->invoke($ctrl, "evil\r\nname.pdf"))->not->toContain("\r");
    expect($m->invoke($ctrl, "../../etc/passwd"))->not->toContain('/');
    expect($m->invoke($ctrl, null))->toBe('document');
});

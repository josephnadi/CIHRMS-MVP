<?php

use App\Models\Document;
use App\Models\LetterheadTemplate;
use App\Models\User;

it('document belongs to a letterhead template', function () {
    $owner = User::factory()->create();
    $tpl   = LetterheadTemplate::factory()->create(['created_by' => $owner->id]);
    $doc   = Document::factory()->for($owner, 'owner')->create(['letterhead_id' => $tpl->id]);
    expect($doc->letterhead)->not->toBeNull();
    expect($doc->letterhead->id)->toBe($tpl->id);
});

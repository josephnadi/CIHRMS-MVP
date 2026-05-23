<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkillCatalogItem extends Model
{
    protected $table = 'skill_catalog';

    protected $fillable = ['name', 'category', 'description'];

    public const CATEGORIES = [
        'technical',
        'leadership',
        'compliance',
        'soft_skills',
        'domain',
    ];
}

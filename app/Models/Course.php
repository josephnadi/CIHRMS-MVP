<?php

namespace App\Models;

use App\Enums\CourseCategory;
use App\Enums\CourseFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Course extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'slug', 'description', 'category', 'format',
        'provider', 'cover_image', 'duration_minutes',
        'price', 'currency', 'skill_tags',
        'is_published', 'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'category'         => CourseCategory::class,
            'format'           => CourseFormat::class,
            'skill_tags'       => 'array',
            'duration_minutes' => 'integer',
            'price'            => 'decimal:2',
            'is_published'     => 'boolean',
            'published_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Course $course) {
            if (! $course->slug && $course->title) {
                $course->slug = Str::slug($course->title).'-'.substr(bin2hex(random_bytes(3)), 0, 6);
            }
        });
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(Certification::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function durationLabel(): Attribute
    {
        return Attribute::get(function () {
            $m = (int) $this->duration_minutes;
            if ($m <= 0) return null;
            if ($m < 60) return "{$m}m";
            $h = intdiv($m, 60); $rest = $m % 60;
            return $rest ? "{$h}h {$rest}m" : "{$h}h";
        });
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true);
    }

    public function scopeCategory(Builder $q, string|CourseCategory $category): Builder
    {
        return $q->where('category', $category instanceof CourseCategory ? $category->value : $category);
    }
}

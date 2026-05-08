<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'instructor',
        'tagline',
        'description',
        'cover_path',
        'estimated_lessons',
        'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('section_order')->orderBy('position');
    }

    public function availableLessons(): HasMany
    {
        return $this->lessons()->where('is_available', true);
    }

    protected function progressPercent(): Attribute
    {
        return Attribute::get(function (): float {
            if ($this->estimated_lessons === 0) {
                return 0;
            }

            return round(($this->lessons->where('is_available', true)->count() / $this->estimated_lessons) * 100, 1);
        });
    }

    protected function firstAvailableLesson(): Attribute
    {
        return Attribute::get(fn () => $this->lessons->firstWhere('is_available', true));
    }
}

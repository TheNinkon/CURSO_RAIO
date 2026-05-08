<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $fillable = [
        'course_id',
        'title',
        'slug',
        'section_title',
        'section_order',
        'position',
        'summary',
        'notes_markdown',
        'resource_links',
        'video_path',
        'poster_path',
        'subtitle_es_path',
        'subtitle_en_path',
        'duration_seconds',
        'is_available',
    ];

    protected $casts = [
        'resource_links' => 'array',
        'is_available' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    protected function formattedDuration(): Attribute
    {
        return Attribute::get(function (): string {
            if (! $this->duration_seconds) {
                return '--:--';
            }

            $hours = intdiv($this->duration_seconds, 3600);
            $minutes = intdiv($this->duration_seconds % 3600, 60);
            $seconds = $this->duration_seconds % 60;

            if ($hours > 0) {
                return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
            }

            return sprintf('%02d:%02d', $minutes, $seconds);
        });
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;

class LessonController extends Controller
{
    public function show(Course $course, Lesson $lesson)
    {
        abort_unless($lesson->course_id === $course->id, 404);

        $course->load('lessons');

        $groupedLessons = $course->lessons
            ->groupBy('section_title');

        return view('courses.show', [
            'course' => $course,
            'lesson' => $lesson,
            'groupedLessons' => $groupedLessons,
        ]);
    }
}

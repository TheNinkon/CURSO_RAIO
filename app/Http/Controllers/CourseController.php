<?php

namespace App\Http\Controllers;

use App\Models\Course;

class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::query()
            ->with('lessons')
            ->where('is_published', true)
            ->get();

        return view('courses.index', [
            'courses' => $courses,
        ]);
    }

    public function show(Course $course)
    {
        $course->load('lessons');

        $lesson = $course->first_available_lesson;

        abort_unless($lesson !== null, 404);

        return redirect()->route('courses.lessons.show', [$course, $lesson]);
    }
}

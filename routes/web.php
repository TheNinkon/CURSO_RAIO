<?php

use App\Http\Controllers\CourseController;
use App\Http\Controllers\LibraryImportController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CourseController::class, 'index'])->name('courses.index');
Route::get('/imports', [LibraryImportController::class, 'index'])->name('imports.index');
Route::post('/imports', [LibraryImportController::class, 'store'])->name('imports.store');
Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');
Route::get('/courses/{course}/lessons/{lesson}', [LessonController::class, 'show'])->name('courses.lessons.show');
Route::match(['get', 'head'], '/media/lessons/{lesson}/video', [MediaController::class, 'video'])->name('lessons.video');

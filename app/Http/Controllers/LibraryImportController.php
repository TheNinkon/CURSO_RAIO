<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Services\CourseArchiveImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LibraryImportController extends Controller
{
    public function __construct(
        private readonly CourseArchiveImportService $importService,
    ) {
    }

    public function index(): View
    {
        $defaults = $this->importService->discoverDefaultSources();

        return view('imports.index', [
            'defaults' => $defaults,
            'courses' => Course::query()->with('lessons')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'video_sources' => ['required', 'string'],
            'notion_source' => ['required', 'string'],
            'course_slug' => ['nullable', 'string', 'max:120'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        $videoSources = collect(preg_split("/\r\n|\n|\r/", $data['video_sources']) ?: [])
            ->map(fn (string $path) => trim($path))
            ->filter()
            ->values()
            ->all();

        try {
            $result = $this->importService->import(
                $videoSources,
                trim($data['notion_source']),
                [
                    'course_slug' => trim($data['course_slug'] ?: 'curso-de-ingles-raio'),
                    'replace_existing' => $request->boolean('replace_existing', true),
                ],
            );
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('import_error', $exception->getMessage());
        }

        return redirect()
            ->route('courses.index')
            ->with('import_success', sprintf(
                'Importación completada: %d lecciones, %d videos conectados.',
                $result['imported_lessons'],
                $result['videos_matched'],
            ));
    }
}

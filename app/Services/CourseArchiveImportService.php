<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class CourseArchiveImportService
{
    public function import(array $videoSources, string $notionSource, array $options = []): array
    {
        set_time_limit(0);

        $courseSlug = $options['course_slug'] ?? 'curso-de-ingles-raio';
        $replaceExisting = (bool) ($options['replace_existing'] ?? true);
        $workspace = storage_path('app/course-imports/' . $courseSlug);
        $publicMediaRoot = public_path('media/imported/' . $courseSlug);
        $managedMediaRoot = storage_path('app/course-media/' . $courseSlug);

        if ($replaceExisting) {
            File::deleteDirectory($workspace);
            File::deleteDirectory($publicMediaRoot);
            File::deleteDirectory($managedMediaRoot);
        }

        File::ensureDirectoryExists($workspace);
        File::ensureDirectoryExists($publicMediaRoot);

        $preparedVideoRoots = $this->prepareVideoSources($videoSources, $workspace);
        $notionRoot = $this->prepareNotionSource($notionSource, $workspace);

        $lessonsFromNotion = $this->loadLessonsFromNotion($notionRoot, $publicMediaRoot, $courseSlug);
        $videoIndex = $this->buildVideoIndex($preparedVideoRoots);

        $course = DB::transaction(function () use ($courseSlug, $lessonsFromNotion, $videoIndex, $publicMediaRoot) {
            $courseTitle = $this->detectCourseTitle($lessonsFromNotion);

            $course = Course::query()->updateOrCreate(
                ['slug' => $courseSlug],
                [
                    'title' => $courseTitle,
                    'instructor' => 'William Trujillo',
                    'tagline' => 'Biblioteca local organizada desde ZIPs y exportaciones de Notion.',
                    'description' => 'Archivo personal del curso con videos, subtítulos, apuntes y recursos servidos desde el propio equipo.',
                    'estimated_lessons' => $lessonsFromNotion->count(),
                    'is_published' => true,
                ],
            );

            $course->lessons()->delete();

            $createdLessons = collect();

            foreach ($lessonsFromNotion as $lessonData) {
                $matchedVideo = $lessonData['expects_video']
                    ? $this->matchVideoForLesson($lessonData, $videoIndex['videos'])
                    : null;
                $subtitlePaths = $this->copyLessonSubtitles($lessonData, $matchedVideo, $videoIndex['subtitles'], $courseSlug);
                $managedVideoPath = $this->prepareManagedVideo($matchedVideo, $courseSlug, $lessonData['slug']);
                $posterPath = $this->preparePoster($managedVideoPath, $publicMediaRoot, $courseSlug, $lessonData['slug'], $lessonData['inline_poster_path'] ?? null);
                $durationSeconds = $this->probeDuration($managedVideoPath);

                $resourceLinks = collect($lessonData['resource_links'])
                    ->merge($lessonData['asset_links'])
                    ->values()
                    ->all();

                $createdLessons->push($course->lessons()->create([
                    'title' => $lessonData['title'],
                    'slug' => $lessonData['slug'],
                    'section_title' => $lessonData['section_title'],
                    'section_order' => $lessonData['section_order'],
                    'position' => $lessonData['position'],
                    'summary' => $lessonData['summary'],
                    'notes_markdown' => $lessonData['notes_markdown'],
                    'resource_links' => $resourceLinks,
                    'video_path' => $managedVideoPath,
                    'poster_path' => $posterPath,
                    'subtitle_es_path' => $subtitlePaths['es'],
                    'subtitle_en_path' => $subtitlePaths['en'],
                    'duration_seconds' => $durationSeconds,
                    'is_available' => (bool) ($matchedVideo || $lessonData['notes_markdown'] || ! empty($resourceLinks)),
                ]));
            }

            $coverPath = $createdLessons->firstWhere('poster_path')?->poster_path
                ?? $createdLessons->firstWhere('inline_poster_path')?->inline_poster_path;

            $course->forceFill([
                'cover_path' => $coverPath,
                'estimated_lessons' => $createdLessons->count(),
            ])->save();

            return $course->fresh('lessons');
        });

        return [
            'course' => $course,
            'video_roots' => $preparedVideoRoots,
            'notion_root' => $notionRoot,
            'imported_lessons' => $course->lessons->count(),
            'videos_matched' => $course->lessons->filter(fn ($lesson) => filled($lesson->video_path))->count(),
        ];
    }

    public function discoverDefaultSources(): array
    {
        $courseDirectory = base_path('curso');

        if (! File::isDirectory($courseDirectory)) {
            return [
                'video_sources' => [],
                'notion_source' => null,
            ];
        }

        $zipFiles = collect(File::files($courseDirectory))
            ->map(fn (\SplFileInfo $file) => $file->getPathname())
            ->filter(fn (string $path) => Str::endsWith(Str::lower($path), '.zip'));

        return [
            'video_sources' => $zipFiles
                ->reject(fn (string $path) => str_contains(Str::lower(basename($path)), 'exportblock'))
                ->values()
                ->all(),
            'notion_source' => $zipFiles
                ->first(fn (string $path) => str_contains(Str::lower(basename($path)), 'exportblock')),
        ];
    }

    private function prepareVideoSources(array $sources, string $workspace): array
    {
        $resolved = collect($sources)
            ->filter(fn ($path) => filled($path))
            ->map(fn ($path) => $this->resolvePath($path))
            ->values();

        $roots = [];

        foreach ($resolved as $index => $path) {
            if (File::isDirectory($path)) {
                $roots[] = $path;
                continue;
            }

            $destination = $workspace . '/videos/source-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            File::ensureDirectoryExists($destination);
            $this->extractZip($path, $destination, ['mp4', 'vtt']);
            $roots[] = $destination;
        }

        return $roots;
    }

    private function prepareNotionSource(string $source, string $workspace): string
    {
        $resolvedSource = $this->resolvePath($source);

        if (File::isDirectory($resolvedSource)) {
            return $this->findNotionContentRoot($resolvedSource);
        }

        $outerDirectory = $workspace . '/notion/outer';
        File::ensureDirectoryExists($outerDirectory);
        $this->extractZip($resolvedSource, $outerDirectory);

        $nestedZips = collect(File::allFiles($outerDirectory))
            ->filter(fn (\SplFileInfo $file) => Str::endsWith(Str::lower($file->getFilename()), '.zip'))
            ->values();

        if ($nestedZips->isNotEmpty()) {
            $innerDirectory = $workspace . '/notion/content';
            File::ensureDirectoryExists($innerDirectory);
            $this->extractZip($nestedZips->first()->getPathname(), $innerDirectory);

            return $this->findNotionContentRoot($innerDirectory);
        }

        return $this->findNotionContentRoot($outerDirectory);
    }

    private function findNotionContentRoot(string $directory): string
    {
        $csvFile = collect(File::allFiles($directory))
            ->first(fn (\SplFileInfo $file) => str_contains(Str::lower($file->getFilename()), 'section-video') && Str::endsWith(Str::lower($file->getFilename()), '.csv'));

        if (! $csvFile) {
            throw new \RuntimeException('No encontré el CSV de section-video dentro del export de Notion.');
        }

        return dirname($csvFile->getPathname(), 1);
    }

    private function loadLessonsFromNotion(string $notionRoot, string $publicMediaRoot, string $courseSlug): Collection
    {
        $courseRoot = File::exists($notionRoot . '/section-video')
            ? $notionRoot
            : collect(File::directories($notionRoot))
                ->first(fn (string $directory) => File::exists($directory . '/section-video'));

        if (! $courseRoot) {
            throw new \RuntimeException('No encontré la carpeta principal del curso dentro del export de Notion.');
        }

        $sectionVideoDirectory = $courseRoot . '/section-video';
        $csvPath = collect(File::files($courseRoot))
            ->first(fn (\SplFileInfo $file) => str_contains(Str::lower($file->getFilename()), 'section-video') && Str::endsWith(Str::lower($file->getFilename()), '.csv'))
            ?->getPathname();

        if (! $csvPath) {
            throw new \RuntimeException('No encontré el CSV con la lista de lecciones.');
        }

        $markdownIndex = collect(File::files($sectionVideoDirectory))
            ->filter(fn (\SplFileInfo $file) => Str::endsWith(Str::lower($file->getFilename()), '.md'))
            ->mapWithKeys(fn (\SplFileInfo $file) => [$this->normalizeText($file->getFilenameWithoutExtension()) => $file->getPathname()]);

        return collect($this->readCsv($csvPath))
            ->map(function (array $row) use ($markdownIndex, $sectionVideoDirectory, $publicMediaRoot, $courseSlug) {
                $rawTitle = trim($row['Title'] ?? $row[array_key_first($row)] ?? '');
                $rawSectionTitle = trim($row['Categories'] ?? '');

                if ($rawTitle === '' || $this->shouldSkipLesson($rawTitle)) {
                    return null;
                }

                $cleanTitle = $this->cleanLessonTitle($rawTitle);
                $slug = Str::slug($cleanTitle);
                $markdownPath = $this->locateLessonMarkdown($markdownIndex, $rawTitle);
                $lessonMediaDirectory = $publicMediaRoot . '/' . $slug;
                File::ensureDirectoryExists($lessonMediaDirectory);

                [$notesMarkdown, $summary, $resourceLinks, $assetLinks, $inlinePosterPath] = $this->parseLessonMarkdown(
                    $markdownPath,
                    $lessonMediaDirectory,
                    $slug,
                    $courseSlug,
                );

                $originalVideoLink = trim((string) ($row['Link Video'] ?? ''));
                $expectsVideo = ! str_starts_with(Str::lower($originalVideoLink), 'no tiene video');

                if ($originalVideoLink !== '' && ! str_starts_with(Str::lower($originalVideoLink), 'no tiene video')) {
                    $resourceLinks[] = [
                        'label' => 'Video original en Drive',
                        'url' => $originalVideoLink,
                        'description' => 'Enlace original exportado desde Notion.',
                    ];
                }

                return [
                    'title' => $cleanTitle,
                    'slug' => $slug,
                    'section_title' => $this->cleanSectionTitle($rawSectionTitle),
                    'section_order' => $this->resolveSectionOrder($rawSectionTitle),
                    'position' => $this->extractLeadingNumber($rawTitle) ?: 999,
                    'summary' => $summary,
                    'notes_markdown' => $notesMarkdown,
                    'resource_links' => $this->uniqueResources($resourceLinks),
                    'asset_links' => $this->uniqueResources($assetLinks),
                    'inline_poster_path' => $inlinePosterPath,
                    'expects_video' => $expectsVideo,
                    'matching_context' => implode(' ', [
                        $rawTitle,
                        $rawSectionTitle,
                        basename($markdownPath),
                        $sectionVideoDirectory,
                    ]),
                ];
            })
            ->filter()
            ->values();
    }

    private function parseLessonMarkdown(string $markdownPath, string $publicLessonDirectory, string $lessonSlug, string $courseSlug): array
    {
        if (! File::exists($markdownPath)) {
            return [null, null, [], [], null];
        }

        $lines = preg_split("/\r\n|\n|\r/", File::get($markdownPath)) ?: [];
        $contentLines = [];
        $skippingPreface = true;
        $titleSkipped = false;
        $assetLinks = [];
        $resourceLinks = [];
        $inlinePosterPath = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($skippingPreface) {
                if (! $titleSkipped && Str::startsWith($trimmed, '# ')) {
                    $titleSkipped = true;
                    continue;
                }

                if ($trimmed === '' || $this->isMetadataLine($trimmed)) {
                    continue;
                }

                $skippingPreface = false;
            }

            [$rewrittenLine, $lineAssets, $maybePoster] = $this->rewriteMarkdownLocalAssetLine($line, dirname($markdownPath), $publicLessonDirectory, $lessonSlug, $courseSlug);

            if ($maybePoster) {
                $inlinePosterPath = $maybePoster;
            }

            foreach ($lineAssets as $asset) {
                $assetLinks[] = $asset;
            }

            foreach ($this->extractExternalLinks($line) as $resource) {
                $resourceLinks[] = $resource;
            }

            $contentLines[] = $rewrittenLine;
        }

        $notesMarkdown = trim(implode("\n", $contentLines)) ?: null;
        $summary = $this->extractSummary($contentLines);

        return [$notesMarkdown, $summary, $resourceLinks, $assetLinks, $inlinePosterPath];
    }

    private function rewriteMarkdownLocalAssetLine(string $line, string $markdownDirectory, string $publicLessonDirectory, string $lessonSlug, string $courseSlug): array
    {
        $trimmed = trim($line);
        $patterns = [
            '/^!\[([^\]]*)\]\((.+)\)$/u' => true,
            '/^\[([^\]]+)\]\((.+)\)$/u' => false,
        ];

        foreach ($patterns as $pattern => $isImage) {
            if (preg_match($pattern, $trimmed, $matches) !== 1) {
                continue;
            }

            $label = trim($matches[1]) ?: 'Archivo adjunto';
            $target = trim($matches[2]);

            if ($this->isExternalUrl($target)) {
                return [$line, [], null];
            }

            $resolvedAsset = $this->resolveMarkdownAssetPath($markdownDirectory, $target);

            if (! $resolvedAsset || ! File::exists($resolvedAsset)) {
                return [$line, [], null];
            }

            $extension = Str::lower(pathinfo($resolvedAsset, PATHINFO_EXTENSION));
            $publicFilename = Str::slug(pathinfo($resolvedAsset, PATHINFO_FILENAME)) . ($extension ? '.' . $extension : '');
            $publicRelativePath = 'media/imported/' . $courseSlug . '/' . $lessonSlug . '/' . $publicFilename;
            $publicDestination = public_path($publicRelativePath);

            File::ensureDirectoryExists(dirname($publicDestination));
            File::copy($resolvedAsset, $publicDestination);

            $rewritten = $isImage
                ? '![' . $label . '](/' . $publicRelativePath . ')'
                : '[' . $label . '](/' . $publicRelativePath . ')';

            $resource = [
                'label' => $label,
                'url' => '/' . $publicRelativePath,
                'description' => $isImage ? 'Imagen adjunta en la lección.' : 'Archivo adjunto de la lección.',
            ];

            return [$rewritten, [$resource], $isImage ? $publicRelativePath : null];
        }

        return [$line, [], null];
    }

    private function resolveMarkdownAssetPath(string $markdownDirectory, string $target): ?string
    {
        $decoded = rawurldecode($target);
        $decoded = trim($decoded, '"');
        $candidate = $markdownDirectory . '/' . $decoded;

        if (File::exists($candidate)) {
            return $candidate;
        }

        $sanitizedCandidate = $markdownDirectory . '/' . implode('/', collect(explode('/', str_replace('\\', '/', $decoded)))
            ->filter(fn (string $segment) => $segment !== '')
            ->map(fn (string $segment) => $this->sanitizeZipSegment($segment))
            ->all());

        return File::exists($sanitizedCandidate) ? $sanitizedCandidate : null;
    }

    private function buildVideoIndex(array $roots): array
    {
        $files = collect($roots)
            ->flatMap(function (string $root) {
                return collect(File::allFiles($root))
                    ->map(fn (\SplFileInfo $file) => $file->getPathname());
            });

        return [
            'videos' => $files
                ->filter(fn (string $path) => in_array(Str::lower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4'], true))
                ->values()
                ->all(),
            'subtitles' => $files
                ->filter(fn (string $path) => Str::lower(pathinfo($path, PATHINFO_EXTENSION)) === 'vtt')
                ->values()
                ->all(),
        ];
    }

    private function matchVideoForLesson(array $lessonData, array $videoPaths): ?string
    {
        return $this->matchAssetForLesson($lessonData, $videoPaths, 2);
    }

    private function copyLessonSubtitles(array $lessonData, ?string $videoPath, array $subtitleFiles, string $courseSlug): array
    {
        $result = ['es' => null, 'en' => null];
        $lessonSlug = $lessonData['slug'];

        if ($videoPath) {
            foreach (File::files(dirname($videoPath)) as $file) {
                if (Str::lower($file->getExtension()) !== 'vtt') {
                    continue;
                }

                $language = $this->detectSubtitleLanguage($file->getFilename());

                if (! $language || $result[$language]) {
                    continue;
                }

                $result[$language] = $this->copySubtitleFile($file->getPathname(), $courseSlug, $lessonSlug, $language);
            }
        }

        foreach (['es', 'en'] as $language) {
            if ($result[$language]) {
                continue;
            }

            $matchedSubtitle = $this->matchAssetForLesson(
                $lessonData,
                array_values(array_filter($subtitleFiles, fn (string $path) => $this->detectSubtitleLanguage(basename($path)) === $language)),
                1,
            );

            if (! $matchedSubtitle) {
                continue;
            }

            $result[$language] = $this->copySubtitleFile($matchedSubtitle, $courseSlug, $lessonSlug, $language);
        }

        return $result;
    }

    private function copySubtitleFile(string $sourcePath, string $courseSlug, string $lessonSlug, string $language): string
    {
        $relativePath = 'media/imported/' . $courseSlug . '/' . $lessonSlug . '/subtitle-' . $language . '.vtt';
        $destination = public_path($relativePath);

        File::ensureDirectoryExists(dirname($destination));
        File::copy($sourcePath, $destination);

        return $relativePath;
    }

    private function prepareManagedVideo(?string $videoPath, string $courseSlug, string $lessonSlug): ?string
    {
        if (! $videoPath || ! is_file($videoPath)) {
            return null;
        }

        $destination = storage_path('app/course-media/' . $courseSlug . '/' . $lessonSlug . '.mp4');
        File::ensureDirectoryExists(dirname($destination));

        if ($this->shouldRemuxToMp4($videoPath)) {
            return $this->remuxVideoToMp4($videoPath, $destination) ? $destination : null;
        }

        if (! File::exists($destination) && ! @link($videoPath, $destination)) {
            File::copy($videoPath, $destination);
        }

        return $destination;
    }

    private function preparePoster(?string $videoPath, string $publicMediaRoot, string $courseSlug, string $lessonSlug, ?string $inlinePosterPath): ?string
    {
        if ($inlinePosterPath) {
            return $inlinePosterPath;
        }

        if (! $videoPath || ! is_file($videoPath)) {
            return null;
        }

        $relativePath = 'media/imported/' . $courseSlug . '/' . $lessonSlug . '/poster.jpg';
        $destination = public_path($relativePath);

        if (File::exists($destination)) {
            return $relativePath;
        }

        File::ensureDirectoryExists(dirname($destination));

        $ffmpeg = trim((string) shell_exec('command -v ffmpeg'));

        if ($ffmpeg === '') {
            return null;
        }

        $process = new Process([$ffmpeg, '-y', '-ss', '00:00:03', '-i', $videoPath, '-frames:v', '1', $destination]);
        $process->setTimeout(null);
        $process->run();

        return $process->isSuccessful() && File::exists($destination) ? $relativePath : null;
    }

    private function probeDuration(?string $videoPath): ?int
    {
        if (! $videoPath || ! is_file($videoPath)) {
            return null;
        }

        $ffprobe = trim((string) shell_exec('command -v ffprobe'));

        if ($ffprobe === '') {
            return null;
        }

        $process = new Process([
            $ffprobe,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $duration = trim($process->getOutput());

        return is_numeric($duration) ? (int) round((float) $duration) : null;
    }

    private function shouldRemuxToMp4(string $videoPath): bool
    {
        $formatName = $this->probeFormatName($videoPath);

        if (! $formatName) {
            return false;
        }

        return ! collect(explode(',', $formatName))
            ->contains(fn (string $format) => in_array(trim($format), ['mp4', 'mov', 'm4a', '3gp', '3g2', 'mj2'], true));
    }

    private function probeFormatName(string $videoPath): ?string
    {
        $ffprobe = trim((string) shell_exec('command -v ffprobe'));

        if ($ffprobe === '') {
            return null;
        }

        $process = new Process([
            $ffprobe,
            '-v',
            'error',
            '-show_entries',
            'format=format_name',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $formatName = trim($process->getOutput());

        return $formatName !== '' ? $formatName : null;
    }

    private function remuxVideoToMp4(string $sourcePath, string $destinationPath): bool
    {
        $ffmpeg = trim((string) shell_exec('command -v ffmpeg'));

        if ($ffmpeg === '') {
            return false;
        }

        $temporaryPath = $destinationPath . '.tmp';

        if (File::exists($temporaryPath)) {
            File::delete($temporaryPath);
        }

        $process = new Process([
            $ffmpeg,
            '-y',
            '-i',
            $sourcePath,
            '-c',
            'copy',
            '-movflags',
            '+faststart',
            '-bsf:a',
            'aac_adtstoasc',
            $temporaryPath,
        ]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful() || ! File::exists($temporaryPath)) {
            File::delete($temporaryPath);

            return false;
        }

        if (File::exists($destinationPath)) {
            File::delete($destinationPath);
        }

        File::move($temporaryPath, $destinationPath);

        return true;
    }

    private function detectSubtitleLanguage(string $filename): ?string
    {
        $normalized = $this->normalizeText($filename);

        if (str_contains($normalized, 'spanish') || preg_match('/(^| )es( |$)/', $normalized) === 1) {
            return 'es';
        }

        if (str_contains($normalized, 'english') || preg_match('/(^| )en( |$)/', $normalized) === 1) {
            return 'en';
        }

        return null;
    }

    private function matchAssetForLesson(array $lessonData, array $paths, int $minimumScore = 2): ?string
    {
        $position = $lessonData['position'];
        $titleNormalized = $this->normalizeText($lessonData['title']);
        $targetTokens = $this->tokenize($lessonData['title'] . ' ' . $lessonData['section_title'] . ' ' . ($lessonData['matching_context'] ?? ''));
        $bestCandidate = null;
        $bestScore = 0;

        foreach ($paths as $path) {
            $normalizedPath = $this->normalizeText($path);
            $candidatePosition = $this->extractPositionFromPath($path);

            if ($candidatePosition && $position && $candidatePosition !== $position) {
                continue;
            }

            $candidateTokens = $this->tokenize($path);
            $score = count(array_intersect($targetTokens, $candidateTokens));

            if ($candidatePosition && $position && $candidatePosition === $position) {
                $score += 2;
            }

            if ($position && str_contains($normalizedPath, 'introduccion') && str_contains($titleNormalized, 'introduccion')) {
                $score += 8;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $path;
            }
        }

        return $bestScore >= $minimumScore ? $bestCandidate : null;
    }

    private function extractPositionFromPath(string $path): ?int
    {
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $path))));
        $matches = [];

        foreach ($segments as $segment) {
            $number = $this->extractLeadingNumber($segment);

            if ($number !== null) {
                $matches[] = $number;
            }
        }

        return $matches === [] ? null : max($matches);
    }

    private function locateLessonMarkdown(Collection $markdownIndex, string $rawTitle): string
    {
        $normalizedTitle = $this->normalizeText($rawTitle);

        $directMatch = $markdownIndex->get($normalizedTitle);

        if ($directMatch) {
            return $directMatch;
        }

        $cleanTitle = $this->cleanLessonTitle($rawTitle);
        $normalizedCleanTitle = $this->normalizeText($cleanTitle);

        $fallback = $markdownIndex->first(function (string $path, string $key) use ($normalizedCleanTitle) {
            return str_contains($key, $normalizedCleanTitle) || str_contains($normalizedCleanTitle, $key);
        });

        if ($fallback) {
            return $fallback;
        }

        throw new \RuntimeException('No encontré el markdown para la lección: ' . $rawTitle);
    }

    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('No pude abrir el CSV: ' . $path);
        }

        $headers = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === []) {
                $headers = array_map([$this, 'normalizeHeader'], $row);
                continue;
            }

            if ($row === [null] || $row === false) {
                continue;
            }

            $rows[] = array_combine($headers, $row) ?: [];
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        return trim(ltrim($header, "\xEF\xBB\xBF"));
    }

    private function shouldSkipLesson(string $title): bool
    {
        $normalized = $this->normalizeText($title);

        return str_contains($normalized, 'pagina guia') || str_contains($normalized, 'skip');
    }

    private function cleanLessonTitle(string $title): string
    {
        $title = preg_replace('/^\s*\d+\.\s*/u', '', trim($title)) ?? trim($title);
        $title = str_replace(['[', ']'], '', $title);
        $title = preg_replace('/^\s*SECTION\s+\d+\s*:?\s*/iu', '', $title) ?? $title;
        $title = preg_replace('/^\s*¡?EMPIEZA\s+AQU[IÍ]!\s*/iu', '', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return trim($title) ?: 'Lección';
    }

    private function cleanSectionTitle(string $title): string
    {
        $title = preg_replace('/^\s*\d+\.\s*/u', '', trim($title)) ?? trim($title);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = trim(str_replace("\xc2\xa0", ' ', $title));

        return match (true) {
            str_contains($this->normalizeText($title), 'comienza aqui') => 'Comienza aquí',
            default => mb_convert_case(mb_strtolower($title, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
        };
    }

    private function extractLeadingNumber(string $value): ?int
    {
        if (preg_match('/^\s*(\d+)/u', $value, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function resolveSectionOrder(string $sectionTitle): int
    {
        $explicitNumber = $this->extractLeadingNumber($sectionTitle);

        if ($explicitNumber) {
            return $explicitNumber;
        }

        return str_contains($this->normalizeText($sectionTitle), 'comienza aqui') ? 1 : 2;
    }

    private function extractExternalLinks(string $line): array
    {
        preg_match_all('/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/iu', $line, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(fn (array $match) => [
                'label' => trim(strip_tags($match[1], '')),
                'url' => $match[2],
                'description' => 'Enlace externo importado desde las notas.',
            ])
            ->all();
    }

    private function extractSummary(array $lines): ?string
    {
        foreach ($lines as $line) {
            $plain = trim($line);

            if (
                $plain === ''
                || in_array($plain, ['---', '.'], true)
                || str_starts_with($plain, '#')
                || str_starts_with($plain, '>')
                || str_starts_with($plain, '![')
                || str_starts_with($plain, '[')
            ) {
                continue;
            }

            $plain = preg_replace('/[*_`>#-]+/u', ' ', $plain) ?? $plain;
            $plain = preg_replace('/\[[^\]]+\]\(([^)]+)\)/u', '$1', $plain) ?? $plain;
            $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;
            $plain = trim($plain);

            if (Str::length($plain) >= 30) {
                return Str::limit($plain, 220);
            }
        }

        return null;
    }

    private function isMetadataLine(string $line): bool
    {
        return Str::startsWith($line, [
            'Categories:',
            'STEPS:',
            'Status:',
            'Categories-Video:',
            'Link Video:',
            'Creado por:',
            'Fecha de creación:',
            'Última edición:',
            'Última edición por:',
        ]);
    }

    private function uniqueResources(array $resources): array
    {
        return collect($resources)
            ->filter(fn (array $resource) => filled($resource['label'] ?? null) && filled($resource['url'] ?? null))
            ->unique(fn (array $resource) => ($resource['label'] ?? '') . '|' . ($resource['url'] ?? ''))
            ->values()
            ->all();
    }

    private function detectCourseTitle(Collection $lessons): string
    {
        $introLesson = $lessons->firstWhere('section_order', 1);

        return $introLesson ? 'Curso De Ingles Raio' : 'RAIO Archive';
    }

    private function normalizeText(string $value): string
    {
        $value = Str::ascii($value);
        $value = str_replace(['&', '/'], ' ', $value);
        $value = preg_replace('/\b[a-f0-9]{32}\b/i', ' ', $value) ?? $value;
        $value = preg_replace('/\([^)]*\)/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^\w\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim(Str::lower($value));
    }

    private function sanitizeZipSegment(string $segment): string
    {
        $segment = Str::ascii($segment);
        $segment = str_replace('%20', ' ', $segment);
        $segment = preg_replace('/[^\w.\- ]+/u', ' ', $segment) ?? $segment;
        $segment = preg_replace('/\s+/', ' ', $segment) ?? $segment;

        return trim($segment) ?: 'item';
    }

    private function tokenize(string $value): array
    {
        $stopwords = ['de', 'la', 'el', 'los', 'las', 'y', 'a', 'of', 'the', 'to', 'video', 'section', 'capitulo', 'curso', 'ingles', 'raio'];

        return collect(explode(' ', $this->normalizeText($value)))
            ->filter(fn (string $token) => $token !== '' && ! in_array($token, $stopwords, true))
            ->values()
            ->all();
    }

    private function resolvePath(string $path): string
    {
        $candidate = Str::startsWith($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path($path);

        if (! File::exists($candidate)) {
            throw new \RuntimeException('No encontré la ruta: ' . $path);
        }

        return $candidate;
    }

    private function extractZip(string $zipPath, string $destination, ?array $extensions = null): void
    {
        if (! File::exists($zipPath)) {
            throw new \RuntimeException('No encontré el ZIP: ' . $zipPath);
        }

        $script = <<<'PY'
import os
import pathlib
import re
import shutil
import sys
import unicodedata
import zipfile

zip_path, destination, extensions = sys.argv[1], sys.argv[2], sys.argv[3]
allowed = {item.strip().lower() for item in extensions.split(',') if item.strip()}

def sanitize_segment(segment: str) -> str:
    segment = unicodedata.normalize('NFKD', segment).encode('ascii', 'ignore').decode('ascii')
    segment = segment.replace('%20', ' ')
    segment = re.sub(r'[^\w.\- ]+', ' ', segment)
    segment = re.sub(r'\s+', ' ', segment).strip()
    return segment[:120] or 'item'

with zipfile.ZipFile(zip_path) as archive:
    for info in archive.infolist():
        if info.is_dir():
            continue

        original = info.filename.replace('\\', '/')
        extension = pathlib.Path(original).suffix.lower().lstrip('.')

        if allowed and extension not in allowed:
            continue

        parts = [sanitize_segment(part) for part in pathlib.PurePosixPath(original).parts if part not in ('', '.')]
        output_path = os.path.join(destination, *parts)
        os.makedirs(os.path.dirname(output_path), exist_ok=True)

        with archive.open(info) as source, open(output_path, 'wb') as target:
            shutil.copyfileobj(source, target, length=1024 * 1024)
PY;

        $process = new Process([
            'python3',
            '-c',
            $script,
            $zipPath,
            $destination,
            implode(',', $extensions ?? []),
        ]);
        $process->setTimeout(null);
        $process->mustRun();
    }

    private function isExternalUrl(string $value): bool
    {
        return preg_match('/^https?:\/\//i', $value) === 1;
    }
}

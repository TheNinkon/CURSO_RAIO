<x-layouts.app :title="$course->title.' · '.$lesson->title">
    @php
        $rawResources = collect($lesson->resource_links ?? []);
        $buildAudioResource = static function (string $url, ?string $label = null): array {
            $path = parse_url($url, PHP_URL_PATH) ?: $url;
            $basename = pathinfo($path, PATHINFO_FILENAME);
            $normalizedBase = \Illuminate\Support\Str::lower($basename);
            $dedupeKey = preg_replace('/(?:[-_ ]\(?1\)?)+$/', '', $normalizedBase) ?: $normalizedBase;
            $readableName = (string) \Illuminate\Support\Str::of(rawurldecode($label ?: $basename))
                ->replace('.mp3', '')
                ->replace(['+', '_', '-'], ' ')
                ->replaceMatches('/\s+/', ' ')
                ->trim()
                ->title();

            $variant = 'practice';
            $title = $readableName !== '' ? $readableName : 'Audio MP3';
            $description = 'Reproduce el audio y ajusta la velocidad para estudiar mejor.';

            if (str_contains($normalizedBase, 'normal')) {
                $variant = 'translation';
                $title = 'Normal version';
                $description = 'Usa este audio mientras lees y cambia la velocidad a 0.5x, 1x, 1.5x o 2x.';
            } elseif (str_contains($normalizedBase, 'interactive')) {
                $variant = 'interactive';
                $title = 'Interactive version';
                $description = 'Audio para practicar preguntas y respuestas con control de velocidad.';
            }

            return [
                'url' => $url,
                'title' => $title,
                'description' => $description,
                'variant' => $variant,
                'download_name' => basename($path),
                'dedupe_key' => $dedupeKey,
                'sort_order' => match ($variant) {
                    'translation' => 0,
                    'interactive' => 1,
                    default => 2,
                },
            ];
        };
        $isAudioResource = static function (array $resource): bool {
            $url = (string) ($resource['url'] ?? '');
            $path = parse_url($url, PHP_URL_PATH) ?: $url;

            return \Illuminate\Support\Str::endsWith(\Illuminate\Support\Str::lower($path), '.mp3');
        };
        $audioResources = $rawResources
            ->filter($isAudioResource)
            ->map(fn (array $resource): array => $buildAudioResource((string) ($resource['url'] ?? '#'), (string) ($resource['label'] ?? '')))
            ->sortBy(fn (array $audio): string => sprintf('%02d-%s', $audio['sort_order'], $audio['title']))
            ->unique('dedupe_key')
            ->values();
        $otherResources = $rawResources
            ->reject($isAudioResource)
            ->values();
        $inlineAudioPlayers = [];
        $notesWithAudioPlaceholders = $lesson->notes_markdown
            ? preg_replace_callback(
                '/^\s*\[([^\]]+)\]\(([^)]+\.mp3(?:\?[^)]*)?)\)\s*$/imu',
                static function (array $matches) use (&$inlineAudioPlayers, $buildAudioResource): string {
                    $index = count($inlineAudioPlayers);
                    $placeholder = "@@INLINE_AUDIO_{$index}@@";
                    $inlineAudioPlayers[$placeholder] = $buildAudioResource($matches[2], $matches[1]);

                    return $placeholder;
                },
                $lesson->notes_markdown,
            )
            : null;
        $notesWithAudioPlaceholders = $notesWithAudioPlaceholders
            ? preg_replace("/\n{3,}/", "\n\n", trim($notesWithAudioPlaceholders))
            : null;
        $markdown = $notesWithAudioPlaceholders
            ? \Illuminate\Support\Str::markdown($notesWithAudioPlaceholders, ['html_input' => 'strip', 'allow_unsafe_links' => false])
            : '<p class="text-white/60">Esta lección todavía no tiene apuntes cargados.</p>';

        foreach ($inlineAudioPlayers as $placeholder => $audio) {
            $playerHtml = <<<HTML
<div class="not-prose my-5">
    <article class="audio-player-card inline-audio-player rounded-[24px] border border-white/8 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <p class="text-sm font-semibold text-white">{$audio['title']}</p>
                <p class="max-w-2xl text-sm leading-6 text-white/58">{$audio['description']}</p>
            </div>
            <a href="{$audio['url']}" download="{$audio['download_name']}" class="inline-flex items-center rounded-full border border-white/10 px-4 py-2 text-sm font-medium text-white/70 transition hover:border-white/20 hover:text-white">
                Descargar MP3
            </a>
        </div>
        <audio class="js-audio-player mt-5 w-full" controls preload="none">
            <source src="{$audio['url']}" type="audio/mpeg">
        </audio>
    </article>
</div>
HTML;

            $markdown = str_replace('<p>' . $placeholder . '</p>', $playerHtml, $markdown);
            $markdown = str_replace($placeholder, $playerHtml, $markdown);
        }
        $availableLessons = $course->lessons->where('is_available', true)->count();
    @endphp

    <section data-course-layout class="course-layout mx-auto grid w-full max-w-[1560px] grid-cols-1 gap-7 px-4 py-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:px-6">
        <div class="space-y-5">
            <div data-player-shell class="overflow-hidden rounded-[28px] border border-white/8 bg-[#181818] p-3 shadow-card">
                <div class="overflow-hidden rounded-[22px] border border-white/8 bg-black">
                    @if ($lesson->video_path)
                        <video
                            class="js-player aspect-video w-full"
                            controls
                            playsinline
                            crossorigin
                            poster="{{ $lesson->poster_path ? asset($lesson->poster_path) : '' }}"
                            data-default-language="es"
                        >
                            <source src="{{ route('lessons.video', $lesson) }}" type="video/mp4">
                            @if ($lesson->subtitle_es_path)
                                <track
                                    kind="captions"
                                    label="Español"
                                    srclang="es"
                                    src="{{ asset($lesson->subtitle_es_path) }}"
                                    default
                                >
                            @endif
                            @if ($lesson->subtitle_en_path)
                                <track
                                    kind="captions"
                                    label="English"
                                    srclang="en"
                                    src="{{ asset($lesson->subtitle_en_path) }}"
                                >
                            @endif
                        </video>
                    @else
                        <div class="grid aspect-video place-items-center bg-[radial-gradient(circle_at_top,rgba(255,255,255,0.08),transparent_32%),linear-gradient(180deg,#131313,#090909)] px-6 text-center">
                            <div class="max-w-xl space-y-3">
                                <p class="text-xs uppercase tracking-[0.35em] text-white/35">Lección sin video</p>
                                <h3 class="text-2xl font-semibold text-white">Esta parte del curso solo tiene apuntes o recursos adjuntos.</h3>
                                <p class="text-sm leading-7 text-white/55">Puedes leer las notas y abrir los archivos disponibles desde las pestañas inferiores.</p>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-b border-white/8 pb-4">
                    <div class="flex flex-wrap items-center gap-2 text-sm text-white/45">
                        <span class="rounded-full border border-white/8 bg-white/5 px-3 py-2">{{ $lesson->video_path ? 'Duración '.$lesson->formatted_duration : 'Solo apuntes y recursos' }}</span>
                        @if ($lesson->subtitle_es_path || $lesson->subtitle_en_path)
                            <span class="rounded-full border border-white/8 bg-white/5 px-3 py-2">Subtítulos {{ $lesson->subtitle_es_path ? 'ES' : '' }}{{ $lesson->subtitle_es_path && $lesson->subtitle_en_path ? ' / ' : '' }}{{ $lesson->subtitle_en_path ? 'EN' : '' }}</span>
                        @endif
                        <span class="rounded-full border border-white/8 bg-white/5 px-3 py-2">{{ $lesson->video_path ? 'Video local archivado' : 'Contenido importado desde Notion' }}</span>
                    </div>
                    @if ($lesson->video_path)
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="player-action-button js-theater-toggle" aria-pressed="false" title="Modo cine (T)">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Zm2 0v10h14V7H5Zm2 2h10v6H7V9Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                            </button>
                            <button type="button" class="player-action-button js-pip-toggle" title="Picture in picture (I)">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6Zm2 0v12h14V6H5Zm8 5h5v4h-5v-4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                            </button>
                            <button type="button" class="player-action-button js-fullscreen-toggle" title="Pantalla completa (F)">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4H4v4M16 4h4v4M20 16v4h-4M8 20H4v-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            <div data-theater-secondary class="space-y-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-3">
                        <a href="{{ route('courses.index') }}" class="inline-flex items-center gap-2 rounded-full border border-white/10 px-4 py-2 text-xs font-medium uppercase tracking-[0.25em] text-white/55 transition hover:border-white/20 hover:text-white">
                            <span aria-hidden="true">←</span>
                            Volver a mis cursos
                        </a>
                        <div>
                            <p class="text-xs uppercase tracking-[0.3em] text-white/35">Course player</p>
                            <h2 class="mt-2 max-w-4xl text-2xl font-semibold leading-tight text-white md:text-3xl">{{ $lesson->title }}</h2>
                            <p class="mt-3 max-w-4xl text-sm leading-7 text-white/55">{{ $lesson->summary }}</p>
                        </div>
                    </div>
                </div>

            </div>

            <div data-theater-secondary class="rounded-[28px] border border-white/8 bg-[#181818] shadow-card">
                <div class="border-b border-white/8 px-6 pt-6">
                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="tab-button is-active" data-tab-target="summary">Summary</button>
                        <button type="button" class="tab-button" data-tab-target="resources">Resources</button>
                        <button type="button" class="tab-button" data-tab-target="files">Files</button>
                    </div>
                </div>

                <div class="p-6">
                    <div class="tab-panel is-active" data-tab-panel="summary">
                        <article class="prose prose-invert max-w-none prose-headings:font-semibold prose-p:text-white/68 prose-li:text-white/68 prose-strong:text-white prose-a:text-white">
                            {!! $markdown !!}
                        </article>
                    </div>

                    <div class="tab-panel hidden" data-tab-panel="resources">
                        <div class="grid gap-4 md:grid-cols-2">
                            @forelse ($otherResources as $resource)
                                <a href="{{ $resource['url'] }}" @if (($resource['url'] ?? '') !== '#') target="_blank" rel="noopener noreferrer" @endif class="rounded-[24px] border border-white/8 bg-black/20 p-5 transition hover:border-white/14 hover:bg-black/30">
                                    <p class="text-base font-semibold text-white">{{ $resource['label'] }}</p>
                                    <p class="mt-2 text-sm leading-6 text-white/55">{{ $resource['description'] }}</p>
                                </a>
                            @empty
                                <p class="text-white/55">No hay recursos extra cargados para esta lección fuera del reproductor de audio.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="tab-panel hidden" data-tab-panel="files">
                        <div class="grid gap-4 md:grid-cols-3">
                            @if ($lesson->video_path)
                                <a href="{{ route('lessons.video', ['lesson' => $lesson, 'download' => 1]) }}" class="rounded-[24px] border border-white/8 bg-black/20 p-5 transition hover:border-white/14 hover:bg-black/30">
                                    <p class="text-base font-semibold text-white">Video MP4</p>
                                    <p class="mt-2 text-sm leading-6 text-white/55">Descarga el archivo principal del curso.</p>
                                </a>
                            @endif

                            @if ($lesson->subtitle_es_path)
                                <a href="{{ asset($lesson->subtitle_es_path) }}" download class="rounded-[24px] border border-white/8 bg-black/20 p-5 transition hover:border-white/14 hover:bg-black/30">
                                    <p class="text-base font-semibold text-white">Subtítulos Español</p>
                                    <p class="mt-2 text-sm leading-6 text-white/55">Pista VTT en español.</p>
                                </a>
                            @endif

                            @if ($lesson->subtitle_en_path)
                                <a href="{{ asset($lesson->subtitle_en_path) }}" download class="rounded-[24px] border border-white/8 bg-black/20 p-5 transition hover:border-white/14 hover:bg-black/30">
                                    <p class="text-base font-semibold text-white">Subtítulos English</p>
                                    <p class="mt-2 text-sm leading-6 text-white/55">Pista VTT en inglés.</p>
                                </a>
                            @endif

                            @foreach ($audioResources as $audio)
                                <a href="{{ $audio['url'] }}" download="{{ $audio['download_name'] }}" class="rounded-[24px] border border-white/8 bg-black/20 p-5 transition hover:border-white/14 hover:bg-black/30">
                                    <p class="text-base font-semibold text-white">{{ $audio['title'] }} MP3</p>
                                    <p class="mt-2 text-sm leading-6 text-white/55">{{ $audio['description'] }}</p>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <aside class="course-curriculum rounded-[28px] border border-white/8 bg-[#181818] p-5 shadow-card lg:sticky lg:top-4 lg:flex lg:max-h-[calc(100vh-2rem)] lg:flex-col lg:overflow-hidden">
            <div class="border-b border-white/8 pb-5">
                <p class="text-xs uppercase tracking-[0.3em] text-white/35">Course curriculum</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">{{ $course->title }}</h3>
                <p class="mt-2 text-sm text-white/55">{{ $availableLessons }} de {{ $course->estimated_lessons }} lecciones importadas</p>
            </div>

            <div class="mt-5 space-y-6 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:pr-1">
                @foreach ($groupedLessons as $section => $lessons)
                    <section class="space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-semibold uppercase tracking-[0.2em] text-white/45">{{ $section }}</h4>
                            <span class="text-xs text-white/30">{{ $lessons->count() }}</span>
                        </div>

                        <div class="space-y-2">
                            @foreach ($lessons as $item)
                                @if ($item->is_available)
                                    <a href="{{ route('courses.lessons.show', [$course, $item]) }}" class="group flex items-start gap-3 rounded-[18px] border px-4 py-3 transition {{ $item->is($lesson) ? 'border-white/14 bg-white text-[#111111]' : 'border-white/7 bg-black/20 text-white hover:border-white/12 hover:bg-black/30' }}">
                                        <span class="mt-1 grid h-8 w-8 shrink-0 place-items-center rounded-full {{ $item->is($lesson) ? 'bg-[#111111] text-white' : 'bg-white/8 text-white/80' }}">▶</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium leading-6 {{ $item->is($lesson) ? 'text-[#111111]' : 'text-white' }}">{{ $item->title }}</p>
                                            <p class="mt-1 text-xs {{ $item->is($lesson) ? 'text-[#111111]/70' : 'text-white/40' }}">{{ $item->formatted_duration }}</p>
                                        </div>
                                    </a>
                                @else
                                    <div class="flex items-start gap-3 rounded-[18px] border border-dashed border-white/7 bg-black/10 px-4 py-3 text-white/35">
                                        <span class="mt-1 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-white/5">•</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium leading-6">{{ $item->title }}</p>
                                            <p class="mt-1 text-xs">Pendiente de importar</p>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </aside>
    </section>
</x-layouts.app>

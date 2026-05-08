<x-layouts.app :title="'Mis cursos · RAIO Archive'">
    <section class="mx-auto grid min-h-[calc(100vh-89px)] w-full max-w-7xl grid-cols-1 gap-8 px-6 py-8 lg:grid-cols-[320px_minmax(0,1fr)] lg:px-8">
        <aside class="panel-panel flex flex-col gap-8 rounded-[32px] border border-white/8 bg-[#262626] p-7 shadow-card">
            <div class="rounded-[28px] border border-white/8 bg-gradient-to-b from-white/8 to-transparent p-6">
                <div class="mx-auto mb-5 grid h-28 w-28 place-items-center rounded-full border border-white/10 bg-[linear-gradient(135deg,#353535,#1a1a1a)] text-3xl font-semibold text-white/90">
                    RA
                </div>
                <h2 class="text-center text-2xl font-semibold text-white">Biblioteca RAIO</h2>
                <p class="mt-2 text-center text-sm leading-6 text-white/55">
                    Tu archivo privado del curso. Videos, subtítulos y apuntes guardados localmente.
                </p>
                <div class="mt-5 flex justify-center">
                    <a href="{{ route('imports.index') }}" class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/8 px-4 py-3 text-sm font-medium text-white transition hover:border-white/20 hover:bg-white/12">
                        Abrir panel de importación
                        <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>

            <div class="grid gap-3">
                <div class="rounded-2xl border border-white/7 bg-black/20 p-4">
                    <p class="text-xs uppercase tracking-[0.25em] text-white/35">Estado</p>
                    <p class="mt-2 text-2xl font-semibold text-white">{{ $courses->sum('estimated_lessons') }} lecciones previstas</p>
                </div>
                <div class="rounded-2xl border border-white/7 bg-black/20 p-4">
                    <p class="text-xs uppercase tracking-[0.25em] text-white/35">Importadas</p>
                    <p class="mt-2 text-2xl font-semibold text-white">{{ $courses->sum(fn ($course) => $course->lessons->where('is_available', true)->count()) }} listas</p>
                </div>
            </div>
        </aside>

        <div class="space-y-8">
            <div class="grid gap-6 xl:grid-cols-2">
                @foreach ($courses as $course)
                    @php
                        $firstLesson = $course->first_available_lesson;
                        $availableLessons = $course->lessons->where('is_available', true)->count();
                    @endphp
                    <article class="group overflow-hidden rounded-[30px] border border-white/8 bg-[#262626] shadow-card transition duration-300 hover:-translate-y-1 hover:border-white/14">
                        <div class="relative h-64 overflow-hidden border-b border-white/7 bg-[linear-gradient(135deg,#262626,#111111)]">
                            @if ($course->cover_path)
                                <img src="{{ asset($course->cover_path) }}" alt="{{ $course->title }}" class="h-full w-full object-cover opacity-65 transition duration-500 group-hover:scale-105">
                            @endif
                            <div class="absolute inset-0 bg-[linear-gradient(180deg,transparent,rgba(0,0,0,0.84))]"></div>
                            <div class="absolute inset-x-0 bottom-0 p-6">
                                <p class="text-xs uppercase tracking-[0.3em] text-white/45">{{ $course->instructor }}</p>
                                <h3 class="mt-2 text-3xl font-semibold text-white">{{ $course->title }}</h3>
                                <p class="mt-2 max-w-xl text-sm leading-6 text-white/60">{{ $course->tagline }}</p>
                            </div>
                        </div>

                        <div class="space-y-5 p-6">
                            <div class="flex flex-wrap items-center gap-3 text-sm text-white/45">
                                <span>{{ $availableLessons }} de {{ $course->estimated_lessons }} lecciones importadas</span>
                                <span class="h-1 w-1 rounded-full bg-white/20"></span>
                                <span>{{ number_format($course->progress_percent, 1) }}% del archivo preparado</span>
                            </div>

                            <div class="h-2 overflow-hidden rounded-full bg-white/6">
                                <div class="h-full rounded-full bg-[linear-gradient(90deg,#7fb0ff,#ffffff)]" style="width: {{ min(100, max(8, $course->progress_percent)) }}%"></div>
                            </div>

                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.25em] text-white/35">Primera lección</p>
                                    <p class="mt-2 text-base font-medium text-white">{{ $firstLesson?->title }}</p>
                                </div>
                                @if ($firstLesson)
                                    <a href="{{ route('courses.lessons.show', [$course, $firstLesson]) }}" class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white px-5 py-3 text-sm font-semibold text-[#111111] transition hover:bg-white/90">
                                        Abrir curso
                                        <span aria-hidden="true">→</span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
</x-layouts.app>

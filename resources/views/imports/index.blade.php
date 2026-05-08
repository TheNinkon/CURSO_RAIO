<x-layouts.app :title="'Importar biblioteca · RAIO Archive'">
    <section class="mx-auto grid w-full max-w-7xl gap-8 px-6 py-8 lg:grid-cols-[minmax(0,1.15fr)_360px] lg:px-8">
        <div class="space-y-6">
            <div class="rounded-[30px] border border-white/8 bg-[#262626] p-7 shadow-card">
                <p class="text-xs uppercase tracking-[0.3em] text-white/35">Panel local</p>
                <h2 class="mt-3 text-3xl font-semibold text-white">Importar curso desde ZIPs o carpetas</h2>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-white/60">
                    Pega una ruta por línea para los videos y una ruta para el export de Notion. El sistema extrae, organiza y conecta
                    automáticamente videos, subtítulos, notas y archivos adjuntos.
                </p>
            </div>

            <form method="POST" action="{{ route('imports.store') }}" class="space-y-6 rounded-[30px] border border-white/8 bg-[#1a1a1a] p-7 shadow-card">
                @csrf

                <div class="grid gap-6 lg:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-white">Slug del curso</span>
                        <input
                            type="text"
                            name="course_slug"
                            value="{{ old('course_slug', 'curso-de-ingles-raio') }}"
                            class="mt-3 w-full rounded-2xl border border-white/10 bg-black/25 px-4 py-3 text-sm text-white outline-none transition placeholder:text-white/30 focus:border-white/20"
                            placeholder="curso-de-ingles-raio"
                        >
                    </label>

                    <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/20 px-4 py-3">
                        <input
                            type="checkbox"
                            name="replace_existing"
                            value="1"
                            @checked(old('replace_existing', '1'))
                            class="h-4 w-4 rounded border-white/20 bg-black/20 text-white focus:ring-0"
                        >
                        <span class="text-sm text-white/75">Reemplazar la importación anterior</span>
                    </label>
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-white">Rutas de videos</span>
                    <textarea
                        name="video_sources"
                        rows="8"
                        class="mt-3 w-full rounded-[24px] border border-white/10 bg-black/25 px-4 py-4 text-sm leading-7 text-white outline-none transition placeholder:text-white/30 focus:border-white/20"
                        placeholder="/ruta/al/zip-1.zip&#10;/ruta/al/zip-2.zip&#10;/ruta/a/una/carpeta-extraida"
                    >{{ old('video_sources', implode("\n", $defaults['video_sources'] ?? [])) }}</textarea>
                    <p class="mt-2 text-xs text-white/35">Una ruta por línea. Se aceptan ZIPs y carpetas ya extraídas.</p>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-white">Ruta del export de Notion</span>
                    <input
                        type="text"
                        name="notion_source"
                        value="{{ old('notion_source', $defaults['notion_source'] ?? '') }}"
                        class="mt-3 w-full rounded-2xl border border-white/10 bg-black/25 px-4 py-3 text-sm text-white outline-none transition placeholder:text-white/30 focus:border-white/20"
                        placeholder="/ruta/al/export-de-notion.zip"
                    >
                </label>

                @if ($errors->any() || session('import_error'))
                    <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                        @if (session('import_error'))
                            <p>{{ session('import_error') }}</p>
                        @endif
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-4">
                    <p class="text-sm text-white/45">La importación grande puede tardar varios minutos la primera vez.</p>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-3 text-sm font-semibold text-[#111111] transition hover:bg-white/90">
                        Ejecutar importación
                        <span aria-hidden="true">→</span>
                    </button>
                </div>
            </form>
        </div>

        <aside class="space-y-5">
            <div class="rounded-[30px] border border-white/8 bg-[#262626] p-6 shadow-card">
                <p class="text-xs uppercase tracking-[0.3em] text-white/35">Detectado</p>
                <div class="mt-4 space-y-3 text-sm text-white/65">
                    <p>{{ count($defaults['video_sources'] ?? []) }} fuentes de video encontradas en `curso/`.</p>
                    <p>{{ ($defaults['notion_source'] ?? null) ? 'Export de Notion localizado automáticamente.' : 'No encontré export de Notion automáticamente.' }}</p>
                </div>
            </div>

            <div class="rounded-[30px] border border-white/8 bg-[#262626] p-6 shadow-card">
                <p class="text-xs uppercase tracking-[0.3em] text-white/35">Biblioteca actual</p>
                <div class="mt-4 space-y-4">
                    @forelse ($courses as $course)
                        <div class="rounded-2xl border border-white/8 bg-black/20 p-4">
                            <p class="text-sm font-semibold text-white">{{ $course->title }}</p>
                            <p class="mt-2 text-sm text-white/50">{{ $course->lessons->where('is_available', true)->count() }} lecciones listas</p>
                        </div>
                    @empty
                        <p class="text-sm text-white/50">Todavía no hay cursos importados.</p>
                    @endforelse
                </div>
            </div>
        </aside>
    </section>
</x-layouts.app>

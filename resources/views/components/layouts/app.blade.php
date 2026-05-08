<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'RAIO Archive' }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#0f0f10] text-white antialiased">
        <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_top,rgba(255,255,255,0.06),transparent_28%),linear-gradient(180deg,rgba(255,255,255,0.03),transparent_30%)]"></div>
        <div class="relative min-h-screen">
            <header class="border-b border-white/6 bg-[#1c1c1c]/95 backdrop-blur-xl">
                <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-6 px-6 py-5 lg:px-8">
                    <a href="{{ route('courses.index') }}" class="flex items-center gap-3">
                        <div class="grid h-11 w-11 place-items-center rounded-2xl bg-gradient-to-br from-white/20 to-white/5 text-lg font-black tracking-[0.3em] text-white shadow-[0_14px_40px_rgba(0,0,0,0.35)]">R</div>
                        <div>
                            <p class="text-xs uppercase tracking-[0.35em] text-white/40">Course Vault</p>
                            <h1 class="text-lg font-semibold text-white">RAIO Archive</h1>
                        </div>
                    </a>
                    <nav class="hidden items-center gap-3 md:flex">
                        <a href="{{ route('courses.index') }}" class="rounded-full border border-white/10 px-4 py-2 text-sm text-white/70 transition hover:border-white/20 hover:text-white">Mis cursos</a>
                        <a href="{{ route('imports.index') }}" class="rounded-full border border-white/10 px-4 py-2 text-sm text-white/70 transition hover:border-white/20 hover:text-white">Importar</a>
                    </nav>
                </div>
            </header>

            <main>
                @if (session('import_success'))
                    <div class="mx-auto w-full max-w-7xl px-6 pt-6 lg:px-8">
                        <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                            {{ session('import_success') }}
                        </div>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </body>
</html>

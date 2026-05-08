# RAIO Archive

Biblioteca privada del curso construida en Laravel 13.

## Requisito importante

Laravel 13 requiere PHP 8.3 o superior.  
Tu XAMPP actual usa PHP 8.2.4, así que **no debes abrir este proyecto con Apache/XAMPP**.

Úsalo con el PHP de Homebrew que ya está instalado en tu Mac.

## Arranque rápido

Con Yarn:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/CURSO_RAIO
yarn dev
```

Con script shell:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/CURSO_RAIO
./serve.sh
```

Abre:

```text
http://127.0.0.1:8000
```

`yarn dev` levanta dos procesos:

- Laravel con `/opt/homebrew/bin/php`
- Vite para los assets frontend

## Qué incluye

- Pantalla `Mis cursos`
- Pantalla `Player del curso`
- Panel de importación en `http://127.0.0.1:8000/imports`
- Reproductor `Plyr` con captions, velocidad y fullscreen
- Importación automática desde ZIPs locales y export de Notion
- Base de datos MySQL en `curso_raio_library`
- 20 lecciones importadas desde tu archivo local

## Base de datos

Configurada en `.env` con:

- host: `127.0.0.1`
- puerto: `3306`
- base: `curso_raio_library`
- usuario: `root`
- password: vacío

## Media del curso

- `public/media/videos`
- `public/media/subtitles`
- `public/media/posters`
- `public/media/sources`
- `public/media/imported`
- `storage/app/course-media`

## Importación local

1. Abre `http://127.0.0.1:8000/imports`
2. Deja las rutas detectadas automáticamente o pega tus propias rutas
3. Ejecuta la importación

Acepta:

- ZIPs grandes de video
- Carpetas ya extraídas
- ZIP del export de Notion

## Comandos útiles

```bash
/opt/homebrew/bin/php artisan migrate:fresh --seed
npm run build
```

Después de un `migrate:fresh --seed`, usa el panel `/imports` para volver a cargar el curso real.

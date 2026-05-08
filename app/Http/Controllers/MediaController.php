<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function video(Request $request, Lesson $lesson): StreamedResponse
    {
        abort_unless($lesson->is_available && $lesson->video_path, 404);

        $path = $this->resolveMediaPath($lesson->video_path);

        abort_unless(File::exists($path), 404);

        $size = File::size($path);
        $mimeType = File::mimeType($path) ?: 'video/mp4';
        $start = 0;
        $end = $size - 1;
        $status = 200;

        $rangeHeader = $request->header('Range');

        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches) === 1) {
            $rangeStart = $matches[1];
            $rangeEnd = $matches[2];

            if ($rangeStart === '' && $rangeEnd === '') {
                abort(416);
            }

            if ($rangeStart === '') {
                $suffixLength = (int) $rangeEnd;
                $start = max(0, $size - $suffixLength);
            } else {
                $start = (int) $rangeStart;
            }

            if ($rangeEnd !== '') {
                $end = min((int) $rangeEnd, $end);
            }

            if ($start > $end || $start >= $size) {
                return response()->stream(static function (): void {
                }, 416, [
                    'Content-Range' => "bytes */{$size}",
                ]);
            }

            $status = 206;
        }

        $length = $end - $start + 1;

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=3600',
            'Content-Disposition' => $request->boolean('download')
                ? 'attachment; filename="' . basename($path) . '"'
                : 'inline; filename="' . basename($path) . '"',
        ];

        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        if ($request->isMethod('HEAD')) {
            return response()->stream(static function (): void {
            }, $status, $headers);
        }

        return response()->stream(function () use ($path, $start, $end): void {
            $stream = fopen($path, 'rb');

            if ($stream === false) {
                return;
            }

            fseek($stream, $start);

            $remaining = $end - $start + 1;
            $chunkSize = 1024 * 1024;

            while (! feof($stream) && $remaining > 0) {
                $readLength = min($chunkSize, $remaining);
                $buffer = fread($stream, $readLength);

                if ($buffer === false) {
                    break;
                }

                echo $buffer;
                flush();

                $remaining -= strlen($buffer);
            }

            fclose($stream);
        }, $status, $headers);
    }

    private function resolveMediaPath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : public_path($path);
    }
}

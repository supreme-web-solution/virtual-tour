<?php

namespace App\Services\Video\Render;

use App\Contracts\Video\VideoRenderer;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * FFmpeg-based assembly.
 *
 * Security: commands are passed to Symfony Process as argument arrays (no
 * shell), and every input/output path must live inside the render's own
 * working directory under the configured temp path. Nothing user-supplied
 * reaches the command line except validated numbers.
 */
class FfmpegVideoRenderer implements VideoRenderer
{
    protected ?bool $available = null;

    /**
     * Only ffmpeg is needed: it renders and also reads media details back.
     * The result is remembered for the rest of the request.
     */
    public function isAvailable(): bool
    {
        return $this->available ??= $this->runs('ffmpeg_path');
    }

    /**
     * Whether a binary starts and answers quickly (security software that
     * blocks or slowly scans it makes it unusable for rendering anyway).
     */
    protected function runs(string $binary): bool
    {
        try {
            $process = new Process([$this->binary($binary), '-hide_banner', '-version']);
            $process->setTimeout(10)->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasAudio(string $path): bool
    {
        return (bool) $this->inspect($path)['has_audio'];
    }

    public function render(RenderSpec $spec): RenderResult
    {
        $this->assertPaths($spec);

        $total = $spec->totalSeconds();
        [$inputs, $filters, $videoLabel, $audioLabel] = $this->graph($spec, $total);

        $arguments = [
            $this->binary('ffmpeg_path'), '-hide_banner', '-nostdin', '-y',
            ...$inputs,
            '-filter_complex', implode(';', $filters),
            '-map', "[{$videoLabel}]",
        ];

        if ($audioLabel !== null) {
            array_push($arguments, '-map', "[{$audioLabel}]", '-c:a', 'aac', '-b:a', '160k', '-ar', '48000');
        } else {
            $arguments[] = '-an';
        }

        array_push(
            $arguments,
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '21', '-pix_fmt', 'yuv420p',
            '-r', (string) $spec->fps, '-movflags', '+faststart',
            '-t', $this->number($total),
            $spec->outputPath,
        );

        $this->run($arguments, (int) config('ai.video.render_timeout', 1500));

        $this->run([
            $this->binary('ffmpeg_path'), '-hide_banner', '-nostdin', '-y',
            '-ss', $this->number(min(1.0, $total / 3)), '-i', $spec->outputPath,
            '-frames:v', '1', '-q:v', '3', $spec->posterPath,
        ], 60);

        return $this->probe($spec);
    }

    /**
     * Build inputs and the filter graph.
     *
     * @return array{0: list<string>, 1: list<string>, 2: string, 3: string|null}
     */
    protected function graph(RenderSpec $spec, float $total): array
    {
        $width = $spec->width;
        $height = $spec->height;
        $fps = $spec->fps;
        $inputs = [];
        $filters = [];
        $videoLabels = [];
        $audioLabels = [];
        $index = 0;

        foreach ($spec->clips as $position => $clip) {
            $duration = $this->number($clip->duration);
            array_push($inputs, '-i', $clip->path);

            // Cover-fit into the frame (centre crop, never stretch), pad short clips by holding the last frame.
            $filters[] = "[{$index}:v]scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},"
                ."fps={$fps},format=yuv420p,setsar=1,tpad=stop_mode=clone:stop_duration={$duration},"
                ."trim=duration={$duration},setpts=PTS-STARTPTS[v{$position}]";
            $videoLabels[] = "v{$position}";

            if ($spec->keepClipAudio) {
                if ($clip->hasAudio) {
                    $filters[] = "[{$index}:a]aformat=sample_rates=48000:channel_layouts=stereo,"
                        ."apad=whole_dur={$duration},atrim=duration={$duration},asetpts=PTS-STARTPTS[a{$position}]";
                } else {
                    $filters[] = "anullsrc=r=48000:cl=stereo,atrim=duration={$duration}[a{$position}]";
                }

                $audioLabels[] = "a{$position}";
            }

            $index++;
        }

        if ($spec->outroLogoPath !== null) {
            $outro = $this->number($spec->outroSeconds);
            $position = count($spec->clips);
            array_push($inputs, '-loop', '1', '-t', $outro, '-i', $spec->outroLogoPath);
            $logoWidth = (int) round($width * 0.4);
            $filters[] = "color=c=black:s={$width}x{$height}:r={$fps}:d={$outro}[outrobg]";
            $filters[] = "[{$index}:v]scale={$logoWidth}:-2:force_original_aspect_ratio=decrease,format=rgba[outrologo]";
            $filters[] = '[outrobg][outrologo]overlay=(W-w)/2:(H-h)/2:shortest=1,format=yuv420p,setsar=1,'
                ."trim=duration={$outro},setpts=PTS-STARTPTS[v{$position}]";
            $videoLabels[] = "v{$position}";

            if ($spec->keepClipAudio) {
                $filters[] = "anullsrc=r=48000:cl=stereo,atrim=duration={$outro}[a{$position}]";
                $audioLabels[] = "a{$position}";
            }

            $index++;
        }

        // Join segments with transitions.
        $segments = $spec->segments();
        $videoOut = $videoLabels[0];
        $audioOut = $audioLabels[0] ?? null;
        $elapsed = $segments[0]['duration'];

        for ($next = 1; $next < count($segments); $next++) {
            $fade = RenderSpec::transitionSeconds($segments[$next - 1]['transition']);
            $effect = $segments[$next - 1]['transition'] === 'fade_black' ? 'fadeblack' : 'fade';
            $offset = $this->number($elapsed - $fade);

            $filters[] = "[{$videoOut}][{$videoLabels[$next]}]xfade=transition={$effect}:duration={$this->number($fade)}:offset={$offset}[vx{$next}]";
            $videoOut = "vx{$next}";

            if ($audioOut !== null) {
                $filters[] = "[{$audioOut}][{$audioLabels[$next]}]acrossfade=d={$this->number($fade)}[ax{$next}]";
                $audioOut = "ax{$next}";
            }

            $elapsed += $segments[$next]['duration'] - $fade;
        }

        // Mix soundtrack layers.
        $mix = [];
        $totalText = $this->number($total);

        if ($audioOut !== null) {
            $mix[] = $audioOut;
        }

        foreach (['music' => $spec->music, 'voice' => $spec->voiceover] as $name => $track) {
            if ($track === null) {
                continue;
            }

            if ($track->loop) {
                array_push($inputs, '-stream_loop', '-1');
            }

            array_push($inputs, '-i', $track->path);
            $chain = "[{$index}:a]aformat=sample_rates=48000:channel_layouts=stereo,"
                .'atrim=start='.$this->number($track->trimStart).",asetpts=PTS-STARTPTS,atrim=duration={$totalText},"
                .'volume='.$this->number($track->volume);

            if ($track->fadeIn > 0) {
                $chain .= ',afade=t=in:d='.$this->number($track->fadeIn);
            }

            if ($track->fadeOut > 0) {
                $chain .= ',afade=t=out:st='.$this->number(max(0, $total - $track->fadeOut)).':d='.$this->number($track->fadeOut);
            }

            $filters[] = $chain."[{$name}]";
            $mix[] = $name;
            $index++;
        }

        if ($mix === []) {
            return [$inputs, $filters, $videoOut, null];
        }

        $mixed = '['.implode('][', $mix).']amix=inputs='.count($mix).":normalize=0:duration=longest,apad=whole_dur={$totalText},atrim=duration={$totalText}[aout]";
        $filters[] = $mixed;

        return [$inputs, $filters, $videoOut, 'aout'];
    }

    protected function probe(RenderSpec $spec): RenderResult
    {
        $info = $this->inspect($spec->outputPath);

        if ($info === null || $info['width'] === 0 || ! is_file($spec->posterPath)) {
            throw new RuntimeException('The rendered video is missing its picture or poster.');
        }

        return new RenderResult(
            videoPath: $spec->outputPath,
            posterPath: $spec->posterPath,
            duration: $info['duration'],
            width: $info['width'],
            height: $info['height'],
            hasAudio: $info['has_audio'],
        );
    }

    /**
     * Read a media file's length, picture size and whether it has sound,
     * using ffmpeg itself (no separate ffprobe binary is needed). ffmpeg
     * prints this to stderr and exits with an error because no output file
     * is given, which is expected here.
     *
     * @return array{duration: float, width: int, height: int, has_audio: bool}|null
     */
    protected function inspect(string $path): ?array
    {
        $process = new Process([$this->binary('ffmpeg_path'), '-hide_banner', '-nostdin', '-i', $path]);
        $process->setTimeout(30)->run();
        $output = $process->getErrorOutput();

        if (! preg_match('/Duration: (\d+):(\d{2}):(\d{2}(?:\.\d+)?)/', $output, $time)) {
            return null;
        }

        preg_match('/Stream #\d+:\d+.*?: Video: [^\n]*?, (\d{2,5})x(\d{2,5})[\s,\[]/', $output, $size);

        return [
            'duration' => round(((int) $time[1]) * 3600 + ((int) $time[2]) * 60 + (float) $time[3], 2),
            'width' => (int) ($size[1] ?? 0),
            'height' => (int) ($size[2] ?? 0),
            'has_audio' => preg_match('/Stream #\d+:\d+.*?: Audio:/', $output) === 1,
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    protected function run(array $arguments, int $timeout): void
    {
        $process = new Process($arguments);
        $process->setTimeout($timeout)->run();

        if (! $process->isSuccessful()) {
            $error = trim(mb_substr($process->getErrorOutput(), -1500));

            throw new RuntimeException('FFmpeg failed: '.$error);
        }
    }

    /**
     * Every path must be inside the render's working directory, which must be
     * inside the configured temp path.
     */
    protected function assertPaths(RenderSpec $spec): void
    {
        $base = realpath((string) config('ai.video.temp_path'));
        $work = realpath($spec->workingDirectory);

        if ($base === false || $work === false || ! str_starts_with($work, $base.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Render working directory is outside the allowed temp path.');
        }

        $paths = [
            ...array_map(fn (RenderClip $clip): string => $clip->path, $spec->clips),
            ...array_filter([$spec->music?->path, $spec->voiceover?->path, $spec->outroLogoPath]),
        ];

        foreach ($paths as $path) {
            $real = realpath($path);

            if ($real === false || ! str_starts_with($real, $work.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('A render input is outside the working directory.');
            }
        }

        foreach ([$spec->outputPath, $spec->posterPath] as $path) {
            if (! str_starts_with((string) realpath(dirname($path)), $work)) {
                throw new RuntimeException('A render output is outside the working directory.');
            }
        }

        if ($spec->clips === []) {
            throw new RuntimeException('There are no clips to render.');
        }
    }

    protected function binary(string $key): string
    {
        return (string) config("services.ffmpeg.{$key}", 'ffmpeg');
    }

    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}

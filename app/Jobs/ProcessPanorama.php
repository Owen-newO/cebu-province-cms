<?php

namespace App\Jobs;

use App\Models\Scene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ProcessPanorama implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $sceneId;

    public function __construct(int $sceneId)
    {
        $this->sceneId = $sceneId;
    }

    public function handle(): void
    {
        $scene = Scene::find($this->sceneId);
        if (!$scene) return;

        $scene->update(['status' => 'processing']);

        try {
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

            /* -------------------------------------------------
             | LOCAL WORKSPACE
             ------------------------------------------------- */
            $sceneKey = $scene->scene_id ?? (string)$scene->id;
            $sceneKey = preg_replace('/[^A-Za-z0-9_\-]/', '_', $sceneKey);

            $tempDir = storage_path("app/tmp_scenes/{$sceneKey}");
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

            /* -------------------------------------------------
             | DOWNLOAD ORIGINAL PANORAMA FROM S3
             ------------------------------------------------- */
            $parsed = parse_url($scene->panorama_path);
            if (!isset($parsed['path'])) {
                throw new \RuntimeException('Invalid panorama_path');
            }

            $s3Key = ltrim($parsed['path'], '/');
            $localPano = "{$tempDir}/panorama.jpg";

            Storage::disk('s3')->getDriver()->getAdapter()->getClient()->getObject([
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key'    => $s3Key,
                'SaveAs' => $localPano,
            ]);

            /* -------------------------------------------------
             | KRPANO EXECUTION
             ------------------------------------------------- */
            $krpanoTool = $isWindows
                ? base_path('krpanotools/krpanotools.exe')
                : base_path('krpanotools/krpanotools');

            $configPath = base_path('krpanotools/templates/vtour-multires.config');

            if ($isWindows) {
                $krpanoTool = str_replace('/', '\\', $krpanoTool);
                $configPath = str_replace('/', '\\', $configPath);
                $localPano  = str_replace('/', '\\', $localPano);
            }

            $process = new Process([
                $krpanoTool,
                'makepano',
                "-config={$configPath}",
                $localPano
            ]);

            $process->setTimeout(900); // 15 minutes
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput());
            }

            /* -------------------------------------------------
             | PARSE KRPANO-GENERATED tour.xml (SOURCE OF TRUTH)
             ------------------------------------------------- */
            $generatedTourXml = "{$tempDir}/vtour/tour.xml";
            if (!file_exists($generatedTourXml)) {
                throw new \RuntimeException('krpano tour.xml not found');
            }

            $xml = simplexml_load_file($generatedTourXml);
            if (!$xml) {
                throw new \RuntimeException('Failed parsing krpano tour.xml');
            }

            $target = strtolower($sceneKey);
            $thumb = $preview = $cubeUrl = $multires = '';

            foreach ($xml->scene as $sceneNode) {
                if (strtolower((string)$sceneNode['name']) !== $target) {
                    continue;
                }

                $thumb   = (string)($sceneNode['thumburl'] ?? '');
                $preview = $sceneNode->preview
                    ? (string)$sceneNode->preview['url']
                    : '';

                if ($sceneNode->image && $sceneNode->image->cube) {
                    $cubeUrl  = (string)$sceneNode->image->cube['url'];
                    $multires = (string)$sceneNode->image->cube['multires'];
                }

                break;
            }

            if (!$cubeUrl) {
                throw new \RuntimeException('Cube URL missing in krpano output');
            }

            /* -------------------------------------------------
             | APPEND TO MASTER TOUR.XML (MUNICIPAL-AWARE)
             ------------------------------------------------- */
            $masterTourXml = storage_path("app/{$scene->municipal}/tour.xml");
            if (!file_exists($masterTourXml)) {
                throw new \RuntimeException('Master tour.xml not found');
            }

            $title = htmlspecialchars($scene->title ?? '', ENT_QUOTES);
            $sub   = htmlspecialchars($scene->location ?? '', ENT_QUOTES);

            $newScene = "
<scene name=\"{$sceneKey}\" title=\"{$title}\" subtitle=\"{$sub}\" thumburl=\"{$thumb}\">
    <view hlookat=\"0\" vlookat=\"0\" fov=\"90\" />
    <preview url=\"{$preview}\" />
    <image>
        <cube url=\"{$cubeUrl}\" multires=\"{$multires}\" />
    </image>
</scene>
";

            $masterXml = file_get_contents($masterTourXml);

            if (!str_contains($masterXml, "name=\"{$sceneKey}\"")) {
                $masterXml = str_replace('</krpano>', $newScene . "\n</krpano>", $masterXml);
                file_put_contents($masterTourXml, $masterXml);
            }

            /* -------------------------------------------------
             | CLEANUP
             ------------------------------------------------- */
            $this->deleteDir($tempDir);

            $scene->update(['status' => 'done']);

            Log::info('✅ Panorama processed successfully', [
                'scene_id' => $scene->id,
                'cube'     => $cubeUrl,
                'multires' => $multires,
            ]);

        } catch (\Throwable $e) {
            Log::error('❌ Panorama processing failed', [
                'scene_id' => $scene->id,
                'error'    => $e->getMessage(),
            ]);

            $scene->update(['status' => 'failed']);
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}

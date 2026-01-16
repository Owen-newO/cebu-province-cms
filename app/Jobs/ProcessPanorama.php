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
        if (!$scene) {
            Log::warning('ProcessPanorama: scene not found', ['scene_id' => $this->sceneId]);
            return;
        }

        // If already processed, don't redo
        if ($scene->status === 'done') {
            Log::info('ProcessPanorama: already done, skipping', ['scene_id' => $scene->id]);
            return;
        }

        $scene->update(['status' => 'processing']);

        $tempDir = null;

        try {
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

            /* -------------------------------------------------
             | IDENTIFIERS / PATHS
             ------------------------------------------------- */
            $sceneKey = $scene->scene_id ?: (string) $scene->id;
            $sceneKey = preg_replace('/[^A-Za-z0-9_\-]/', '_', $sceneKey);

            // Prefer municipal_slug if you have it, else municipal
            $municipalSlug = $scene->municipal_slug ?? $scene->municipal;
            $municipalSlug = trim((string) $municipalSlug);
            if ($municipalSlug === '') {
                throw new \RuntimeException('Missing municipal/municipal_slug on scene');
            }

            $basePath = "{$municipalSlug}/{$sceneKey}";

            $tempDir = storage_path("app/tmp_scenes/{$sceneKey}");
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

            /* -------------------------------------------------
             | DOWNLOAD ORIGINAL PANORAMA FROM S3
             ------------------------------------------------- */
            $parsed = parse_url((string) $scene->panorama_path);
            if (!isset($parsed['path'])) {
                throw new \RuntimeException('Invalid panorama_path (expected S3 URL with path)');
            }

            $s3Key = ltrim($parsed['path'], '/');
            $localPano = $tempDir . DIRECTORY_SEPARATOR . 'panorama.jpg';

            $client = Storage::disk('s3')->getDriver()->getAdapter()->getClient();
            $client->getObject([
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key'    => $s3Key,
                'SaveAs' => $localPano,
            ]);

            if (!file_exists($localPano) || filesize($localPano) === 0) {
                throw new \RuntimeException('Downloaded panorama is missing/empty');
            }

            /* -------------------------------------------------
             | KRPANO EXECUTION
             ------------------------------------------------- */
            $krpanoTool = $isWindows
                ? base_path('krpanotools/krpanotools.exe')
                : base_path('krpanotools/krpanotools');

            $configPath = base_path('krpanotools/templates/vtour-multires.config');

            if (!file_exists($krpanoTool)) {
                throw new \RuntimeException("krpano tool not found: {$krpanoTool}");
            }
            if (!file_exists($configPath)) {
                throw new \RuntimeException("krpano config not found: {$configPath}");
            }

            if ($isWindows) {
                $krpanoTool = str_replace('/', '\\', $krpanoTool);
                $configPath = str_replace('/', '\\', $configPath);
                $localPano  = str_replace('/', '\\', $localPano);
            }

            $process = new Process([
                $krpanoTool,
                'makepano',
                "-config={$configPath}",
                $localPano,
            ]);

            $process->setTimeout(1800); // 30 minutes
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
            }

            /* -------------------------------------------------
             | PARSE GENERATED tour.xml (SOURCE OF TRUTH)
             ------------------------------------------------- */
            $generatedTourXml = $tempDir . DIRECTORY_SEPARATOR . 'vtour' . DIRECTORY_SEPARATOR . 'tour.xml';
            if (!file_exists($generatedTourXml)) {
                throw new \RuntimeException('krpano tour.xml not found (vtour/tour.xml)');
            }

            $xml = simplexml_load_file($generatedTourXml);
            if (!$xml) {
                throw new \RuntimeException('Failed parsing krpano tour.xml');
            }

            $target = strtolower($sceneKey);
            $thumbRel = $previewRel = $cubeRel = $multires = '';

            foreach ($xml->scene as $sceneNode) {
                $name = strtolower((string) $sceneNode['name']);
                if ($name !== $target) continue;

                $thumbRel   = ltrim((string) ($sceneNode['thumburl'] ?? ''), '/');
                $previewRel = $sceneNode->preview ? ltrim((string) $sceneNode->preview['url'], '/') : '';

                if ($sceneNode->image && $sceneNode->image->cube) {
                    $cubeRel  = ltrim((string) $sceneNode->image->cube['url'], '/');
                    $multires = (string) $sceneNode->image->cube['multires'];
                }
                break;
            }

            if ($cubeRel === '') {
                throw new \RuntimeException('Cube URL missing in krpano output');
            }

            /* -------------------------------------------------
             | UPLOAD KRPANO OUTPUT (vtour/) TO S3
             ------------------------------------------------- */
            $vtourDir = $tempDir . DIRECTORY_SEPARATOR . 'vtour';
            if (!is_dir($vtourDir)) {
                throw new \RuntimeException('vtour folder not found after krpano processing');
            }

            $this->uploadFolderToS3($vtourDir, $basePath);

            /* -------------------------------------------------
             | BUILD FINAL URLs (PUBLIC)
             ------------------------------------------------- */
            $thumbUrl   = $thumbRel   ? Storage::disk('s3')->url("{$basePath}/{$thumbRel}")   : null;
            $previewUrl = $previewRel ? Storage::disk('s3')->url("{$basePath}/{$previewRel}") : null;

            // Keep cube url in krpano format. If you store absolute, it breaks %s/%0v placeholders.
            // So store as "https://bucket/.../panos/.../%s/..." by prefixing basePath.
            $cubeUrl = Storage::disk('s3')->url("{$basePath}/{$cubeRel}");

            /* -------------------------------------------------
             | APPEND TO MASTER TOUR.XML (ON S3 OR LOCAL)
             | If you keep master tour.xml on S3, update there.
             ------------------------------------------------- */

            // If your master tour.xml is on S3: "{$municipalSlug}/tour.xml"
            $masterKey = "{$municipalSlug}/tour.xml";

            if (!Storage::disk('s3')->exists($masterKey)) {
                throw new \RuntimeException("Master tour.xml not found on S3: {$masterKey}");
            }

            $masterXml = Storage::disk('s3')->get($masterKey);

            $title = htmlspecialchars((string) ($scene->title ?? ''), ENT_QUOTES);
            $sub   = htmlspecialchars((string) ($scene->location ?? ''), ENT_QUOTES);

            $newScene = "\n<scene name=\"{$sceneKey}\" title=\"{$title}\" subtitle=\"{$sub}\" thumburl=\"{$thumbRel}\">\n"
                . "    <view hlookat=\"0\" vlookat=\"0\" fov=\"90\" />\n"
                . "    <preview url=\"{$previewRel}\" />\n"
                . "    <image>\n"
                . "        <cube url=\"{$cubeRel}\" multires=\"{$multires}\" />\n"
                . "    </image>\n"
                . "</scene>\n";

            if (!str_contains($masterXml, "name=\"{$sceneKey}\"")) {
                $masterXml = str_replace('</krpano>', $newScene . '</krpano>', $masterXml);
                Storage::disk('s3')->put($masterKey, $masterXml);
            }

            /* -------------------------------------------------
             | UPDATE DB + CLEANUP
             ------------------------------------------------- */
            $scene->update([
                'status' => 'done',
                // Optional: save derived URLs if you have columns for them
                // 'thumb_url' => $thumbUrl,
                // 'preview_url' => $previewUrl,
                // 'cube_url' => $cubeUrl,
            ]);

            Log::info('✅ Panorama processed successfully', [
                'scene_id'  => $scene->id,
                'basePath'  => $basePath,
                'thumbRel'  => $thumbRel,
                'previewRel'=> $previewRel,
                'cubeRel'   => $cubeRel,
                'multires'  => $multires,
            ]);

        } catch (\Throwable $e) {
            Log::error('❌ Panorama processing failed', [
                'scene_id' => $scene->id,
                'error'    => $e->getMessage(),
            ]);

            $scene->update(['status' => 'failed']);

        } finally {
            if ($tempDir) {
                $this->deleteDir($tempDir);
            }
        }
    }

    private function uploadFolderToS3(string $localDir, string $s3Prefix): void
    {
        $localDir = rtrim($localDir, DIRECTORY_SEPARATOR);
        $s3Prefix = trim($s3Prefix, '/');

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($rii as $file) {
            if ($file->isDir()) continue;

            $fullPath = $file->getPathname();
            $relPath  = ltrim(str_replace($localDir, '', $fullPath), DIRECTORY_SEPARATOR);
            $relPath  = str_replace(DIRECTORY_SEPARATOR, '/', $relPath);

            $key = "{$s3Prefix}/{$relPath}";

            Storage::disk('s3')->put($key, fopen($fullPath, 'r'));
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

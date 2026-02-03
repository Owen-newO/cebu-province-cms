<?php

namespace App\Services;

use App\Models\Scene;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class ScenePipelineService
{
    /* =====================================================
     |  PUBLIC ENTRY POINTS (CALLED BY CONTROLLER / JOB)
     ===================================================== */

    public function processNewScene(
        Scene $scene,
        string $localPanoramaPath,
        string $municipalSlug,
        array $validated
    ): void {
        $sceneId = pathinfo($localPanoramaPath, PATHINFO_FILENAME);
        $tempDir = dirname($localPanoramaPath);
        $basePath = "{$municipalSlug}/{$sceneId}";

        // 1️⃣ Run krpano
        $this->runKrpano($localPanoramaPath);

        // 2️⃣ Read local krpano tour.xml
        $localTourXml = $tempDir . '/vtour/tour.xml';
        $config = $this->extractKrpanoSceneConfig($sceneId, $localTourXml);

        if ($config) {
            $thumb   = Storage::disk('s3')->url("{$basePath}/" . ltrim($config['thumb'], '/'));
            $preview = Storage::disk('s3')->url("{$basePath}/" . ltrim($config['preview'], '/'));
            $cubeUrl = Storage::disk('s3')->url("{$basePath}/" . ltrim($config['cube'], '/'));
            $multires = $config['multires'];
        } else {
            $tileBase = Storage::disk('s3')->url("{$basePath}/panos/{$sceneId}.tiles");
            $thumb    = "{$tileBase}/thumb.jpg";
            $preview  = "{$tileBase}/preview.jpg";
            $cubeUrl  = "{$tileBase}/%s/l%l/%0v_%0h.jpg";
            $multires = '512,1024,2048';
        }

        // 3️⃣ Upload vtour folder
        $this->uploadFolderToS3($tempDir . '/vtour', $basePath);

        // 4️⃣ Inject scene + ALL layers
        $this->appendSceneToXml(
            $sceneId,
            $validated,
            $thumb,
            $preview,
            $cubeUrl,
            $multires,
            $municipalSlug
        );
    }

    public function updateSceneMeta(Scene $scene, array $validated): void
    {
        $sceneId = pathinfo($scene->panorama_path, PATHINFO_FILENAME);
        $municipalSlug = $scene->municipal;

        $this->updateSceneMetaInXml($sceneId, $validated, $municipalSlug);
        $this->updateLayerMetaInXml($sceneId, $validated, $municipalSlug);
    }

    public function deleteScene(Scene $scene): void
    {
        $sceneId = pathinfo($scene->panorama_path, PATHINFO_FILENAME);
        $municipalSlug = $scene->municipal;

        $this->removeSceneFromXml($sceneId, $municipalSlug);
        $this->removeLayerFromXml($sceneId, $municipalSlug);
        $this->forceDeleteS3Directory("{$municipalSlug}/{$sceneId}");
    }

    /* =====================================================
     |  PRIVATE HELPERS (INTERNAL ONLY)
     ===================================================== */

    private function runKrpano(string $localPanorama): void
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        $exe = $isWindows
            ? base_path('krpanotools/krpanotools.exe')
            : base_path('krpanotools/krpanotools');

        $config = base_path('krpanotools/templates/vtour-multires.config');

        if ($isWindows) {
            $exe = str_replace('/', '\\', $exe);
            $config = str_replace('/', '\\', $config);
            $localPanorama = str_replace('/', '\\', $localPanorama);
        }

        chdir(base_path());

        $cmd = "\"{$exe}\" makepano -config=\"{$config}\" \"{$localPanorama}\"";

        exec($cmd . " 2>&1", $out, $status);

        if ($status !== 0) {
            throw new \Exception("KRPANO failed: " . json_encode($out));
        }
    }

    private function extractKrpanoSceneConfig(string $sceneId, string $tourXmlPath): ?array
    {
        if (!file_exists($tourXmlPath)) return null;

        $xml = @simplexml_load_file($tourXmlPath);
        if (!$xml) return null;

        $target = 'scene_' . strtolower($sceneId);

        foreach ($xml->scene as $scene) {
            if (strtolower((string)$scene['name']) !== $target) continue;

            return [
                'thumb'    => (string)$scene['thumburl'],
                'preview'  => (string)$scene->preview['url'],
                'cube'     => (string)$scene->image->cube['url'],
                'multires' => (string)$scene->image->cube['multires'],
            ];
        }

        return null;
    }

    private function uploadFolderToS3(string $localFolder, string $remoteFolder): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localFolder, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isDir()) continue;

            $key = $remoteFolder . '/' . substr($file->getPathname(), strlen($localFolder) + 1);
            $key = str_replace('\\', '/', $key);

            Storage::disk('s3')->put($key, file_get_contents($file->getPathname()));
        }
    }

    /* ================= XML HELPERS ================= */

    private function appendSceneToXml($sceneId, $validated, $thumb, $preview, $cubeUrl, $multires, $municipalSlug)
    {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if (!$xml) return;

        $sceneBlock = "
<scene name=\"scene_{$sceneId}\" title=\"{$validated['title']}\" subtitle=\"{$validated['location']}\" thumburl=\"{$thumb}\">
  <preview url=\"{$preview}\" />
  <image>
    <cube url=\"{$cubeUrl}\" multires=\"{$multires}\" />
  </image>
</scene>
";

        $xml = str_replace('</krpano>', $sceneBlock . "\n</krpano>", $xml);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        // ALL your existing layer injections
        $this->appendLayerToXml($sceneId, $validated['title'], $validated['barangay'] ?? '', $thumb, $municipalSlug);
        $this->appendMapToSideMapLayerXml($validated['google_map_link'] ?? null, $validated['title'], $sceneId, $municipalSlug);
        $this->appendTitle($validated['title'], $sceneId, $municipalSlug);
        $this->appendBarangayInsideForBarangay($validated['barangay'] ?? '', $validated['title'], $sceneId, $municipalSlug);
        $this->appendCategoryInsideForCat($validated['category'] ?? '', $validated['title'], $sceneId, $municipalSlug);
        $this->appenddetailsInsidescrollarea5($validated['address'] ?? '', $validated['title'], $sceneId, $municipalSlug);
        $this->appendcontactnumber($validated['contact_number'] ?? '', $validated['title'], $sceneId, $municipalSlug);
        $this->appendemail($validated['email'] ?? '', $validated['title'], $sceneId, $municipalSlug);
        $this->appendwebsite($validated['website'] ?? '', $validated['title'], $sceneId, $municipalSlug);
        $this->appendfacebook($validated['facebook'] ?? '', $validated['title'], $sceneId, $municipalSlug);
        $this->appendinstagram($validated['instagram'] ?? '', $validated['title'], $sceneId, $municipalSlug);
        $this->appendtiktok($validated['tiktok'] ?? '', $validated['title'], $sceneId, $municipalSlug);
    }

    /* ================= S3 XML LOAD/SAVE ================= */

    private function loadTourXmlFromS3(string $municipalSlug): ?string
    {
        $key = "{$municipalSlug}/tour.xml";
        return Storage::disk('s3')->exists($key)
            ? Storage::disk('s3')->get($key)
            : null;
    }

    private function saveTourXmlToS3(string $municipalSlug, string $xml): void
    {
        Storage::disk('s3')->put("{$municipalSlug}/tour.xml", $xml);
    }

    /* ================= DELETE HELPERS ================= */

    private function removeSceneFromXml($sceneId, string $municipalSlug)
    {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if (!$xml) return;

        $xml = preg_replace('/<scene[^>]*scene_' . preg_quote($sceneId, '/') . '.*?<\/scene>/is', '', $xml);
        $this->saveTourXmlToS3($municipalSlug, $xml);
    }

    private function removeLayerFromXml($sceneId, string $municipalSlug)
    {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if (!$xml) return;

        $xml = preg_replace('/<layer[^>]*linkedscene="scene_' . preg_quote($sceneId, '/') . '".*?<\/layer>/is', '', $xml);
        $this->saveTourXmlToS3($municipalSlug, $xml);
    }

    private function forceDeleteS3Directory(string $prefix)
    {
        $files = Storage::disk('s3')->listContents($prefix, true);
        foreach ($files as $file) {
            if ($file['type'] === 'file') {
                Storage::disk('s3')->delete($file['path']);
            }
        }
        Storage::disk('s3')->deleteDirectory($prefix);
    }
}

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
     |  PUBLIC ENTRY POINTS
     ===================================================== */

    public function processNewScene(
        Scene $scene,
        string $localPanoramaPath,
        string $municipalSlug,
        array $validated
    ): void {
        $sceneId  = pathinfo($localPanoramaPath, PATHINFO_FILENAME);
        $tempDir = dirname($localPanoramaPath);
        $basePath = "{$municipalSlug}/{$sceneId}";

        Log::info('🚀 Scene pipeline started', [
            'scene_id' => $scene->id,
            'scene_uid' => $sceneId,
            'local_pano' => $localPanoramaPath,
        ]);

        /* ================= 1️⃣ RUN KRPANO ================= */

        $this->runKrpano($localPanoramaPath);

        $vtourPath = $tempDir . '/vtour';
        $tourXmlPath = $vtourPath . '/tour.xml';

        if (!is_dir($vtourPath)) {
            throw new \Exception('❌ KRPANO did not generate vtour directory');
        }

        if (!file_exists($tourXmlPath)) {
            throw new \Exception('❌ KRPANO did not generate tour.xml');
        }
        
       
        /* ================= 2️⃣ UPLOAD VT0UR TO S3 ================= */

        $this->uploadFolderToS3($vtourPath, $basePath);

        /* ================= 3️⃣  READ KRPANO CONFIG ================= */

        $config = $this->getStaticKrpanoConfig($basePath, $sceneId);

        $thumb    = $config['thumb'];
        $preview  = $config['preview'];
        $cubeUrl  = $config['cube'];
        $multires = $config['multires'];

        /* ================= 4️⃣ INJECT SCENE + LAYERS ================= */

        $this->appendSceneToXml(
            $sceneId,
            $validated,
            $thumb,
            $preview,
            $cubeUrl,
            $multires,
            $municipalSlug
        );

        Log::info('✅ Scene pipeline finished', [
            'scene_uid' => $sceneId,
        ]);
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
     |  PRIVATE HELPERS
     ===================================================== */

    private function runKrpano(string $localPanorama): void
{
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

    $exe = $isWindows
        ? base_path('krpanotools/krpanotools.exe')
        : base_path('krpanotools/krpanotools');

    $config = base_path('krpanotools/templates/vtour-multires.config');

    // 🔑 CRITICAL: run krpano beside the panorama
    chdir(dirname($localPanorama));

    $cmd = "\"{$exe}\" makepano -config=\"{$config}\" \"{$localPanorama}\"";

    exec($cmd . " 2>&1", $out, $status);

    Log::info('🧩 KRPANO EXECUTION', [
        'cmd'    => $cmd,
        'cwd'    => getcwd(),
        'status' => $status,
        'output' => $out,
    ]);

    if ($status !== 0) {
        throw new \Exception("❌ KRPANO failed: " . json_encode($out));
    }
}
private function getStaticKrpanoConfig(string $basePath, string $sceneId): array
{
    $tileBase = Storage::disk('s3')->url("{$basePath}/panos/{$sceneId}.tiles");

    return [
        'thumb'    => "{$tileBase}/thumb.jpg",
        'preview'  => "{$tileBase}/preview.jpg",
        'cube'     => "{$tileBase}/%s/l%l/%0v/l%l_%s_%0v_%0h.jpg",
        'multires' => '512,640,1152,2304,4736',
    ];
}
    private function uploadFolderToS3(string $localFolder, string $remoteFolder): void
    {
        Log::info('⬆️ Uploading vtour to S3', [
            'local' => $localFolder,
            'remote' => $remoteFolder,
        ]);

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

    private function appendSceneToXml(
        $sceneId,
        $validated,
        $thumb,
        $preview,
        $cubeUrl,
        $multires,
        $municipalSlug
    ) {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if (!$xml) {
            throw new \Exception('❌ Main tour.xml missing in S3');
        }

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

        // 🔥 YOUR EXISTING LAYER INJECTIONS
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

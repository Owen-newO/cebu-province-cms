<?php

namespace App\Jobs;

use App\Models\Scene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPanorama implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $sceneId;

    public function __construct($sceneId)
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

            // ✅ Paths
            $krpanoTool = $isWindows
                ? base_path('krpanotools/krpanotools.exe')
                : base_path('krpanotools/krpanotools');

            $configPath = base_path('krpanotools/templates/vtour-multires.config');
            $imagePath  = public_path($scene->panorama_path);
            $sceneId    = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($imagePath, PATHINFO_FILENAME));
            $outputDir  = dirname($imagePath);

            if ($isWindows) {
                $krpanoTool = str_replace('/', '\\', $krpanoTool);
                $configPath = str_replace('/', '\\', $configPath);
                $imagePath  = str_replace('/', '\\', $imagePath);
                $outputDir  = str_replace('/', '\\', $outputDir);
            }

            if (!file_exists($outputDir)) mkdir($outputDir, 0775, true);
            chdir(base_path());

            $cmd = "\"{$krpanoTool}\" makepano -config=\"{$configPath}\" \"{$imagePath}\"";
            exec($cmd . ' 2>&1', $output, $status);
            Log::info('krpano command executed', ['cmd' => $cmd, 'output' => $output, 'status' => $status]);

            if ($status !== 0) {
                throw new \Exception('krpano tiling failed');
            }

            // ✅ Append new <scene> entry to tour.xml
            $tourXml = public_path('vtour/tour.xml');

            $sceneTitle = htmlspecialchars($scene->title ?? '', ENT_QUOTES);
            $scenesubTitle = htmlspecialchars($scene->location ?? '', ENT_QUOTES);
            $sceneId = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($scene->panorama_path, PATHINFO_FILENAME));

            // ✅ Build URLs
            $cubeUrl = "panos/{$sceneId}/vtour/panos/{$sceneId}.tiles/%s/l%l/%0v_%0h.jpg";
            $multires = "512,1024,2048";

            // ✅ Construct your desired scene
            $newScene = "
            <scene name=\"{$sceneId}\" title=\"{$sceneTitle}\" subtitle=\"{$scenesubTitle}\" onstart=\"\" places=\"{$sceneTitle}\" thumburl=\"panos/{$sceneId}/vtour/panos/{$sceneId}.tiles/thumb.jpg\">
            <view hlookat=\"0\" vlookat=\"0\" fov=\"90\" />
            <preview url=\"panos/{$sceneId}/vtour/panos/{$sceneId}.tiles/preview.jpg\" />
            <image>
                <cube url=\"{$cubeUrl}\" multires=\"{$multires}\" />
            </image>
            </scene>\n";

            // ✅ Insert before </krpano> in master tour.xml        
            if (file_exists($tourXml)) {
                $xmlContent = file_get_contents($tourXml);
                $xmlContent = str_replace('</krpano>', $newScene . '</krpano>', $xmlContent);
                file_put_contents($tourXml, $xmlContent);
                Log::info("✅ Scene appended to main tour.xml: {$sceneId}");
            } else {
                Log::error("❌ Main tour.xml not found at {$tourXml}");
            }

            file_put_contents($tourXml, $xmlContent);

            Log::info("Scene appended to tour.xml successfully: {$sceneId}", [
                'cubeUrl' => $cubeUrl,
                'multires' => $multires
            ]);

            $scene->update(['status' => 'done']);
            Log::info("✅ Finished processing scene {$scene->id}");
        } catch (\Exception $e) {
            Log::error('❌ Error during krpano processing: ' . $e->getMessage());
            $scene->update(['status' => 'failed']);
        }
    }
}

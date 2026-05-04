<?php

namespace App\Jobs;

use App\Models\Scene;
use App\Services\ScenePipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSceneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ DECLARE PROPERTIES
    public int $sceneId;
    public string $localPanoramaPath;
    public string $municipalSlug;
    public array $validated;

    // ✅ ASSIGN THEM IN CONSTRUCTOR
    public function __construct(
        int $sceneId,
        string $localPanoramaPath,
        string $municipalSlug,
        array $validated
    ) {
        $this->sceneId = $sceneId;
        $this->localPanoramaPath = $localPanoramaPath;
        $this->municipalSlug = $municipalSlug;
        $this->validated = $validated;
    }

    public function handle(ScenePipelineService $pipeline): void
    {
        $scene = Scene::findOrFail($this->sceneId);

        $scene->update(['status' => 'processing']);

        $pipeline->processNewScene(
            $scene,
            $this->localPanoramaPath,
            $this->municipalSlug,
            $this->validated
        );

        $scene->update(['status' => 'done']);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Scene;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class SceneController extends Controller
{
    // -----------------------------------------------------------
    // MUNICIPAL → slug
    // -----------------------------------------------------------
    private function municipalSlug($municipal)
    {
        return strtolower(trim(str_replace([' ', '/', '\\'], '_', $municipal)));
    }

    private function extractIframeSrc($iframeHtml)
    {
        if (!$iframeHtml) return null;

        // If user pasted a full iframe, extract only src=""
        if (preg_match('/src=["\']([^"\']+)["\']/', $iframeHtml, $match)) {
            return $match[1];
        }

        // If no iframe detected, return raw input (normal URL)
        return $iframeHtml;
    }

    // -----------------------------------------------------------
    // Helpers for tour.xml on S3
    // -----------------------------------------------------------
    private function getTourXmlPath(string $municipalSlug): string
    {
        // S3 key: cebu/{municipalSlug}/tour.xml
        return "{$municipalSlug}/tour.xml";
    }

    private function loadTourXmlFromS3(string $municipalSlug): ?string
    {
        $path = $this->getTourXmlPath($municipalSlug);
        $disk = Storage::disk('s3');

        if (!$disk->exists($path)) {
            Log::error('❌ tour.xml not found on S3', [
                's3_key'        => $path,
                'municipalSlug' => $municipalSlug,
            ]);
            return null;
        }

        try {
            return $disk->get($path);
        } catch (\Throwable $e) {
            Log::error('❌ Failed reading tour.xml from S3', [
                's3_key'        => $path,
                'municipalSlug' => $municipalSlug,
                'error'         => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function saveTourXmlToS3(string $municipalSlug, string $xml): void
    {
        $path = $this->getTourXmlPath($municipalSlug);
        $disk = Storage::disk('s3');

        try {
            $disk->put($path, $xml);
            Log::info('✅ tour.xml updated on S3', [
                's3_key'        => $path,
                'municipalSlug' => $municipalSlug,
            ]);
        } catch (\Throwable $e) {
            Log::error('❌ Failed writing tour.xml to S3', [
                's3_key'        => $path,
                'municipalSlug' => $municipalSlug,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------
    // STORE
    // -----------------------------------------------------------
    public function store(Request $request)
    {
        $validated = $this->validateScene($request);
        $validated['google_map_link'] = $this->extractIframeSrc($request->google_map_link);
        $validated['contact_number']  = $request->contact_number;
        $validated['email']           = $request->email;
        $validated['website']         = $request->website;
        $validated['facebook']        = $request->facebook;
        $validated['instagram']       = $request->instagram;
        $validated['tiktok']          = $request->tiktok;
        $validated['is_published']    = $validated['is_published'] === "true" ? 1 : 0;

        $file = $request->file('panorama');
        $filename = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());

        $sceneId       = pathinfo($filename, PATHINFO_FILENAME);
        $municipalSlug = $this->municipalSlug($validated['municipal']);

        // S3 base path: {municipal}/{sceneId}
        $basePath = "{$municipalSlug}/{$sceneId}";

        // TEMP FOLDER (local krpano workspace)
        $tempDir = storage_path("app/tmp_scenes/{$sceneId}");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $tempPanorama = $tempDir . DIRECTORY_SEPARATOR . $filename;
        $file->move($tempDir, $filename);

        Log::info('🎞️ Scene upload started', [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
            'tempDir'       => $tempDir,
            'tempPanorama'  => $tempPanorama,
            's3_base_path'  => $basePath,
        ]);

        // ✅ Upload original panorama to S3 (so panorama_path actually exists there)
        $originalKey = "{$basePath}/{$filename}";
        try {
            Storage::disk('s3')->put($originalKey, file_get_contents($tempPanorama));
            Log::info('✅ Original panorama uploaded to S3', [
                'key' => $originalKey,
            ]);
        } catch (\Throwable $e) {
            Log::error('❌ Failed uploading original panorama to S3', [
                'key'   => $originalKey,
                'error' => $e->getMessage(),
            ]);
        }

        // DB reference (points to S3 path)
        $validated['panorama_path'] = Storage::disk('s3')->url($originalKey);
        $scene = Scene::create($validated);

        // RUN KRPANO (this MUST create {$tempDir}/vtour/...)
        $this->runKrpano($tempPanorama);

        // 🔍 Read krpano-generated local tour.xml to get real thumb/preview/cube/multires
        $localTourXml = $tempDir . DIRECTORY_SEPARATOR . 'vtour' . DIRECTORY_SEPARATOR . 'tour.xml';
        $config = $this->extractKrpanoSceneConfig($sceneId, $localTourXml);

        if ($config) {
            // krpano paths are relative, e.g. "panos/1763...tiles/preview.jpg"
            $thumbRel   = ltrim($config['thumb']   ?? '', '/');
            $previewRel = ltrim($config['preview'] ?? '', '/');
            $cubeRel    = ltrim($config['cube']    ?? '', '/');
            $multires   = $config['multires']      ?? '';

            // Final S3 URLs: https://.../{municipal}/{sceneId}/{krpano-relative-path}
            $thumb   = Storage::disk('s3')->url("{$basePath}/{$thumbRel}");
            $preview = Storage::disk('s3')->url("{$basePath}/{$previewRel}");
            $cubeUrl = Storage::disk('s3')->url("{$basePath}/{$cubeRel}");
        } else {
            // Fallback if parsing fails
            $tileBase = Storage::disk('s3')->url("{$basePath}/panos/{$sceneId}.tiles");
            $thumb    = "{$tileBase}/thumb.jpg";
            $preview  = "{$tileBase}/preview.jpg";
            $cubeUrl  = "{$tileBase}/%s/l%l/%0v_%0h.jpg";
            $multires = '512,1024,2048';
        }

        Log::info('🧩 Computed S3 URLs for scene', [
            'sceneId'  => $sceneId,
            'thumb'    => $thumb ?? null,
            'preview'  => $preview ?? null,
            'cubeUrl'  => $cubeUrl ?? null,
            'multires' => $multires ?? null,
        ]);

        // UPLOAD KRPANO OUTPUT: local: {tempDir}/vtour → S3: {basePath}/...
        $this->uploadFolderToS3($tempDir . DIRECTORY_SEPARATOR . 'vtour', $basePath);

        // CLEAN TEMP
        $this->deleteLocalFolder($tempDir);

        // UPDATE tour.xml + layer (use krpano's real cube url + multires) — MUNICIPAL-AWARE (on S3)
        $this->appendSceneToXml($sceneId, $validated, $thumb, $preview, $cubeUrl, $multires, $municipalSlug);

        return redirect()->route('Dashboard')->with('success', 'Scene uploaded!');
    }

    // -----------------------------------------------------------
    // INDEX
    // -----------------------------------------------------------
    public function index()
    {
        return Scene::latest()->get();
    }

    // -----------------------------------------------------------
    // UPDATE
    // -----------------------------------------------------------
    public function update(Request $request, $id)
    {
        $scene = Scene::findOrFail($id);
        $validated = $this->validateScene($request, updating: true);
        $validated['google_map_link'] = $this->extractIframeSrc($request->google_map_link);
        $validated['contact_number']  = $request->contact_number;
        $validated['email']           = $request->email;
        $validated['website']         = $request->website;
        $validated['facebook']        = $request->facebook;
        $validated['instagram']       = $request->instagram;
        $validated['tiktok']          = $request->tiktok;
        $validated['is_published']    = $validated['is_published'] === "true" ? 1 : 0;

        // If no new panorama uploaded → just update meta + xml layer/scene (on S3)
        if (!$request->hasFile('panorama')) {
            $scene->update($validated);

            // derive sceneId from existing panorama_path (S3 URL)
            $path = parse_url($scene->panorama_path, PHP_URL_PATH) ?? $scene->panorama_path;
            $sceneId = pathinfo($path, PATHINFO_FILENAME);

            // Use the MUNICIPAL of the existing scene for XML
            $municipalSlug = $this->municipalSlug($scene->municipal);

            try {
                $this->updateSceneMetaInXml($sceneId, $validated, $municipalSlug);
                $this->updateLayerMetaInXml($sceneId, $validated, $municipalSlug);
                Log::info('✏️ Updated scene + layer meta in tour.xml on S3 (no retiling)', [
                    'sceneId'       => $sceneId,
                    'municipalSlug' => $municipalSlug,
                ]);
            } catch (\Throwable $e) {
                Log::error('❌ Failed to update tour.xml meta on scene update', [
                    'sceneId'       => $sceneId,
                    'municipalSlug' => $municipalSlug,
                    'error'         => $e->getMessage(),
                ]);
            }

            return redirect()->route('Dashboard')->with('success', 'Scene updated.');
        }

        $oldSceneId       = pathinfo($scene->panorama_path, PATHINFO_FILENAME);
        $oldMunicipal     = $scene->municipal;
        $oldMunicipalSlug = $this->municipalSlug($oldMunicipal);

        // DELETE OLD S3 DIRECTORY
        Storage::disk('s3')->deleteDirectory("{$oldMunicipalSlug}/{$oldSceneId}");
        Log::info('🗑 Deleted old S3 directory for scene update', [
            'sceneId'       => $oldSceneId,
            'municipalSlug' => $oldMunicipalSlug,
        ]);

        // NEW FILE
        $file       = $request->file('panorama');
        $filename   = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
        $newSceneId = pathinfo($filename, PATHINFO_FILENAME);

        // NEW municipalSlug (could be same or different)
        $municipalSlug = $this->municipalSlug($validated['municipal']);
        $basePath      = "{$municipalSlug}/{$newSceneId}";

        // TEMP DIR
        $tempDir = storage_path("app/tmp_scenes/{$newSceneId}");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $tempPanorama = $tempDir . DIRECTORY_SEPARATOR . $filename;
        $file->move($tempDir, $filename);

        Log::info('♻️ Scene update with new panorama started', [
            'oldSceneId'   => $oldSceneId,
            'newSceneId'   => $newSceneId,
            'tempDir'      => $tempDir,
            'tempPanorama' => $tempPanorama,
            's3_base_path' => $basePath,
        ]);

        // ✅ Upload new original panorama to S3
        $originalKey = "{$basePath}/{$filename}";
        try {
            Storage::disk('s3')->put($originalKey, file_get_contents($tempPanorama));
            Log::info('✅ New original panorama uploaded to S3', [
                'key' => $originalKey,
            ]);
        } catch (\Throwable $e) {
            Log::error('❌ Failed uploading new original panorama to S3', [
                'key'   => $originalKey,
                'error' => $e->getMessage(),
            ]);
        }

        // UPDATE DB path
        $validated['panorama_path'] = Storage::disk('s3')->url($originalKey);

        // RUN KRPANO
        $this->runKrpano($tempPanorama);

        // 🔍 Read krpano-generated local tour.xml to get real thumb/preview/cube/multires
        $localTourXml = $tempDir . DIRECTORY_SEPARATOR . 'vtour' . DIRECTORY_SEPARATOR . 'tour.xml';
        $config = $this->extractKrpanoSceneConfig($newSceneId, $localTourXml);

        if ($config) {
            $thumbRel   = ltrim($config['thumb']   ?? '', '/');
            $previewRel = ltrim($config['preview'] ?? '', '/');
            $cubeRel    = ltrim($config['cube']    ?? '', '/');
            $multires   = $config['multires']      ?? '';

            $thumb   = Storage::disk('s3')->url("{$basePath}/{$thumbRel}");
            $preview = Storage::disk('s3')->url("{$basePath}/{$previewRel}");
            $cubeUrl = Storage::disk('s3')->url("{$basePath}/{$cubeRel}");
        } else {
            $tileBase = Storage::disk('s3')->url("{$basePath}/panos/{$newSceneId}.tiles");
            $thumb    = "{$tileBase}/thumb.jpg";
            $preview  = "{$tileBase}/preview.jpg";
            $cubeUrl  = "{$tileBase}/%s/l%l/%0v_%0h.jpg";
            $multires = '512,1024,2048';
        }

        Log::info('🧩 Computed S3 URLs for updated scene', [
            'sceneId'  => $newSceneId,
            'thumb'    => $thumb ?? null,
            'preview'  => $preview ?? null,
            'cubeUrl'  => $cubeUrl ?? null,
            'multires' => $multires ?? null,
        ]);

        // UPLOAD NEW OUTPUT
        $this->uploadFolderToS3($tempDir . DIRECTORY_SEPARATOR . 'vtour', $basePath);

        // CLEANUP LOCAL
        $this->deleteLocalFolder($tempDir);

        // REMOVE OLD XML + LAYER from OLD MUNICIPAL (on S3)
        $this->removeSceneFromXml($oldSceneId, $oldMunicipalSlug);
        $this->removeLayerFromXml($oldSceneId, $oldMunicipalSlug);

        // INSERT NEW XML BLOCK + LAYER (with real cube + multires) in NEW MUNICIPAL (on S3)
        $this->appendSceneToXml($newSceneId, $validated, $thumb, $preview, $cubeUrl, $multires, $municipalSlug);

        $scene->update($validated);

        return redirect()->route('Dashboard')->with('success', 'Scene updated.');
    }

    // -----------------------------------------------------------
    // DESTROY
    // -----------------------------------------------------------
    public function destroy($id)
    {
        try {
            $scene = Scene::findOrFail($id);

            $path    = parse_url($scene->panorama_path, PHP_URL_PATH) ?: $scene->panorama_path;
            $sceneId = pathinfo($path, PATHINFO_FILENAME);
            $municipalSlug = $this->municipalSlug($scene->municipal);

            $folderPrefix = "{$municipalSlug}/{$sceneId}";

            Log::info('🗑 Deleting scene', [
                'sceneId'      => $sceneId,
                'folderPrefix' => $folderPrefix,
            ]);

            // REMOVE XML + LAYER from this municipal tour.xml (on S3)
            $this->removeSceneFromXml($sceneId, $municipalSlug);
            $this->removeLayerFromXml($sceneId, $municipalSlug);

            // DELETE ALL S3 FILES & FOLDERS
            $this->forceDeleteS3Directory($folderPrefix);

            // DELETE DB
            $scene->delete();

            return redirect()->route('Dashboard')->with('success', 'Scene deleted.');
        } catch (\Exception $e) {
            Log::error("❌ DELETE FAILED: " . $e->getMessage());
            return back()->with('error', 'Failed to delete scene!');
        }
    }

    private function forceDeleteS3Directory(string $prefix)
    {
        $s3 = Storage::disk('s3');

        // Normalize prefix (remove accidental double slashes)
        $prefix = trim($prefix, '/');

        // List ALL objects under folder
        $objects = $s3->listContents($prefix, true);

        foreach ($objects as $file) {
            if ($file['type'] === 'file') {
                $s3->delete($file['path']);
            }
        }

        // Finally remove folder itself
        $s3->deleteDirectory($prefix);

        Log::info("🧹 FORCE DELETED S3 directory", ['prefix' => $prefix]);
    }

    // =====================================================================
    // VALIDATION
    // =====================================================================
    private function validateScene($request, $updating = false)
    {
        return $request->validate([
            'title'           => 'required|string|max:255',
            'municipal'       => 'required|string|max:255',
            'location'        => 'nullable|string|max:255',
            'barangay'        => 'nullable|string|max:255',
            'category'        => 'nullable|string|max:255',
            'address'         => 'nullable|string|max:255',
            'google_map_link' => 'nullable|string',
            'contact_number'  => 'nullable|string',
            'email'           => 'nullable|string|max:255',
            'website'         => 'nullable|string|max:255',
            'facebook'        => 'nullable|string|max:255',
            'instagram'       => 'nullable|string|max:255',
            'tiktok'          => 'nullable|string|max:255',
            'is_published'    => 'required',
            'panorama'        =>
                $updating
                    ? 'nullable|file|mimes:jpg,jpeg'
                    : 'required|file|mimes:jpg,jpeg',
        ]);
    }

    // =====================================================================
    // KRPANO EXECUTION
    // =====================================================================
    private function runKrpano($localPanorama)
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        $exe = $isWindows
            ? base_path('krpanotools/krpanotools.exe')
            : base_path('krpanotools/krpanotools');

        $config = base_path('krpanotools/templates/vtour-multires.config');

        if ($isWindows) {
            $exe           = str_replace('/', '\\', $exe);
            $config        = str_replace('/', '\\', $config);
            $localPanorama = str_replace('/', '\\', $localPanorama);
        }

        chdir(base_path());

         $cmd = "\"{$exe}\" makepano -config=\"{$config}\" \"{$localPanorama}\"  -license=\"vUYqPAACoXher8ChuuTQitL9LBF7pkWALVRziNeYXDTHTLnxubIQxl6aXGAS
        DyYG6aFZvHTAvSdbFHnYDzY4nsbBRLABJUAhdnQPqdzK39qSE1kity/Yvg1O
        owESykbliDlqeWwUfkh7VsqI36JpNTTWi9IS1y3NPaZjDQLPFjx+OG/9vkIN
        yTBcQHcwp32mu5rkVtbDaWAG8D2j9Eh4FxHtOWjAXOd7dYut3lbLjQWARy3N
        /hI5IdM+4xn7PVWufGNtfgS+xHbg9LSDyR+uV+ZnevMlCY5LC99IBAHNXd2R
        y3sH4qOFjaehW7Y=\"";
        

        $out = [];
        $status = 0;
        exec($cmd . " 2>&1", $out, $status);

        Log::info('🛠️ KRPANO command executed', [
            'cmd'    => $cmd,
            'status' => $status,
            'output' => $out,
        ]);

        if ($status !== 0) {
            throw new \Exception("KRPANO failed: " . json_encode($out));
        }
    }

    // =====================================================================
    // READ KRPANO-GENERATED SCENE CONFIG FROM LOCAL tour.xml
    // =====================================================================
    private function extractKrpanoSceneConfig(string $sceneId, string $tourXmlPath): ?array
    {
        if (!file_exists($tourXmlPath)) {
            Log::error('❌ Local krpano tour.xml not found', ['path' => $tourXmlPath]);
            return null;
        }

        $xml = @simplexml_load_file($tourXmlPath);
        if ($xml === false) {
            Log::error('❌ Failed to parse local krpano tour.xml', ['path' => $tourXmlPath]);
            return null;
        }

        // krpano scene name is usually lowercased version: scene_{sceneIdLower}
        $targetNameLower = 'scene_' . strtolower($sceneId);

        foreach ($xml->scene as $sceneNode) {
            $nameAttr = (string) $sceneNode['name'];
            if (strtolower($nameAttr) !== $targetNameLower) {
                continue;
            }

            $thumb    = (string) ($sceneNode['thumburl'] ?? '');
            $preview  = $sceneNode->preview ? (string) $sceneNode->preview['url'] : '';
            $cubeUrl  = '';
            $multires = '';

            if ($sceneNode->image && $sceneNode->image->cube) {
                $cubeUrl  = (string) $sceneNode->image->cube['url'];
                $multires = (string) $sceneNode->image->cube['multires'];
            }

            Log::info('🔎 Extracted krpano scene config from local tour.xml', [
                'sceneId'  => $sceneId,
                'nameAttr' => $nameAttr,
                'thumb'    => $thumb,
                'preview'  => $preview,
                'cubeUrl'  => $cubeUrl,
                'multires' => $multires,
            ]);

            return [
                'thumb'    => $thumb,
                'preview'  => $preview,
                'cube'     => $cubeUrl,
                'multires' => $multires,
            ];
        }

        Log::warning('⚠️ Target scene not found in local krpano tour.xml', [
            'sceneId' => $sceneId,
            'path'    => $tourXmlPath,
        ]);

        return null;
    }

    // =====================================================================
    // UPLOAD FOLDER TO S3
    // =====================================================================
    private function uploadFolderToS3($localFolder, $remoteFolder)
    {
        if (!is_dir($localFolder)) {
            Log::error('❌ S3 upload aborted, local folder not found', [
                'localFolder'  => $localFolder,
                'remoteFolder' => $remoteFolder,
            ]);
            return;
        }

        Log::info('📤 Starting S3 upload of folder', [
            'localFolder'  => $localFolder,
            'remoteFolder' => $remoteFolder,
        ]);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localFolder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            $skipExt = ['exe', 'bin', 'sh', 'bat', 'cmd', 'dll', 'macos', 'dat'];

            if (in_array($ext, $skipExt)) {
                Log::info('⏭ Skipped non-uploadable file', [
                    'file' => $file->getFilename(),
                    'ext'  => $ext,
                ]);
                continue;
            }

            $fullPath = $file->getPathname();
            $relative = substr($fullPath, strlen($localFolder) + 1);
            $relative = str_replace('\\', '/', $relative);

            $key = trim($remoteFolder . '/' . $relative, '/');

            Log::info('📦 Preparing to upload file to S3', [
                'key'        => $key,
                'local'      => $fullPath,
                'ext'        => $ext,
                'size_bytes' => filesize($fullPath),
            ]);

            try {
                $result = Storage::disk('s3')->put(
                    $key,
                    file_get_contents($fullPath)
                );

                if ($result) {
                    Log::info('✅ Uploaded file to S3', [
                        'key'   => $key,
                        'local' => $fullPath,
                    ]);
                } else {
                    Log::warning('⚠️ Storage::put returned false (no exception thrown)', [
                        'key'        => $key,
                        'local'      => $fullPath,
                        'ext'        => $ext,
                        'size_bytes' => filesize($fullPath),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('❌ Failed uploading file to S3 (exception)', [
                    'key'   => $key,
                    'local' => $fullPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // =====================================================================
    // DELETE TEMP LOCAL FOLDER
    // =====================================================================
    private function deleteLocalFolder($folder)
    {
        if (!is_dir($folder)) {
            Log::warning('⚠️ Tried to delete non-existing temp folder', ['folder' => $folder]);
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            $path = $f->getPathname();
            $f->isDir() ? rmdir($path) : unlink($path);
        }

        rmdir($folder);

        Log::info('🧹 Deleted temp folder', ['folder' => $folder]);
    }

    // =====================================================================
    // APPEND SCENE TO XML  (MUNICIPAL-AWARE, S3)
    // =====================================================================
    private function appendSceneToXml($sceneId, $validated, $thumb, $preview, $cubeUrl, $multires, $municipalSlug)
    {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) {
            return;
        }

        $title    = htmlspecialchars($validated['title'], ENT_QUOTES);
        $subtitle = htmlspecialchars($validated['location'], ENT_QUOTES);

        $newScene = "
<scene name=\"scene_{$sceneId}\" title=\"{$title}\" subtitle=\"{$subtitle}\" onstart=\"filterLayersByPlace\" places=\"{$title}\" thumburl=\"{$thumb}\">
  <view hlookat=\"0\" vlookat=\"0\" fovtype=\"MFOV\" fov=\"120\" maxpixelzoom=\"2.0\" fovmin=\"70\" fovmax=\"140\" limitview=\"auto\" />
  <preview url=\"{$preview}\" />
  <image>
    <cube url=\"{$cubeUrl}\" multires=\"{$multires}\" />
  </image>
</scene>\n";

        if (strpos($xml, '</krpano>') === false) {
            Log::error('❌ Invalid tour.xml: missing </krpano> tag', [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $xml = str_replace('</krpano>', $newScene . '</krpano>', $xml);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info('🧩 Scene appended to tour.xml (S3)', [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);

        // LAYER + META INJECTIONS (municipality-aware on S3)
        $this->appendLayerToXml($sceneId, $validated['title'], $validated['barangay'] ?? '', $thumb, $municipalSlug);
        $this->appendMapToSideMapLayerXml(
            $validated['google_map_link'] ?? null,
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appendTitle(
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appendBarangayInsideForBarangay(
            $validated['barangay'] ?? '',
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appendCategoryInsideForCat(
            $validated['category'] ?? '',
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appenddetailsInsidescrollarea5(
            $validated['address'] ?? '',
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appendcontactnumber(
            $validated['contact_number'] ?? '',
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appendemail(
            $validated['email'] ?? '',
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appendwebsite(
            $validated['website'] ?? '',
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appendfacebook(
            $validated['facebook'] ?? '',
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appendinstagram(
            $validated['instagram'] ?? '',
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );

        $this->appendtiktok(
            $validated['tiktok'] ?? '',
            $validated['title'] ?? '',
            $sceneId,
            $municipalSlug
        );
    }

    // =====================================================================
    // LAYER INJECTION SA THUMBS (MUNICIPAL-AWARE, S3)
    // =====================================================================
    private function appendLayerToXml($sceneId, $sceneTitle, $barangay, $thumb, $municipalSlug)
    {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $text      = strtoupper(str_replace('_', ' ', $sceneTitle));
        $safeTitle = htmlspecialchars($sceneTitle, ENT_QUOTES);

        $layer = "
<layer name=\"{$safeTitle}\" 
    url=\"{$thumb}\" 
    width.desktop=\"99%\" width.mobile=\"99%\" width.tablet=\"320\" height=\"prop\" 
    bgcolor=\"0xffffff\" bgroundedge=\"35\" alpha=\"1\" bgalpha=\"1\" flowspacing=\"5\" 
    keep=\"true\" scale=\".495\" isFilterbrgy=\"true\" linkedscene=\"scene_{$sceneId}\" 
    barangay=\"{$barangay}\" enabled=\"true\" onclick=\"navigation();filter_init();\">
    <layer type=\"text\" text=\"{$text}\" width=\"100%\" autoheight=\"true\" 
        align=\"bottom\" bgcolor=\"0x000000\" bgalpha=\"0\" 
        css=\"color:#FFFFFF; font-size:300%; font-family:Chewy; padding-left:20px; text-align:bottom;\"/>
</layer>
";

        $pattern = '/(<layer\b[^>]*name="topni"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) {
            Log::warning("⚠️ 'topni' layer not found — skipping thumbnail injection", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $openingTag = $match[1];
        $replacement = $openingTag . "\n" . $layer;

        $xml = preg_replace($pattern, $replacement, $xml, 1);

        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("🧩 Layer injected under TOPNI for scene {$sceneId} (S3)", [
            'municipalSlug' => $municipalSlug,
        ]);
    }

    // =====================================================================
    // LAYER INJECTION SA MAP (MUNICIPAL-AWARE, S3)
    // =====================================================================
    private function appendMapToSideMapLayerXml($googleMapSrc, $title, $sceneId, $municipalSlug)
    {
        if (!$googleMapSrc) {
            Log::info("ℹ️ No google_map_link provided — skipping sidemap iframe injection.", [
                'sceneId'       => $sceneId,
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $title = htmlspecialchars($title, ENT_QUOTES);
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $pattern = '/(<layer\b[^>]*name="sidemap"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) {
            Log::error("❌ sidemap layer not found in XML", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $openingTag = $match[1];

        $iframeLayer = "
    <layer 
        name=\"iframeLayer_{$title}\"
        type=\"iframe\"
        iframeurl=\"{$googleMapSrc}\"
        width=\"100%\"
        height=\"100%\"
        align=\"center\"
        parent=\"sidemap\"
        keep=\"true\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
    />
    ";

        $replacement = $openingTag . "\n" . $iframeLayer;

        $xml = preg_replace($pattern, $replacement, $xml, 1);

        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("🗺️ Google Map iframe injected right under sidemap tag. (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appendTitle($title, $sceneId, $municipalSlug)
    {
        if (!$title) return;

        $title = htmlspecialchars($title, ENT_QUOTES);
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $pattern = '/(<layer\b[^>]*name="scrollarea6"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) {
            Log::error("❌ scrollarea6 not found", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $openingTag = $match[1];

        $titleLayer = "
    <layer 
        name=\"Title_text_{$title}\"
        type=\"text\"
        text=\"{$title}\"
        width=\"90%\"
        height=\"auto\"
        autoheight=\"true\"
        enabled=\"false\"
        align=\"centertop\"
        bgcolor=\"0x000000\"
        bgalpha=\"0\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
        css=\"color:#000000; font-size:300%; font-family:Chewy; padding-left:0px; text-align:left;\"
    >
 </layer>
    ";

        $replacement = $openingTag . "\n" . $titleLayer;
        $xml = str_replace($openingTag, $replacement, $xml);

        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("🏷️ Title text inserted UNDER scrollarea6 (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appendBarangayInsideForBarangay($barangay, $title, $sceneId, $municipalSlug)
    {
        if (!$barangay) return;

        $barangay = htmlspecialchars($barangay, ENT_QUOTES);
        $title    = htmlspecialchars($title, ENT_QUOTES);

        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $parent  = "forbarangay";
        $pattern = '/(<layer\b[^>]*name="' . $parent . '"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) return;

        $openingTag = $match[1];

        $barangayLayer = "
    <layer 
        name=\"barangay_text_{$barangay}\"
        type=\"text\"
        text=\"{$barangay}\"
        width=\"100%\"
        height=\"100%\"
        parent=\"forbarangay\"
        enabled=\"false\"
        align=\"center\"
        bgcolor=\"0x000000\"
        bgalpha=\"0\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
        css=\"color:#000000; font-size:150%; font-family:Chewy; text-align:left;\"
    />";

        $replacement = $openingTag . "\n" . $barangayLayer;

        $xml = preg_replace($pattern, $replacement, $xml, 1);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("🏘️ Barangay text inserted (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appendCategoryInsideForCat($category, $title, $sceneId, $municipalSlug)
    {
        if (!$category) return;

        $category = htmlspecialchars($category, ENT_QUOTES);
        $title    = htmlspecialchars($title, ENT_QUOTES);

        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $parent  = "forcat";
        $pattern = '/(<layer\b[^>]*name="' . $parent . '"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) return;

        $openingTag = $match[1];

        $categoryLayer = "
    <layer 
        name=\"category_text_{$title}\"
        type=\"text\"
        text=\"{$category}\"
        width=\"100%\"
        height=\"100%\"
        parent=\"forcat\"
        enabled=\"false\"
        align=\"center\"
        bgcolor=\"0x000000\"
        bgalpha=\"0\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
        css=\"color:#000000; font-size:150%; font-family:Chewy; text-align:left;\"
    />";

        $replacement = $openingTag . "\n" . $categoryLayer;

        $xml = preg_replace($pattern, $replacement, $xml, 1);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("🏷️ Category text inserted (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appenddetailsInsidescrollarea5($address, $title, $sceneId, $municipalSlug)
    {
        if (!$address) return;

        $address = htmlspecialchars($address, ENT_QUOTES);
        $title   = htmlspecialchars($title, ENT_QUOTES);

        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $parent  = "scrollarea5";
        $pattern = '/(<layer\b[^>]*name="' . $parent . '"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) return;

        $openingTag = $match[1];

        $detailsLayer = "
    <layer 
        name=\"details_text_{$title}\"
        type=\"text\"
        text=\"{$address}\"
        width=\"100%\"
        height=\"auto\"
        parent=\"scrollarea5\"
        enabled=\"false\"
        align=\"centertop\"
        bgcolor=\"0x000000\"
        bgalpha=\"0\"
        css=\"font-family:Chewy;color:#000000; font-size:150%; text-align:left;\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
    />";

        $replacement = $openingTag . "\n" . $detailsLayer;

        $xml = preg_replace($pattern, $replacement, $xml, 1);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("📄 Address/details text inserted (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appendcontactnumber($contact_number, $title, $sceneId, $municipalSlug)
    {
        if (!$contact_number) return;

        $contact_number = htmlspecialchars($contact_number, ENT_QUOTES);
        $title          = htmlspecialchars($title, ENT_QUOTES);

        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $parent  = "forphone";
        $pattern = '/(<layer\b[^>]*name="' . $parent . '"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) {
            Log::error("❌ forphone layer not found", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $openingTag = $match[1];

        $contactLayer = "
    <layer 
        name=\"number_text_{$title}\"
        type=\"text\"
        text=\"{$contact_number}\"
        width=\"100%\"
        height=\"100%\"
        enabled=\"false\"
        parent=\"forphone\"
        align=\"center\"
        bgcolor=\"0x000000\"
        bgalpha=\"0\"
        css=\"font-family:Chewy; color:#000000; font-size:150%; text-align:left;\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
    />";

        $xml = preg_replace($pattern, $openingTag . "\n" . $contactLayer, $xml, 1);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("📞 Contact number inserted under forphone (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appendemail($email, $title, $sceneId, $municipalSlug)
    {
        if (!$email) return;

        $email = htmlspecialchars($email, ENT_QUOTES);
        $title = htmlspecialchars($title, ENT_QUOTES);

        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $parent  = "formail";
        $pattern = '/(<layer\b[^>]*name="' . $parent . '"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) {
            Log::error("❌ formail layer not found", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $openingTag = $match[1];

        $emailLayer = "
    <layer 
        name=\"email_text_{$title}\"
        type=\"text\"
        text=\"{$email}\"
        width=\"100%\"
        height=\"100%\"
        enabled=\"false\"
        parent=\"formail\"
        align=\"center\"
        bgcolor=\"0x000000\"
        bgalpha=\"0\"
        css=\"font-family:Chewy; color:#000000; font-size:150%; text-align:left;\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
    />";

        $xml = preg_replace($pattern, $openingTag . "\n" . $emailLayer, $xml, 1);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("📧 Email inserted under formail (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appendwebsite($website, $title, $sceneId, $municipalSlug)
    {
        if (!$website) return;

        $website = htmlspecialchars($website, ENT_QUOTES);
        $title   = htmlspecialchars($title, ENT_QUOTES);

        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $parent  = "forwebsite";
        $pattern = '/(<layer\b[^>]*name="' . $parent . '"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) {
            Log::error("❌ forwebsite layer not found", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $openingTag = $match[1];

        $websiteLayer = "
    <layer 
        name=\"website_text_{$website}\"
        url=\"skin/browse.png\"
        width=\"prop\"
        height=\"100%\"
        parent=\"forwebsite\"
        enabled=\"true\"
        css=\"font-family:Chewy; color:#000000; font-size:150%; text-align:left;\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
        onclick=\"openurl('{$website}')\"
    />";

        $xml = preg_replace($pattern, $openingTag . "\n" . $websiteLayer, $xml, 1);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("🌐 Website inserted under forwebsite (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appendfacebook($facebook, $title, $sceneId, $municipalSlug)
    {
        if (!$facebook) return;

        $facebook = htmlspecialchars($facebook, ENT_QUOTES);
        $title    = htmlspecialchars($title, ENT_QUOTES);

        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $parent  = "forfb";
        $pattern = '/(<layer\b[^>]*name="' . $parent . '"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) {
            Log::error("❌ forfb layer not found", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $openingTag = $match[1];

        $facebookLayer = "
    <layer 
        name=\"facebook_text_{$facebook}\"
        url=\"skin/fb.png\"
        width=\"prop\"
        height=\"100%\"
        parent=\"forfb\"
        enabled=\"true\"
        css=\"font-family:Chewy; color:#000000; font-size:150%; text-align:left;\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
        onclick=\"openurl('{$facebook}')\"
    />";

        $xml = preg_replace($pattern, $openingTag . "\n" . $facebookLayer, $xml, 1);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("📘 Facebook inserted under forfb (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appendinstagram($instagram, $title, $sceneId, $municipalSlug)
    {
        if (!$instagram) return;

        $instagram = htmlspecialchars($instagram, ENT_QUOTES);
        $title     = htmlspecialchars($title, ENT_QUOTES);

        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $parent  = "forinsta";
        $pattern = '/(<layer\b[^>]*name="' . $parent . '"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) {
            Log::error("❌ forinsta layer not found", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $openingTag = $match[1];

        $instagramLayer = "
    <layer 
        name=\"instagram_text_{$instagram}\"
        url=\"skin/insta.png\"
        width=\"prop\"
        height=\"100%\"
        parent=\"forinsta\"
        enabled=\"true\"
        css=\"font-family:Chewy; color:#000000; font-size:150%; text-align:left;\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
        onclick=\"openurl('{$instagram}')\"
    />";

        $xml = preg_replace($pattern, $openingTag . "\n" . $instagramLayer, $xml, 1);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("📸 Instagram inserted under forinsta (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    private function appendtiktok($tiktok, $title, $sceneId, $municipalSlug)
    {
        if (!$tiktok) return;

        $tiktok = htmlspecialchars($tiktok, ENT_QUOTES);
        $title  = htmlspecialchars($title, ENT_QUOTES);

        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $parent  = "fortiktok";
        $pattern = '/(<layer\b[^>]*name="' . $parent . '"[^>]*>)/i';

        if (!preg_match($pattern, $xml, $match)) {
            Log::error("❌ fortiktok layer not found", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $openingTag = $match[1];

        $tiktokLayer = "
    <layer 
        name=\"tiktok_text_{$tiktok}\"
        url=\"skin/tiktok.png\"
        width=\"prop\"
        height=\"100%\"
        parent=\"fortiktok\"
        enabled=\"true\"
        css=\"font-family:Chewy; color:#000000; font-size:150%; text-align:left;\"
        places=\"{$title}\"
        linkedscene=\"scene_{$sceneId}\"
        onclick=\"openurl('{$tiktok}')\"
    />";

        $xml = preg_replace($pattern, $openingTag . "\n" . $tiktokLayer, $xml, 1);
        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("🎵 TikTok inserted under fortiktok (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    // =====================================================================
    // UPDATE SCENE META IN XML (TITLE / SUBTITLE / PLACES) — MUNICIPAL, S3
    // =====================================================================
    private function updateSceneMetaInXml(string $sceneId, array $validated, string $municipalSlug): void
    {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $title    = $validated['title'] ?? '';
        $subtitle = $validated['location'] ?? '';

        $pattern = '/(<scene[^>]*name="scene_' . preg_quote($sceneId, '/') . '"[^>]*)(>)/is';

        $replacement = function ($m) use ($title, $subtitle) {
            $block = $m[1];

            $block = preg_replace('/title="[^"]*"/', 'title="' . $title . '"', $block);
            $block = preg_replace('/subtitle="[^"]*"/', 'subtitle="' . $subtitle . '"', $block);
            $block = preg_replace('/places="[^"]*"/', 'places="' . $title . '"', $block);

            return $block . $m[2];
        };

        $xml = preg_replace_callback($pattern, $replacement, $xml);

        $this->saveTourXmlToS3($municipalSlug, $xml);

        Log::info("✏️ Updated scene meta in XML (S3)", [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    // =====================================================================
    // UPDATE LAYER META IN XML (NAME / BARANGAY / TEXT LABEL) — MUNICIPAL, S3
    // =====================================================================
    private function updateLayerMetaInXml(string $sceneId, array $validated, string $municipalSlug): void
    {
        $xmlContent = $this->loadTourXmlFromS3($municipalSlug);
        if ($xmlContent === null) {
            Log::error('tour.xml not found when updating layer meta (S3)', [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $pattern = '/<layer[^>]*linkedscene="scene_' . preg_quote($sceneId, '/') . '"[^>]*>.*?<\/layer>/is';

        if (!preg_match($pattern, $xmlContent, $matches)) {
            Log::warning("Layer not found for scene {$sceneId} (S3)", [
                'municipalSlug' => $municipalSlug,
            ]);
            return;
        }

        $originalBlock = $matches[0];

        $newName     = $validated['title'] ?? '';
        $newBarangay = $validated['barangay'] ?? '';
        $textLabel   = strtoupper(str_replace('_', ' ', $newName));

        $updated = preg_replace(
            '/name="[^"]*"/',
            'name="' . $newName . '"',
            $originalBlock,
            1
        );

        $updated = preg_replace(
            '/barangay="[^"]*"/',
            'barangay="' . $newBarangay . '"',
            $updated,
            1
        );

        $updated = preg_replace(
            '/<layer[^>]*type="text"[^>]*text="[^"]*"/',
            '<layer type="text" text="' . $textLabel . '"',
            $updated,
            1
        );

        $xmlContent = str_replace($originalBlock, $updated, $xmlContent);

        $this->saveTourXmlToS3($municipalSlug, $xmlContent);

        Log::info("Updated layer meta for scene {$sceneId} (S3)", [
            'municipalSlug' => $municipalSlug,
        ]);
    }

    // =====================================================================
    // REMOVE SCENE FROM XML — MUNICIPAL, S3
    // =====================================================================
    private function removeSceneFromXml($sceneId, string $municipalSlug)
    {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $pattern = '/<scene[^>]*name="scene_' . preg_quote($sceneId, '/') . '"[^>]*>.*?<\/scene>\s*/is';
        $new     = preg_replace($pattern, '', $xml);

        $this->saveTourXmlToS3($municipalSlug, $new);

        Log::info('🧹 Scene removed from tour.xml (S3)', [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }

    // =====================================================================
    // REMOVE LAYER FROM XML — MUNICIPAL, S3
    // =====================================================================
    private function removeLayerFromXml($sceneId, string $municipalSlug)
    {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $pattern = '/
        <layer\b[^>]*linkedscene="scene_' . preg_quote($sceneId, '/') . '"[^>]*\/>   
        |
        <layer\b[^>]*linkedscene="scene_' . preg_quote($sceneId, '/') . '"[^>]*>      
        (?:.*?)                                                                         
        <\/layer>                                                                       
    /isx';

        $updatedXml = preg_replace($pattern, '', $xml);

        $this->saveTourXmlToS3($municipalSlug, $updatedXml);

        Log::info('🧹 All layers for scene removed (supports self-closing + block, S3)', [
            'sceneId'       => $sceneId,
            'municipalSlug' => $municipalSlug,
        ]);
    }
}

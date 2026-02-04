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

     private function stripMunicipal(string $path, string $municipalSlug): string
{
    return preg_replace(
        '#^' . preg_quote($municipalSlug, '#') . '/#',
        '',
        ltrim($path, '/')
    );
}

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

        /* ================= 2️⃣ READ KRPANO CONFIG ================= */

        $config = $this->extractKrpanoSceneConfig($sceneId, $tourXmlPath);

        if ($config) {
            $thumb = $this->stripMunicipal(
                "{$basePath}/" . ltrim($config['thumb'], '/'),
                $municipalSlug
            );

            $preview = $this->stripMunicipal(
                "{$basePath}/" . ltrim($config['preview'], '/'),
                $municipalSlug
            );

            $cubeUrl = $this->stripMunicipal(
                "{$basePath}/" . ltrim($config['cube'], '/'),
                $municipalSlug
            );

            $multires = $config['multires'];
        } else {
            Log::warning('⚠️ KRPANO config not found, using fallback URLs');
            $tileBase = "{$basePath}/panos/{$sceneId}.tiles";
            $thumb    = "{$tileBase}/thumb.jpg";
            $preview  = "{$tileBase}/preview.jpg";
            $cubeUrl  = "{$tileBase}/%s/l%l/%v/l%l_%s_%v_%h.jpg";
            $multires = '512,1024,2048';
        }
        /* ================= 3️⃣ UPLOAD VT0UR TO S3 ================= */

        $this->uploadFolderToS3($vtourPath, $basePath);

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
    $exe = base_path('krpanotools/krpanotools');
    $config = base_path('krpanotools/templates/vtour-multires.config');

    // ✅ run beside the panorama
    chdir(dirname($localPanorama));

    $cmd = "\"{$exe}\" makepano -config=\"{$config}\" \"{$localPanorama}\"";

    exec($cmd . " 2>&1", $out, $status);

    Log::info('🧩 KRPANO EXECUTED', [
        'cmd'    => $cmd,
        'cwd'    => getcwd(),
        'status' => $status,
        'output' => $out,
    ]);

    if ($status !== 0) {
        throw new \Exception("KRPANO failed:\n" . implode("\n", $out));
    }
}


    private function extractKrpanoSceneConfig(string $sceneId, string $tourXmlPath): ?array
    {
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

     private function appendLayerToXml($sceneId, $sceneTitle, $barangay, $thumb, $municipalSlug)
    {
        $xml = $this->loadTourXmlFromS3($municipalSlug);
        if ($xml === null) return;

        $text = ucfirst(strtolower(str_replace('_', ' ', $sceneTitle)));
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
        css=\"font-family:Chewy; color:#000000; font-size:150%; text-align:left; word-wrap:break-word; overflow-wrap:break-word; white-space:normal;\"
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

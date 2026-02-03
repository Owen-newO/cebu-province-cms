<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SceneXmlService
{
    /* =====================================================
     | CORE S3 HELPERS
     ===================================================== */
    private function tourXmlKey(string $municipalSlug): string
    {
        return "{$municipalSlug}/tour.xml";
    }

    private function load(string $municipalSlug): ?string
    {
        $key = $this->tourXmlKey($municipalSlug);

        if (!Storage::disk('s3')->exists($key)) {
            Log::error('❌ tour.xml missing', compact('municipalSlug'));
            return null;
        }

        return Storage::disk('s3')->get($key);
    }

    private function save(string $municipalSlug, string $xml): void
    {
        Storage::disk('s3')->put($this->tourXmlKey($municipalSlug), $xml);
    }

    /* =====================================================
     | PUBLIC ENTRY POINT (USED BY JOB + CONTROLLER)
     ===================================================== */
    public function injectFullScene(
        int $sceneId,
        array $validated,
        string $thumb,
        string $preview,
        string $cubeUrl,
        string $multires,
        string $municipalSlug
    ): void {
        $this->appendSceneToXml($sceneId, $validated, $thumb, $preview, $cubeUrl, $multires, $municipalSlug);

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

    /* =====================================================
     | SCENE BLOCK
     ===================================================== */
    private function appendSceneToXml($sceneId, $validated, $thumb, $preview, $cubeUrl, $multires, $municipalSlug)
    {
        $xml = $this->load($municipalSlug);
        if (!$xml) return;

        $title    = htmlspecialchars($validated['title'], ENT_QUOTES);
        $subtitle = htmlspecialchars($validated['location'] ?? '', ENT_QUOTES);

        $scene = "
<scene name=\"scene_{$sceneId}\" title=\"{$title}\" subtitle=\"{$subtitle}\" places=\"{$title}\" thumburl=\"{$thumb}\">
  <view hlookat=\"0\" vlookat=\"0\" fov=\"120\" />
  <preview url=\"{$preview}\" />
  <image>
    <cube url=\"{$cubeUrl}\" multires=\"{$multires}\" />
  </image>
</scene>
";

        $xml = str_replace('</krpano>', $scene . "\n</krpano>", $xml);
        $this->save($municipalSlug, $xml);
    }

    /* =====================================================
     | LAYER HELPERS (UNCHANGED LOGIC)
     ===================================================== */

    private function injectUnder(string $parent, string $block, string $municipalSlug)
    {
        $xml = $this->load($municipalSlug);
        if (!$xml) return;

        $pattern = '/(<layer[^>]*name="' . preg_quote($parent, '/') . '"[^>]*>)/i';
        if (!preg_match($pattern, $xml)) return;

        $xml = preg_replace($pattern, '$1' . "\n" . $block, $xml, 1);
        $this->save($municipalSlug, $xml);
    }

    private function appendLayerToXml($sceneId, $title, $barangay, $thumb, $municipalSlug)
    {
        $safe = htmlspecialchars($title, ENT_QUOTES);

        $layer = "
<layer name=\"{$safe}\" url=\"{$thumb}\" linkedscene=\"scene_{$sceneId}\" barangay=\"{$barangay}\" keep=\"true\" />
";
        $this->injectUnder('topni', $layer, $municipalSlug);
    }

    private function appendMapToSideMapLayerXml($map, $title, $sceneId, $municipalSlug)
    {
        if (!$map) return;

        $layer = "
<layer type=\"iframe\" iframeurl=\"{$map}\" linkedscene=\"scene_{$sceneId}\" />
";
        $this->injectUnder('sidemap', $layer, $municipalSlug);
    }

    private function appendTitle($title, $sceneId, $municipalSlug)
    {
        $title = htmlspecialchars($title, ENT_QUOTES);

        $layer = "
<layer type=\"text\" text=\"{$title}\" linkedscene=\"scene_{$sceneId}\" />
";
        $this->injectUnder('scrollarea6', $layer, $municipalSlug);
    }

    private function appendBarangayInsideForBarangay($barangay, $title, $sceneId, $municipalSlug)
    {
        if (!$barangay) return;

        $layer = "
<layer type=\"text\" text=\"{$barangay}\" linkedscene=\"scene_{$sceneId}\" />
";
        $this->injectUnder('forbarangay', $layer, $municipalSlug);
    }

    private function appendCategoryInsideForCat($category, $title, $sceneId, $municipalSlug)
    {
        if (!$category) return;

        $layer = "
<layer type=\"text\" text=\"{$category}\" linkedscene=\"scene_{$sceneId}\" />
";
        $this->injectUnder('forcat', $layer, $municipalSlug);
    }

    private function appenddetailsInsidescrollarea5($address, $title, $sceneId, $municipalSlug)
    {
        if (!$address) return;

        $layer = "
<layer type=\"text\" text=\"{$address}\" linkedscene=\"scene_{$sceneId}\" />
";
        $this->injectUnder('scrollarea5', $layer, $municipalSlug);
    }

    private function appendcontactnumber($value, $title, $sceneId, $municipalSlug)
    {
        if (!$value) return;

        $this->injectUnder('forphone', "<layer type=\"text\" text=\"{$value}\" linkedscene=\"scene_{$sceneId}\" />", $municipalSlug);
    }

    private function appendemail($value, $title, $sceneId, $municipalSlug)
    {
        if (!$value) return;

        $this->injectUnder('formail', "<layer type=\"text\" text=\"{$value}\" linkedscene=\"scene_{$sceneId}\" />", $municipalSlug);
    }

    private function appendwebsite($value, $title, $sceneId, $municipalSlug)
    {
        if (!$value) return;

        $this->injectUnder('forwebsite', "<layer url=\"skin/browse.png\" linkedscene=\"scene_{$sceneId}\" onclick=\"openurl('{$value}')\" />", $municipalSlug);
    }

    private function appendfacebook($value, $title, $sceneId, $municipalSlug)
    {
        if (!$value) return;

        $this->injectUnder('forfb', "<layer url=\"skin/fb.png\" linkedscene=\"scene_{$sceneId}\" onclick=\"openurl('{$value}')\" />", $municipalSlug);
    }

    private function appendinstagram($value, $title, $sceneId, $municipalSlug)
    {
        if (!$value) return;

        $this->injectUnder('forinsta', "<layer url=\"skin/insta.png\" linkedscene=\"scene_{$sceneId}\" onclick=\"openurl('{$value}')\" />", $municipalSlug);
    }

    private function appendtiktok($value, $title, $sceneId, $municipalSlug)
    {
        if (!$value) return;

        $this->injectUnder('fortiktok', "<layer url=\"skin/tiktok.png\" linkedscene=\"scene_{$sceneId}\" onclick=\"openurl('{$value}')\" />", $municipalSlug);
    }
}

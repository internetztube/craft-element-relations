<?php
/**
 * Demo site for screenshots and product reviews: "Transit", a fictional travel journal about cities and the trains
 * between them, with a journal, team, pages, a homepage built from content blocks, two sites, Navigation menus,
 * Formie forms and SEOmatic settings. Photos by Frederic Köberl (unsplash.com/@internetztube) are downloaded on the
 * first run; without network a drawn picture takes their place. Portraits, logo and PDFs are drawn here. Idempotent.
 *
 * Lives next to the test fixtures of dev/seed.php (section "Pages", "Page via …" entries) in the same project; dev.sh
 * seed runs both, so CI proves the demo too. Its handles must not collide with the fixtures: the tests count on them.
 * Run: dev/dev.sh seed   (or: php dev/demo.php <craft-project-dir> schema|content, two processes: fields made in one
 *      process are not reliably writable in the same process)
 */
require ($argv[1] ?? __DIR__) . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\enums\Color;
use craft\fieldlayoutelements\assets\AssetTitleField;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fieldlayoutelements\TitleField;
use craft\fields\Assets as AssetsField;
use craft\fields\Categories as CategoriesField;
use craft\fields\Entries as EntriesField;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\UserGroup;
use craft\models\Volume;
use internetztube\elementRelations\fields\ElementRelationsField;

function fail(object $model): never
{
    throw new RuntimeException(get_class($model) . ': ' . print_r($model->getErrors(), true));
}

function field(string $handle): FieldInterface
{
    return Craft::$app->getFields()->getFieldByHandle($handle) ?? throw new RuntimeException("No field $handle");
}

function ensureField(string $class, array $config): FieldInterface
{
    if ($field = Craft::$app->getFields()->getFieldByHandle($config['handle'])) {
        return $field;
    }
    $field = new $class($config);
    Craft::$app->getFields()->saveField($field) or fail($field);
    return $field;
}

/**
 * @param array<string, array> $tabs tab name => elements: a layout element, a field handle, or [handle, config]
 */
function layout(string $type, array $tabs): FieldLayout
{
    $layout = new FieldLayout(['type' => $type]);
    $layout->setTabs(array_map(function (string $name, array $elements) use ($layout) {
        $tab = new FieldLayoutTab(['name' => $name, 'layout' => $layout]);
        $tab->setElements(array_map(function ($element) {
            if (is_string($element)) {
                return new CustomField(field($element));
            }
            if (is_array($element)) {
                return new CustomField(field($element[0]), $element[1]);
            }
            return $element;
        }, $elements));
        return $tab;
    }, array_keys($tabs), $tabs));
    return $layout;
}

function ensureEntryType(string $handle, array $config, array $tabs): EntryType
{
    $entries = Craft::$app->getEntries();
    if ($type = $entries->getEntryTypeByHandle($handle)) {
        // add fields the demo gained since; never replace the layout (content is stored by layout element uid)
        if (addMissingFields($type->getFieldLayout(), $tabs)) {
            $entries->saveEntryType($type) or fail($type);
        }
        return $type;
    }
    $type = new EntryType(['handle' => $handle, ...$config]);
    $type->setFieldLayout(layout(Entry::class, $tabs));
    $entries->saveEntryType($type) or fail($type);
    return $type;
}

/** Appends the custom fields of `$tabs` that the layout lacks to their tab (created if missing). */
function addMissingFields(FieldLayout $layout, array $tabs): bool
{
    $changed = false;
    $layoutTabs = $layout->getTabs();
    foreach ($tabs as $name => $elements) {
        $tab = array_values(array_filter($layoutTabs, fn(FieldLayoutTab $t) => $t->name === $name))[0] ?? null;
        foreach ($elements as $element) {
            $handle = is_string($element) ? $element : (is_array($element) ? $element[0] : null);
            if ($handle === null || $layout->getFieldByHandle($handle)) {
                continue;
            }
            if (!$tab) {
                $tab = new FieldLayoutTab(['name' => $name, 'layout' => $layout]);
                $layoutTabs[] = $tab;
            }
            $tab->setElements([...$tab->getElements(), is_array($element) ? new CustomField(field($handle), $element[1]) : new CustomField(field($handle))]);
            $changed = true;
        }
    }
    if ($changed) {
        $layout->setTabs($layoutTabs);
    }
    return $changed;
}

function ensureSection(string $handle, string $name, string $sectionType, array $entryTypes, ?string $uriFormat): Section
{
    $entries = Craft::$app->getEntries();
    if ($section = $entries->getSectionByHandle($handle)) {
        $known = array_map(fn(EntryType $t) => $t->id, $section->getEntryTypes());
        $missing = array_filter($entryTypes, fn(EntryType $t) => !in_array($t->id, $known, true));
        if ($missing) {
            $section->setEntryTypes([...$section->getEntryTypes(), ...$missing]);
            $entries->saveSection($section) or fail($section);
        }
        return $section;
    }
    $section = new Section([
        'name' => $name,
        'handle' => $handle,
        'type' => $sectionType,
        'maxLevels' => $sectionType === Section::TYPE_STRUCTURE ? 2 : null,
        'siteSettings' => array_map(fn(Site $site) => new Section_SiteSettings([
            'siteId' => $site->id,
            'hasUrls' => $uriFormat !== null,
            'uriFormat' => $uriFormat,
        ]), Craft::$app->getSites()->getAllSites()),
    ]);
    $section->setEntryTypes($entryTypes);
    $entries->saveSection($section) or fail($section);
    return $section;
}

function ensureVolume(string $handle, string $name): Volume
{
    $volumes = Craft::$app->getVolumes();
    if ($volume = $volumes->getVolumeByHandle($handle)) {
        return $volume;
    }
    $fsService = Craft::$app->getFs();
    if (!$fs = $fsService->getFilesystemByHandle($handle)) {
        $fs = $fsService->createFilesystem([
            'type' => Local::class,
            'name' => $name,
            'handle' => $handle,
            'hasUrls' => true,
            'settings' => ['path' => "@webroot/uploads/$handle", 'url' => "@web/uploads/$handle"],
        ]);
        $fsService->saveFilesystem($fs) or fail($fs);
    }
    $volume = new Volume(['name' => $name, 'handle' => $handle, 'fsHandle' => $handle]);
    $volume->setFieldLayout(layout(Asset::class, ['Content' => [new AssetTitleField(), new craft\fieldlayoutelements\assets\AltField(), 'usedIn']]));
    $volumes->saveVolume($volume) or fail($volume);
    return $volume;
}

// ---------------------------------------------------------------------------------------------------------------
// Images, drawn with GD
// ---------------------------------------------------------------------------------------------------------------

/** @return int[] */
function rgb(string $hex): array
{
    return array_map('hexdec', str_split(ltrim($hex, '#'), 2));
}

function mix(string $a, string $b, float $t): string
{
    [$r1, $g1, $b1] = rgb($a);
    [$r2, $g2, $b2] = rgb($b);
    return sprintf('#%02x%02x%02x', $r1 + ($r2 - $r1) * $t, $g1 + ($g2 - $g1) * $t, $b1 + ($b2 - $b1) * $t);
}

function color(GdImage $img, string $hex, int $alpha = 0): int
{
    [$r, $g, $b] = rgb($hex);
    return imagecolorallocatealpha($img, $r, $g, $b, $alpha);
}

function gradient(GdImage $img, string $top, string $bottom, int $y0 = 0, ?int $y1 = null): void
{
    $y1 ??= imagesy($img);
    for ($y = $y0; $y < $y1; $y++) {
        imageline($img, 0, $y, imagesx($img), $y, color($img, mix($top, $bottom, ($y - $y0) / max(1, $y1 - $y0 - 1))));
    }
}

/** A mountain ridge by midpoint displacement: 129 points across the width. @return float[] heights in -1..1 */
function ridge(float $roughness): array
{
    $n = 128;
    $h = array_fill(0, $n + 1, 0.0);
    $h[0] = mt_rand() / mt_getrandmax() * 2 - 1;
    $h[$n] = mt_rand() / mt_getrandmax() * 2 - 1;
    for ($step = $n, $d = 1.0; $step > 1; $step /= 2, $d *= $roughness) {
        for ($i = $step / 2; $i < $n; $i += $step) {
            $h[$i] = ($h[$i - $step / 2] + $h[$i + $step / 2]) / 2 + (mt_rand() / mt_getrandmax() * 2 - 1) * $d;
        }
    }
    $max = max(array_map('abs', $h)) ?: 1;
    return array_map(fn($v) => $v / $max, $h);
}

function glow(GdImage $img, int $x, int $y, int $r, string $hex): void
{
    for ($i = 5; $i >= 1; $i--) {
        imagefilledellipse($img, $x, $y, (int)($r * 2 * (1 + $i * 0.35)), (int)($r * 2 * (1 + $i * 0.35)), color($img, $hex, 100 + $i * 4));
    }
    imagefilledellipse($img, $x, $y, $r * 2, $r * 2, color($img, $hex));
}

/**
 * A landscape: sky, sun or moon, three mountain layers, optional lake, stars, trees, a hut or a tent.
 * @param array{top: string, bottom: string, sun: string, layers: string[], night?: bool, lake?: bool, trees?: bool, hut?: bool, tent?: bool, flat?: bool} $p
 */
function landscape(string $path, array $p, int $seed): void
{
    mt_srand($seed);
    [$w, $h] = [1600, 1000];
    $img = imagecreatetruecolor($w, $h);
    gradient($img, $p['top'], $p['bottom']);
    if ($p['night'] ?? false) {
        for ($i = 0; $i < 260; $i++) {
            $s = mt_rand(1, 3);
            imagefilledellipse($img, mt_rand(0, $w), mt_rand(0, (int)($h * 0.6)), $s, $s, color($img, '#ffffff', mt_rand(0, 70)));
        }
    }
    glow($img, (int)($w * (0.25 + mt_rand(0, 50) / 100)), (int)($h * 0.32), 70, $p['sun']);

    $lakeY = ($p['lake'] ?? false) ? (int)($h * 0.74) : $h;
    $front = [];
    foreach ($p['layers'] as $i => $layerColor) {
        $base = $h * (0.5 + $i * 0.11);
        $amp = ($p['flat'] ?? false) && $i === 1 ? 25 : $h * (0.2 - $i * 0.05);
        $heights = ridge(0.55 + $i * 0.05);
        $points = [0, $h];
        foreach ($heights as $k => $v) {
            $x = $w * $k / 128;
            $y = $base - $v * $amp;
            $points[] = (int)$x;
            $points[] = (int)$y;
            $front[$k] = $y;
        }
        array_push($points, $w, $h);
        imagefilledpolygon($img, $points, color($img, $layerColor));
        if ($i === 0 && !($p['night'] ?? false)) {
            // light haze between the layers
            imagefilledrectangle($img, 0, (int)($base - $amp * 0.2), $w, $h, color($img, $p['bottom'], 110));
        }
    }
    $frontAt = fn(float $x) => $front[(int)round(128 * $x / $w)] ?? $h;
    $last = end($p['layers']);
    if ($p['trees'] ?? false) {
        for ($i = 0; $i < 70; $i++) {
            $x = mt_rand(0, $w);
            $th = mt_rand(40, 95);
            $y = (int)$frontAt($x) + mt_rand(5, 60);
            imagefilledpolygon($img, [$x, $y - $th, $x - $th / 4, $y, $x + $th / 4, $y], color($img, mix($last, '#000000', 0.35)));
        }
    }
    if ($p['hut'] ?? false) {
        $x = (int)($w * 0.68);
        $y = (int)$frontAt($x) + 30;
        imagefilledrectangle($img, $x - 60, $y - 70, $x + 60, $y, color($img, mix($last, '#000000', 0.5)));
        imagefilledpolygon($img, [$x - 80, $y - 70, $x, $y - 135, $x + 80, $y - 70], color($img, mix($last, '#000000', 0.6)));
        imagefilledrectangle($img, $x - 30, $y - 50, $x - 8, $y - 28, color($img, '#ffd27a'));
        imagefilledrectangle($img, $x + 10, $y - 50, $x + 32, $y - 28, color($img, '#ffd27a'));
    }
    if ($p['tent'] ?? false) {
        $x = (int)($w * 0.62);
        $y = (int)$frontAt($x) + 70;
        glow($img, $x, $y - 40, 40, '#ffb05c');
        imagefilledpolygon($img, [$x - 110, $y, $x, $y - 120, $x + 110, $y], color($img, '#f08a3e'));
        imagefilledpolygon($img, [$x - 18, $y, $x, $y - 60, $x + 18, $y], color($img, '#ffe0a8'));
    }
    if ($lakeY < $h) {
        gradient($img, mix($p['bottom'], $last, 0.45), mix($p['top'], $last, 0.6), $lakeY, $h);
        for ($i = 0; $i < 40; $i++) {
            $y = mt_rand($lakeY + 5, $h);
            $x = mt_rand(0, $w);
            imageline($img, $x, $y, $x + mt_rand(40, 240), $y, color($img, '#ffffff', 95));
        }
    }
    imagejpeg($img, $path, 86);
}

function font(bool $bold): ?string
{
    $candidates = $bold
        ? ['/System/Library/Fonts/Supplemental/Arial Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf']
        : ['/System/Library/Fonts/Supplemental/Arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'];
    return array_values(array_filter($candidates, 'is_file'))[0] ?? null;
}

function text(GdImage $img, string $text, int $size, int $x, int $y, string $hex, bool $bold = true): void
{
    $font = font($bold);
    $font ? imagettftext($img, $size, 0, $x, $y, color($img, $hex), $font, $text) : imagestring($img, 5, $x, $y - 15, $text, color($img, $hex));
}

/** The Transit mark: a metro-map "T", a line with three stations, on a warm gradient tile (SVG, 120×120 units). */
function markSvg(string $id): string
{
    return <<<SVG
<defs><linearGradient id="$id" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff5f45"/><stop offset="1" stop-color="#ffb23e"/></linearGradient></defs>
<rect width="120" height="120" rx="30" fill="url(#$id)"/>
<path d="M30 40H90M60 40V86" stroke="#fff" stroke-width="13" stroke-linecap="round" fill="none"/>
<g fill="#fff"><circle cx="30" cy="40" r="11"/><circle cx="90" cy="40" r="11"/><circle cx="60" cy="88" r="13"/></g>
<g fill="#ff7a43"><circle cx="30" cy="40" r="4.5"/><circle cx="90" cy="40" r="4.5"/><circle cx="60" cy="88" r="5.5"/></g>
SVG;
}

const WORDMARK_FONT = "'Avenir Next', 'Outfit', 'Helvetica Neue', Arial, sans-serif";

function logo(string $path): void
{
    $font = WORDMARK_FONT;
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 470 120" width="940" height="240"><g>' . markSvg('logo') . "</g>"
        . "<text x=\"146\" y=\"84\" font-family=\"$font\" font-size=\"72\" font-weight=\"700\" letter-spacing=\"-2\" fill=\"#1b2236\">transit</text></svg>");
}

function socialImage(string $path): void
{
    $font = WORDMARK_FONT;
    $mark = markSvg('social');
    $lines = '';
    foreach ([['#ff5f45', 'M-40 520 C 240 520 300 360 520 360 S 900 200 1260 200'], ['#36c5f0', 'M-40 600 C 300 600 420 470 640 470 S 980 330 1260 330'], ['#2eb67d', 'M-40 430 C 180 430 260 300 430 260 S 760 90 1260 110'], ['#ecb22e', 'M200 700 C 300 560 480 560 560 640 S 880 720 1260 560']] as [$color, $d]) {
        $lines .= "<path d=\"$d\" stroke=\"$color\" stroke-width=\"14\" stroke-linecap=\"round\" fill=\"none\" opacity=\".55\"/>";
    }
    file_put_contents($path, <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630">
<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#141a2b"/><stop offset="1" stop-color="#26324f"/></linearGradient></defs>
<rect width="1200" height="630" fill="url(#bg)"/>$lines
<g transform="translate(96 150) scale(1.5)">$mark</g>
<text x="300" y="262" font-family="$font" font-size="120" font-weight="700" letter-spacing="-4" fill="#fff">transit</text>
<text x="306" y="326" font-family="$font" font-size="36" fill="#c6d0e6">Cities, and the trains between them.</text>
</svg>
SVG);
}

/** A photo from Unsplash (images.unsplash.com/<path>, 1600px wide), or a drawn city at dusk when offline. */
function unsplash(string $path, string $imagePath, int $seed): void
{
    try {
        Craft::createGuzzleClient(['timeout' => 30])->get("https://images.unsplash.com/$imagePath?w=1600&q=80&fm=jpg&fit=max", ['sink' => $path]);
        if (@getimagesize($path)) {
            return;
        }
    } catch (Throwable $e) {
        Craft::warning("Demo photo $imagePath not downloaded: {$e->getMessage()}", 'element-relations');
    }
    $palettes = [
        ['top' => '#1d2433', 'bottom' => '#e98b6d', 'sun' => '#ffd9a8', 'layers' => ['#5c6680', '#3b4560', '#20283a']],
        ['top' => '#3d5a80', 'bottom' => '#e0fbfc', 'sun' => '#ffffff', 'layers' => ['#98c1d9', '#5b7fa3', '#293241'], 'lake' => true],
        ['top' => '#0b1026', 'bottom' => '#2e3a6e', 'sun' => '#f5f3ce', 'layers' => ['#242c52', '#181e3b', '#0d1124'], 'night' => true],
    ];
    landscape($path, $palettes[$seed % 3], $seed);
}

function portrait(string $path, string $bg, string $figure, int $seed): void
{
    mt_srand($seed);
    $img = imagecreatetruecolor(800, 800);
    gradient($img, mix($bg, '#ffffff', 0.35), $bg);
    imagefilledellipse($img, 400, 880, 640, 560, color($img, $figure));
    imagefilledellipse($img, 400, 320, 290, 330, color($img, mix($figure, '#ffffff', 0.12)));
    imagefilledellipse($img, 400, 220, 300, 180, color($img, mix($figure, '#000000', 0.25))); // hair
    imagejpeg($img, $path, 88);
}

/** A one-page PDF with a title and a few lines. */
function pdf(string $path, string $title, array $lines): void
{
    $esc = fn(string $s) => strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    $stream = "BT /F1 26 Tf 72 760 Td ({$esc($title)}) Tj ET\n";
    foreach ($lines as $i => $line) {
        $stream .= sprintf("BT /F2 13 Tf 72 %d Td (%s) Tj ET\n", 720 - $i * 22, $esc($line));
    }
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
        "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $out = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($out);
        $out .= ($i + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($out);
    $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $out .= sprintf("%010d 00000 n \n", $offset);
    }
    $out .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($path, $out);
}

function ensureAsset(string $volumeHandle, string $filename, string $title, callable $draw, ?string $alt = null): Asset
{
    $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
    if ($asset = Asset::find()->volumeId($volume->id)->filename($filename)->one()) {
        return $asset;
    }
    // a file left from an earlier install (dev.sh reset keeps the uploads) would get the new asset a suffixed name
    if ($volume->fileExists($filename)) {
        $volume->deleteFile($filename);
    }
    $tmp = sys_get_temp_dir() . '/demo-' . uniqid() . '-' . $filename;
    $draw($tmp);
    $asset = new Asset([
        'tempFilePath' => $tmp,
        'filename' => $filename,
        'title' => $title,
        'alt' => $alt,
        'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id,
        'volumeId' => $volume->id,
        'avoidFilenameConflicts' => true,
    ]);
    $asset->setScenario(Asset::SCENARIO_CREATE);
    Craft::$app->getElements()->saveElement($asset) or fail($asset);
    return $asset;
}

/**
 * Creates an entry once (existing ones are left alone, so re-runs add no revisions).
 * @param array<string, mixed> $attributes e.g. postDate, authorIds, enabled, expiryDate
 */
function ensureEntry(string $section, string $type, string $title, array $fields = [], array $attributes = [], ?string $titleDe = null): Entry
{
    $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section);
    $entry = Entry::find()->sectionId($sectionModel->id)->title($title)->status(null)->one();
    if (!$entry) {
        $entry = new Entry([
            'sectionId' => $sectionModel->id,
            'typeId' => Craft::$app->getEntries()->getEntryTypeByHandle($type)->id,
            'title' => $title,
            ...$attributes,
        ]);
        $entry->setFieldValues($fields);
        Craft::$app->getElements()->saveElement($entry) or fail($entry);
    }
    if ($titleDe) {
        translate($entry, $titleDe);
    }
    return $entry;
}

/** The German title (the rest of the content is shared between the sites). */
function translate(craft\base\ElementInterface $element, string $title): void
{
    $de = Craft::$app->getSites()->getSiteByHandle('de');
    $localized = $element::find()->id($element->id)->siteId($de->id)->status(null)->one();
    if ($localized && $localized->title !== $title) {
        $localized->title = $title;
        $localized->slug = craft\helpers\ElementHelper::generateSlug($title, null, 'de');
        Craft::$app->getElements()->saveElement($localized) or fail($localized);
    }
}

function ref(craft\base\ElementInterface $element): string
{
    $kind = $element instanceof Asset ? 'asset' : 'entry';
    return sprintf('{%s:%d@%d:url||%s}', $kind, $element->id, $element->siteId, $element->getUrl() ?: 'https://example.com');
}

$phase = $argv[2] ?? 'schema';
$sites = Craft::$app->getSites();
$entries = Craft::$app->getEntries();
$elements = Craft::$app->getElements();

if ($phase === 'schema') {
    // feature flag + edition: the demo shows Pro; Craft itself as Pro (trial in dev) for several authors
    $configFile = CRAFT_BASE_PATH . '/config/element-relations.php';
    if (!file_exists($configFile)) {
        file_put_contents($configFile, "<?php\n\nreturn [\n    'features' => ['editions' => true],\n];\n");
    }
    if (Craft::$app->edition !== CmsEdition::Pro) {
        Craft::$app->setEdition(CmsEdition::Pro);
    }
    $projectConfig = Craft::$app->getProjectConfig();
    $projectConfig->set('system.name', 'Transit');

    $primary = $sites->getPrimarySite();
    if ($primary->name !== 'English') {
        $primary->name = 'English';
        $sites->saveSite($primary) or fail($primary);
        $group = $sites->getGroupById($primary->groupId);
        $group->setName('Transit');
        $sites->saveGroup($group);
    }
    if (!$sites->getSiteByHandle('de')) {
        $de = new Site(['groupId' => $primary->groupId, 'name' => 'Deutsch', 'handle' => 'de', 'language' => 'de-DE', 'hasUrls' => true, 'baseUrl' => '@web/de']);
        $sites->saveSite($de) or fail($de);
    }

    ensureField(ElementRelationsField::class, ['name' => 'Used in', 'handle' => 'usedIn', 'instructions' => 'Everything that links to this, in every site.']);
    $images = ensureVolume('images', 'Images');
    ensureVolume('documents', 'Documents');

    $categories = Craft::$app->getCategories();
    if (!$topics = $categories->getGroupByHandle('topics')) {
        $topics = new CategoryGroup(['name' => 'Topics', 'handle' => 'topics', 'maxLevels' => 1]);
        $topics->setSiteSettings(array_map(fn(Site $site) => new CategoryGroup_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]), $sites->getAllSites()));
        $topics->setFieldLayout(layout(Category::class, ['Content' => [new TitleField(), 'usedIn']]));
        $categories->saveGroup($topics) or fail($topics);
    }

    $imageField = fn(string $name, string $handle) => ensureField(AssetsField::class, [
        'name' => $name, 'handle' => $handle, 'sources' => ["volume:$images->uid"], 'maxRelations' => 1, 'viewMode' => 'large',
        'restrictFiles' => true, 'allowedKinds' => ['image'],
    ]);
    $imageField('Hero image', 'heroImage');
    $imageField('Image', 'image');
    $imageField('Portrait', 'portrait');
    ensureField(PlainText::class, ['name' => 'Headline', 'handle' => 'headline', 'translationMethod' => 'site']);
    ensureField(PlainText::class, ['name' => 'Summary', 'handle' => 'summary', 'multiline' => true, 'initialRows' => 3, 'translationMethod' => 'site']);
    ensureField(PlainText::class, ['name' => 'Caption', 'handle' => 'caption', 'translationMethod' => 'site']);
    ensureField(PlainText::class, ['name' => 'Role', 'handle' => 'jobTitle', 'translationMethod' => 'site']);
    ensureField(PlainText::class, ['name' => 'Button label', 'handle' => 'buttonLabel', 'translationMethod' => 'site']);
    ensureField(craft\ckeditor\Field::class, ['name' => 'Body', 'handle' => 'body', 'translationMethod' => 'site']);
    ensureField(CategoriesField::class, ['name' => 'Topics', 'handle' => 'topics', 'source' => "group:$topics->uid", 'branchLimit' => null]);
    ensureField(EntriesField::class, ['name' => 'Written by', 'handle' => 'writtenBy', 'maxRelations' => 2]);
    ensureField(EntriesField::class, ['name' => 'Related articles', 'handle' => 'relatedArticles', 'maxRelations' => 3]);
    ensureField(EntriesField::class, ['name' => 'Articles', 'handle' => 'articles', 'maxRelations' => 3]);
    ensureField(Link::class, ['name' => 'Link', 'handle' => 'buttonLink', 'types' => ['entry', 'asset', 'url']]);
    ensureField(verbb\formie\fields\Forms::class, ['name' => 'Form', 'handle' => 'contactForm', 'maxRelations' => 1]);
    ensureField(nystudio107\seomatic\fields\SeoSettings::class, ['name' => 'SEO', 'handle' => 'seo']);
    ensureField(AssetsField::class, ['name' => 'Gallery', 'handle' => 'gallery', 'sources' => ["volume:$images->uid"], 'viewMode' => 'large', 'restrictFiles' => true, 'allowedKinds' => ['image']]);
    $imageField('Logo', 'logoImage');
    ensureField(EntriesField::class, ['name' => 'Destinations', 'handle' => 'destinations', 'maxRelations' => 3]);
    ensureField(EntriesField::class, ['name' => 'Featured destination', 'handle' => 'featuredDestination', 'maxRelations' => 1]);
    ensureField(PlainText::class, ['name' => 'Place', 'handle' => 'placeName', 'translationMethod' => 'site']);
    ensureField(PlainText::class, ['name' => 'Best time to visit', 'handle' => 'bestTime', 'translationMethod' => 'site']);
    ensureField(PlainText::class, ['name' => 'Travel time', 'handle' => 'travelTime']);
    ensureField(PlainText::class, ['name' => 'Footer text', 'handle' => 'footerText', 'translationMethod' => 'site']);
    ensureField(craft\fields\Dropdown::class, ['name' => 'Rating', 'handle' => 'rating', 'options' => array_map(
        fn(int $n) => ['label' => str_repeat('★', $n) . str_repeat('☆', 5 - $n), 'value' => (string)$n, 'default' => $n === 3],
        [5, 4, 3, 2, 1],
    )]);
    $tagsService = Craft::$app->getTags();
    if (!$tagGroup = $tagsService->getTagGroupByHandle('travelTags')) {
        $tagGroup = new craft\models\TagGroup(['name' => 'Travel tags', 'handle' => 'travelTags']);
        $tagGroup->setFieldLayout(layout(craft\elements\Tag::class, ['Content' => [new TitleField(), 'usedIn']]));
        $tagsService->saveTagGroup($tagGroup) or fail($tagGroup);
    }
    ensureField(craft\fields\Tags::class, ['name' => 'Tags', 'handle' => 'travelTags', 'source' => "taggroup:$tagGroup->uid"]);

    // the shop (Commerce): merch with an image, and a field for "shop the story"
    $productTypes = craft\commerce\Plugin::getInstance()->getProductTypes();
    if (!$merch = $productTypes->getProductTypeByHandle('merch')) {
        $merch = new craft\commerce\models\ProductType(['name' => 'Merch', 'handle' => 'merch', 'hasVariantTitleField' => false, 'variantTitleFormat' => '{product.title}', 'skuFormat' => '']);
        $primaryId = $sites->getPrimarySite()->id;
        $merch->setSiteSettings([$primaryId => new craft\commerce\models\ProductTypeSite(['siteId' => $primaryId, 'hasUrls' => false, 'enabledByDefault' => true])]);
        $merch->getBehavior('productFieldLayout')->setFieldLayout(layout(craft\commerce\elements\Product::class, [
            'Content' => [new TitleField(), ['image', ['providesThumbs' => true]], 'summary'],
            'Used in' => ['usedIn'],
        ]));
        $productTypes->saveProductType($merch) or fail($merch);
    }
    ensureField(craft\commerce\fields\Products::class, ['name' => 'Shop the story', 'handle' => 'shopProducts', 'maxRelations' => 2]);

    // content blocks for the homepage and pages
    $blocks = [
        ensureEntryType('hero', ['name' => 'Hero', 'icon' => 'image', 'color' => Color::Emerald, 'hasTitleField' => false], ['Content' => ['headline', ['heroImage', ['providesThumbs' => true]], 'buttonLabel', 'buttonLink']]),
        ensureEntryType('textBlock', ['name' => 'Text', 'icon' => 'paragraph', 'color' => Color::Gray, 'hasTitleField' => false], ['Content' => ['body']]),
        ensureEntryType('imageBlock', ['name' => 'Image', 'icon' => 'photo-film', 'color' => Color::Sky, 'hasTitleField' => false], ['Content' => [['image', ['providesThumbs' => true]], 'caption']]),
        ensureEntryType('featuredArticles', ['name' => 'Featured articles', 'icon' => 'newspaper', 'color' => Color::Amber, 'hasTitleField' => false], ['Content' => ['headline', 'articles']]),
        ensureEntryType('callToAction', ['name' => 'Call to action', 'icon' => 'bullhorn', 'color' => Color::Rose, 'hasTitleField' => false], ['Content' => ['headline', 'buttonLabel', 'buttonLink']]),
    ];
    ensureField(Matrix::class, ['name' => 'Content blocks', 'handle' => 'contentBlocks', 'entryTypes' => $blocks, 'viewMode' => Matrix::VIEW_MODE_BLOCKS]);
    $stop = ensureEntryType('stop', ['name' => 'Stop', 'icon' => 'location-dot', 'color' => Color::Orange], ['Content' => [new EntryTitleField(), 'destinations', 'travelTime', ['image', ['providesThumbs' => true]]]]);
    ensureField(Matrix::class, ['name' => 'Stops', 'handle' => 'stops', 'entryTypes' => [$stop], 'viewMode' => Matrix::VIEW_MODE_BLOCKS]);

    $article = ensureEntryType('article', ['name' => 'Article', 'icon' => 'newspaper', 'color' => Color::Blue], [
        'Content' => [new EntryTitleField(), ['heroImage', ['providesThumbs' => true]], 'summary', 'body', 'topics', 'travelTags', 'destinations', 'writtenBy', 'relatedArticles', 'shopProducts'],
        'SEO' => ['seo'],
        'Used in' => ['usedIn'],
    ]);
    $photoEssay = ensureEntryType('photoEssay', ['name' => 'Photo essay', 'icon' => 'camera', 'color' => Color::Fuchsia], [
        'Content' => [new EntryTitleField(), ['heroImage', ['providesThumbs' => true]], 'summary', 'gallery', 'destinations', 'travelTags', 'writtenBy'],
        'SEO' => ['seo'],
        'Used in' => ['usedIn'],
    ]);
    $route = ensureEntryType('route', ['name' => 'Route', 'icon' => 'route', 'color' => Color::Orange], [
        'Content' => [new EntryTitleField(), ['heroImage', ['providesThumbs' => true]], 'summary', 'stops', 'topics', 'writtenBy'],
        'Used in' => ['usedIn'],
    ]);
    $review = ensureEntryType('review', ['name' => 'Review', 'icon' => 'star', 'color' => Color::Yellow], [
        'Content' => [new EntryTitleField(), ['heroImage', ['providesThumbs' => true]], 'placeName', 'rating', 'body', 'destinations', 'travelTags', 'writtenBy'],
        'Used in' => ['usedIn'],
    ]);
    $destination = ensureEntryType('destination', ['name' => 'Destination', 'icon' => 'earth-asia', 'color' => Color::Cyan], [
        'Content' => [new EntryTitleField(), ['heroImage', ['providesThumbs' => true]], 'summary', 'bestTime'],
        'Used in' => ['usedIn'],
    ]);
    $person = ensureEntryType('person', ['name' => 'Person', 'icon' => 'user', 'color' => Color::Violet], [
        'Content' => [new EntryTitleField(), ['portrait', ['providesThumbs' => true]], 'jobTitle', 'summary'],
        'Used in' => ['usedIn'],
    ]);
    $page = ensureEntryType('contentPage', ['name' => 'Content page', 'icon' => 'file-lines', 'color' => Color::Teal], [
        'Content' => [new EntryTitleField(), 'contentBlocks', 'contactForm'],
        'SEO' => ['seo'],
        'Used in' => ['usedIn'],
    ]);
    $home = ensureEntryType('homepage', ['name' => 'Homepage', 'icon' => 'house', 'color' => Color::Green], [
        'Content' => [new EntryTitleField(), 'contentBlocks'],
        'Used in' => ['usedIn'],
    ]);
    ensureSection('homepage', 'Homepage', Section::TYPE_SINGLE, [$home], '__home__');
    $journal = ensureSection('journal', 'Journal', Section::TYPE_CHANNEL, [$article, $photoEssay, $route, $review], 'journal/{slug}');
    $destinations = ensureSection('destinations', 'Destinations', Section::TYPE_STRUCTURE, [$destination], 'destinations/{slug}');
    $team = ensureSection('team', 'Team', Section::TYPE_CHANNEL, [$person], 'team/{slug}');
    ensureSection('company', 'Company', Section::TYPE_STRUCTURE, [$page], 'company/{slug}');

    // the demo front end: dev/templates is the project's templates folder, each section renders with its template
    $templates = CRAFT_BASE_PATH . '/templates';
    if (!is_link($templates)) {
        if (is_dir($templates)) {
            rename($templates, "$templates-craft-default"); // Craft's welcome page
        }
        symlink(__DIR__ . '/templates', $templates);
    }
    foreach (['homepage' => 'index', 'journal' => 'journal/_entry', 'destinations' => 'destinations/_entry', 'team' => 'team/_entry', 'company' => 'company/_entry'] as $handle => $template) {
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);
        $siteSettings = $section->getSiteSettings();
        if (array_filter($siteSettings, fn($settings) => $settings->template !== $template)) {
            foreach ($siteSettings as $settings) {
                $settings->template = $template;
            }
            $section->setSiteSettings($siteSettings);
            Craft::$app->getEntries()->saveSection($section) or fail($section);
        }
    }

    // entries fields point at their sections (they exist only now)
    $fields = Craft::$app->getFields();
    foreach (['writtenBy' => $team, 'relatedArticles' => $journal, 'articles' => $journal, 'destinations' => $destinations, 'featuredDestination' => $destinations] as $handle => $section) {
        $f = field($handle);
        if ($f->sources !== ["section:$section->uid"]) {
            $f->sources = ["section:$section->uid"];
            $fields->saveField($f) or fail($f);
        }
    }

    // index columns: "Used in" next to the file info, entry type, authors and dates for the journal (merged into
    // whatever source settings exist, Craft adds the sources not listed)
    $usedInUid = field('usedIn')->uid;
    $documents = Craft::$app->getVolumes()->getVolumeByHandle('documents');
    foreach ([
        Asset::class => [
            "volume:$images->uid" => ['filename', 'size', 'dateModified', "field:$usedInUid"],
            "volume:$documents->uid" => ['filename', 'size', "field:$usedInUid"],
        ],
        Entry::class => [
            "section:$journal->uid" => ['type', 'authors', 'postDate', 'status', "field:$usedInUid"],
            "section:$destinations->uid" => ['postDate', "field:$usedInUid"],
        ],
    ] as $elementType => $columns) {
        $path = "elementSources.$elementType";
        $configs = $projectConfig->get($path) ?? [];
        foreach ($columns as $key => $attributes) {
            $index = array_search($key, array_column($configs, 'key'), true);
            $config = ['type' => 'native', 'key' => $key, 'tableAttributes' => $attributes];
            $index === false ? $configs[] = $config : $configs[$index] = $config + $configs[$index];
        }
        $projectConfig->set($path, array_values($configs));
    }

    // Craft Pro's rebrand: the Transit mark as the control panel icon
    $iconDir = Craft::$app->getPath()->getRebrandPath() . '/icon';
    if (!is_dir($iconDir) || !glob("$iconDir/*")) {
        craft\helpers\FileHelper::createDirectory($iconDir);
        file_put_contents("$iconDir/transit.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120">' . markSvg('icon') . '</svg>');
    }

    // site-wide settings as a global set
    $globals = Craft::$app->getGlobals();
    if (!$globals->getSetByHandle('siteSettings')) {
        $set = new craft\elements\GlobalSet(['name' => 'Site settings', 'handle' => 'siteSettings']);
        $set->setFieldLayout(layout(craft\elements\GlobalSet::class, ['Content' => ['logoImage', 'footerText', 'featuredDestination']]));
        $globals->saveSet($set) or fail($set);
    }

    // user photos live in the Images volume; an editors group
    if (!$projectConfig->get('users.photoVolumeUid')) {
        $projectConfig->set('users.photoVolumeUid', $images->uid);
    }
    $userGroups = Craft::$app->getUserGroups();
    if (!$editors = $userGroups->getGroupByHandle('editors')) {
        $editors = new UserGroup(['name' => 'Editors', 'handle' => 'editors']);
        $userGroups->saveGroup($editors) or fail($editors);
    }
    $projectConfig->saveModifiedConfigData();
    echo "Demo schema ready.\n";
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------
// Content
// ---------------------------------------------------------------------------------------------------------------
Craft::$app->getPlugins()->switchEdition('element-relations', 'pro');
$deSite = $sites->getSiteByHandle('de');

// photos by Frederic Köberl (unsplash.com/@internetztube), Unsplash ID => image path on images.unsplash.com
$photo = function (string $file, string $title, string $unsplashId, string $imagePath, int $seed) {
    return ensureAsset('images', $file, $title, fn(string $path) => unsplash($path, $imagePath, $seed), "$title. Photo: Frederic Köberl on Unsplash ($unsplashId)");
};
$skyline = $photo('train-and-city-towers.jpg', 'Train passing the city towers', 'UZavnbsGc40', 'photo-1684760224861-ab9aae14d153', 1);
$platform = $photo('waiting-on-the-platform.jpg', 'Waiting on the platform', 'b8jpobjHgVU', 'photo-1684871346551-b72adf1431fb', 2);
$costumes = $photo('costumes-on-the-subway.jpg', 'Costumes on the subway', 'Mq-TRcLIpE0', 'photo-1748016414138-befa946218dc', 3);
$flags = $photo('red-flags-and-golden-star.jpg', 'Red flags and a golden star', '4yGTh9mszaI', 'photo-1747995973820-dc4b51bcf13f', 4);
$street = $photo('street-with-parked-cars.jpg', 'A street lined with parked cars', 'W6e9Eas3Zu0', 'photo-1684933305826-b364a8c38256', 5);
$glassRoof = $photo('glass-roof.jpg', 'Glass roof in black and white', '7OjtJUPwTPs', 'photo-1684696220429-b7c468019364', 6);
$clock = $photo('train-under-the-station-clock.jpg', 'Train under the station clock', 'qXb7TuKWaSY', 'photo-1684934899514-772e03714de5', 7);
$photo('brick-building.jpg', 'Brick building with many windows', 'uXmX8F58-b0', 'photo-1684868314024-f063f6534c57', 8); // unused
$tunnel = $photo('into-the-tunnel.jpg', 'Into the dark tunnel', 'WzXvVof2PvI', 'photo-1679750184902-5992e3445eed', 9);
$stadium = $photo('beijing-national-stadium.jpg', 'Beijing National Stadium', 'rLCooDyRYqA', 'photo-1748015331424-e1a00584a42d', 10);
$temple = $photo('hong-kong-temple.jpg', 'At a Hong Kong temple', '_8NvDEEf8rU', 'photo-1748012727730-f5cee47b3a52', 11);
$river = $photo('afternoon-by-the-river.jpg', 'An afternoon by the river', 'ZVx38zMyPTc', 'photo-1684869238596-70d9c6962a12', 12);
$window = $photo('fields-through-the-train-window.jpg', 'Fields through the train window', 'PXbFK_2ClTE', 'photo-1684760176960-f26f57a71176', 13);
$photo('row-of-bikes.jpg', 'A row of bikes', 'cR5HOgnWexM', 'photo-1678220984856-e3ad7cc90285', 14); // unused
$bank = $photo('central-bank-of-taiwan.jpg', 'Central Bank of Taiwan', 'FeJen0cN8MA', 'photo-1748015460374-5666346918bc', 15);
$crowd = $photo('crowd-at-the-gate.jpg', 'A crowd in front of the gate', 'OtckXHgS6yU', 'photo-1684872225923-b93c46049543', 16);
$social = ensureAsset('images', 'social-share.svg', 'Social share image', 'socialImage');
$logo = ensureAsset('images', 'transit-logo.svg', 'Transit logo', 'logo');
$portraits = [
    'mara' => ensureAsset('images', 'mara-lindqvist.jpg', 'Mara Lindqvist', fn($p) => portrait($p, '#c7d9e8', '#3c5a78', 1)),
    'jonas' => ensureAsset('images', 'jonas-berger.jpg', 'Jonas Berger', fn($p) => portrait($p, '#e8d9c7', '#7a5236', 2)),
    'amelie' => ensureAsset('images', 'amelie-novak.jpg', 'Amelie Novak', fn($p) => portrait($p, '#d6e8cf', '#3f6b45', 3)),
    'theo' => ensureAsset('images', 'theo-hartmann.jpg', 'Theo Hartmann', fn($p) => portrait($p, '#e8cfd9', '#7a3a58', 4)),
];
$guide = ensureAsset('documents', 'rail-pass-guide-2026.pdf', 'Rail Pass Guide 2026', fn($p) => pdf($p, 'Transit Rail Pass Guide 2026', [
    'Which pass pays off, how to reserve seats, and the night trains worth booking.',
    'Twelve routes between cities, with travel times and the best window side.',
    'Printed on recycled paper. Please take the train.',
]));
ensureAsset('documents', 'station-map.pdf', 'Station map', fn($p) => pdf($p, 'Station map', ['Platforms, exits and the good noodle shop.'])); // unused

// categories
$topics = [];
foreach (['Rail' => 'Bahn', 'Cities' => 'Städte', 'Architecture' => 'Architektur', 'Street life' => 'Straßenleben', 'Guides' => 'Reiseführer'] as $en => $de) {
    $group = Craft::$app->getCategories()->getGroupByHandle('topics');
    $category = Category::find()->groupId($group->id)->title($en)->one();
    if (!$category) {
        $category = new Category(['groupId' => $group->id, 'title' => $en]);
        $elements->saveElement($category) or fail($category);
    }
    translate($category, $de);
    $topics[$en] = $category->id;
}

// users (authors) with photos
$users = [];
$editors = Craft::$app->getUserGroups()->getGroupByHandle('editors');
// what editors may: the control panel, every site, the demo's sections and volumes, replace and roll back; not
// "Delete or disable elements that are in use" (Element Relations Pro)
$editorPermissions = ['accessCp', 'element-relations-replace', 'element-relations-rollback'];
foreach ($sites->getAllSites() as $site) {
    $editorPermissions[] = "editSite:$site->uid";
}
foreach (['journal', 'destinations', 'team', 'company', 'homepage'] as $handle) {
    $uid = Craft::$app->getEntries()->getSectionByHandle($handle)->uid;
    foreach (['viewEntries', 'createEntries', 'saveEntries', 'deleteEntries', 'viewPeerEntries', 'savePeerEntries', 'deletePeerEntries'] as $permission) {
        $editorPermissions[] = "$permission:$uid";
    }
}
foreach (['images', 'documents'] as $handle) {
    $uid = Craft::$app->getVolumes()->getVolumeByHandle($handle)->uid;
    foreach (['viewAssets', 'saveAssets', 'deleteAssets', 'replaceFiles', 'editImages', 'viewPeerAssets', 'savePeerAssets', 'deletePeerAssets', 'replacePeerFiles', 'editPeerImages'] as $permission) {
        $editorPermissions[] = "$permission:$uid";
    }
}
// editors see the forms (read only): the forms that redirect to "Thank you" are named in its delete dialog, and
// Formie 4.0's form field fails on PostgreSQL for a user without any form
array_push($editorPermissions, 'formie-accessForms', 'formie-viewForms');
Craft::$app->getUserPermissions()->saveGroupPermissions($editors->id, $editorPermissions);
foreach (['mara' => ['Mara', 'Lindqvist'], 'jonas' => ['Jonas', 'Berger'], 'amelie' => ['Amelie', 'Novak']] as $key => [$first, $last]) {
    $user = User::find()->username($key)->status(null)->one();
    if (!$user) {
        $user = new User(['username' => $key, 'email' => "$key@example.com", 'firstName' => $first, 'lastName' => $last]);
        $elements->saveElement($user) or fail($user);
        Craft::$app->getUsers()->assignUserToGroups($user->id, [$editors->id]);
        Craft::$app->getUsers()->activateUser($user);
    }
    if ($key === 'mara' && !$user->admin) {
        // the editor the screenshots log in as (dev/screenshots.mts), with the dev project's password
        $user->admin = true;
        $user->newPassword = 'password';
        $elements->saveElement($user) or fail($user);
    }
    if ($key === 'jonas' && $user->password === null) {
        // a non-admin editor (Editors group) for the screenshots of what editors may not do, dev password too
        $user->newPassword = 'password';
        $elements->saveElement($user) or fail($user);
    }
    if ($user->photoId !== $portraits[$key]->id) {
        // straight to the column: saving a user with a photo copies it into the user photo volume, which is the test
        // fixtures' volume here (dev/seed/user-photo.php); the relations cache is rebuilt after the seed anyway
        craft\helpers\Db::update(craft\db\Table::USERS, ['photoId' => $portraits[$key]->id], ['id' => $user->id]);
    }
    $users[$key] = $user;
}
$admin = User::find()->username('admin')->one(); // stays "admin": the GUI test looks for that label

$daysAgo = fn(int $days) => (new DateTime())->modify("-$days days")->setTime(9, 0);

// team
$people = [];
foreach ([
    'mara' => ['Mara Lindqvist', 'Editor-in-chief', 'Chefredakteurin', 'Mara has never taken a flight she could replace with a sleeper train.'],
    'jonas' => ['Jonas Berger', 'Rail correspondent', 'Bahnkorrespondent', 'Jonas knows the timetable of lines he has not travelled yet.'],
    'amelie' => ['Amelie Novak', 'Architecture writer', 'Architekturautorin', 'Amelie looks up in every station hall and walks into people because of it.'],
    'theo' => ['Theo Hartmann', 'Photographer', 'Fotograf', 'Theo waits for the train to leave so the platform is his.'],
] as $key => [$name, $role, $roleDe, $bio]) {
    $people[$key] = ensureEntry('team', 'person', $name, ['portrait' => [$portraits[$key]->id], 'jobTitle' => $role, 'summary' => $bio], ['authorIds' => [$admin->id]]);
    $localized = Entry::find()->id($people[$key]->id)->siteId($deSite->id)->status(null)->one();
    if ($localized && $localized->getFieldValue('jobTitle') !== $roleDe) {
        $localized->setFieldValue('jobTitle', $roleDe);
        $elements->saveElement($localized) or fail($localized);
    }
}

// destinations (a structure: region › city)
$eastAsia = ensureEntry('destinations', 'destination', 'East Asia', ['heroImage' => [$window->id], 'summary' => 'High-speed lines, night markets and the best station food in the world.'], [], 'Ostasien');
$d = [];
foreach ([
    'beijing' => ['Beijing', 'Peking', $stadium, 'Wide avenues, old hutongs and the busiest stations we have seen.', 'Spring and autumn'],
    'hongkong' => ['Hong Kong', 'Hongkong', $temple, 'Harbour ferries, trams and temples between the towers.', 'October to December'],
    'taipei' => ['Taipei', 'Taipeh', $bank, 'Night markets, mountain trails at the end of the metro, and very good tea.', 'October to April'],
] as $key => [$title, $titleDe, $hero, $summary, $best]) {
    $d[$key] = ensureEntry('destinations', 'destination', $title, ['heroImage' => [$hero->id], 'summary' => $summary, 'bestTime' => $best], ['parentId' => $eastAsia->id], $titleDe);
}

// tags
$tag = function (string $title): int {
    $group = Craft::$app->getTags()->getTagGroupByHandle('travelTags');
    $tag = craft\elements\Tag::find()->groupId($group->id)->title($title)->one();
    if (!$tag) {
        $tag = new craft\elements\Tag(['groupId' => $group->id, 'title' => $title]);
        Craft::$app->getElements()->saveElement($tag) or fail($tag);
    }
    return $tag->id;
};

// the shop (Commerce)
$product = function (string $title, Asset $image, string $summary, string $sku, float $price): craft\commerce\elements\Product {
    $type = craft\commerce\Plugin::getInstance()->getProductTypes()->getProductTypeByHandle('merch');
    $product = craft\commerce\elements\Product::find()->typeId($type->id)->title($title)->status(null)->one();
    if (!$product) {
        $product = new craft\commerce\elements\Product(['typeId' => $type->id, 'title' => $title, 'enabled' => true]);
        $product->setVariants([new craft\commerce\elements\Variant(['title' => $title, 'sku' => $sku, 'basePrice' => $price, 'isDefault' => true])]);
        $product->setDirtyAttributes(['variants']); // setVariants() alone doesn't mark them dirty
    }
    // always: products have no revisions, and the image may be new (e.g. after a logo change)
    $product->setFieldValues(['image' => [$image->id], 'summary' => $summary]);
    Craft::$app->getElements()->saveElement($product) or fail($product);
    return $product;
};
$tote = $product('Transit tote bag', $logo, 'Fits a laptop, a book and two sandwiches. Organic cotton.', 'TRANSIT-TOTE', 24);
$print = $product('"Glass roof" photo print', $glassRoof, 'A3, black and white, signed by the photographer.', 'TRANSIT-PRINT-ROOF', 45);

// journal
$a = [];
$a['rails'] = ensureEntry('journal', 'article', 'Twelve hours on the rails', [
    'heroImage' => [$skyline->id],
    'summary' => 'From one skyline to the next without leaving the train: what a long day of rail travel teaches you.',
    'body' => '<p>We boarded before sunrise and stepped off after dark, with four changes, one missed connection and more noodles than planned.</p><p>The trick is not speed. It is a window seat, a full water bottle and a timetable printed on paper, just in case.</p>',
    'topics' => [$topics['Rail'], $topics['Cities']],
    'travelTags' => [$tag('Skylines')],
    'destinations' => [$d['beijing']->id, $d['hongkong']->id],
    'writtenBy' => [$people['mara']->id],
    'shopProducts' => [$tote->id],
    'seo' => ['metaBundleSettings' => ['seoImageSource' => 'fromAsset', 'seoImageIds' => [(string)$skyline->id]]],
], ['postDate' => $daysAgo(64), 'authorIds' => [$users['mara']->id]], 'Zwölf Stunden auf Schienen');

$a['station'] = ensureEntry('journal', 'article', 'How to read a station', [
    'heroImage' => [$clock->id],
    'summary' => 'Clocks, boards, platform letters: a short guide to finding your train in a hurry.',
    'body' => sprintf(
        '<p>Every station tells you where to go, if you know where to look. On <a href="%s">our twelve-hour day</a> we changed trains four times.</p><p><img src="%s" alt="Train passing the city towers"></p><p>Look for the clock first: the departure boards are always nearby.</p>',
        ref($a['rails']), ref($skyline),
    ),
    'topics' => [$topics['Rail'], $topics['Guides']],
    'travelTags' => [$tag('Stations')],
    'writtenBy' => [$people['jonas']->id],
    'relatedArticles' => [$a['rails']->id],
    'shopProducts' => [$print->id],
], ['postDate' => $daysAgo(41), 'authorIds' => [$users['jonas']->id]], 'Wie man einen Bahnhof liest');

$a['stadium'] = ensureEntry('journal', 'article', 'Steel, glass and a bird\'s nest', [
    'heroImage' => [$stadium->id],
    'summary' => 'A walk through the architecture you see from the train, and the one stadium worth getting off for.',
    'body' => sprintf(
        '<p>Some buildings are best seen from a moving window. Others deserve a stop. Our <a href="%s">Rail Pass Guide 2026</a> lists the stations closest to each.</p>',
        ref($guide),
    ),
    'topics' => [$topics['Architecture'], $topics['Cities']],
    'destinations' => [$d['beijing']->id],
    'writtenBy' => [$people['amelie']->id],
], ['postDate' => $daysAgo(30), 'authorIds' => [$users['amelie']->id]], 'Stahl, Glas und ein Vogelnest');

$a['packing'] = ensureEntry('journal', 'article', 'Packing light for a week of trains', [
    'heroImage' => [$window->id],
    'summary' => 'One bag that fits under the seat, and the three things we never travel without.',
    'body' => sprintf('<p>Earplugs, a scarf, a power bank. The rest of the planning is in <a href="%s">how to read a station</a>.</p>', ref($a['station'])),
    'topics' => [$topics['Rail'], $topics['Guides']],
    'writtenBy' => [$people['mara']->id, $people['jonas']->id],
    'relatedArticles' => [$a['rails']->id, $a['station']->id],
], ['postDate' => $daysAgo(18), 'authorIds' => [$users['mara']->id]], 'Leicht packen für eine Woche Zug');

$a['temple'] = ensureEntry('journal', 'article', 'Incense and quiet in Hong Kong', [
    'heroImage' => [$temple->id],
    'summary' => 'Ten minutes from the busiest station in town, a temple where nobody is in a hurry. A photo essay.',
    'topics' => [$topics['Cities'], $topics['Street life']],
    'destinations' => [$d['hongkong']->id],
    'writtenBy' => [$people['theo']->id],
    'relatedArticles' => [$a['rails']->id],
], ['postDate' => $daysAgo(11), 'authorIds' => [$users['amelie']->id]], 'Räucherstäbchen und Stille in Hongkong');

$a['costumes'] = ensureEntry('journal', 'article', 'Costume day on the subway', [
    'heroImage' => [$costumes->id],
    'summary' => 'Once a year the morning commute turns into a parade. We rode along.',
    'topics' => [$topics['Street life'], $topics['Cities']],
    'writtenBy' => [$people['amelie']->id, $people['theo']->id],
], ['postDate' => $daysAgo(5), 'authorIds' => [$users['amelie']->id]], 'Kostümtag in der U-Bahn');

$a['why'] = ensureEntry('journal', 'article', 'Why we take the train', [
    'heroImage' => [$platform->id],
    'summary' => 'Slower, cheaper than you think, and the only way to arrive in the middle of town.',
    'body' => '<p>A train arrives where the city is. That alone is worth the extra hour.</p>',
    'topics' => [$topics['Rail'], $topics['Guides']],
    'writtenBy' => [$people['jonas']->id],
], ['postDate' => $daysAgo(3), 'authorIds' => [$users['jonas']->id]], 'Warum wir Zug fahren');

$a['night'] = ensureEntry('journal', 'article', 'Night trains are back', [
    'heroImage' => [$tunnel->id],
    'summary' => 'Coming soon: the sleeper routes worth booking this winter.',
    'topics' => [$topics['Rail']],
    'writtenBy' => [$people['jonas']->id],
], ['postDate' => (new DateTime())->modify('+12 days')->setTime(9, 0), 'authorIds' => [$users['jonas']->id]], 'Die Nachtzüge sind zurück'); // pending

ensureEntry('journal', 'article', 'Holiday timetable 2025', [
    'heroImage' => [$glassRoof->id],
    'summary' => 'Last year\'s holiday timetable (archived).',
    'topics' => [$topics['Rail']],
], ['postDate' => $daysAgo(400), 'expiryDate' => $daysAgo(200), 'authorIds' => [$users['mara']->id]], 'Feiertagsfahrplan 2025'); // expired

// the other journal entry types: photo essay, route, reviews
ensureEntry('journal', 'photoEssay', 'Platforms', [
    'heroImage' => [$platform->id],
    'summary' => 'Five stations, five minutes before departure.',
    'gallery' => [$platform->id, $clock->id, $glassRoof->id, $tunnel->id, $crowd->id],
    'travelTags' => [$tag('Stations')],
    'writtenBy' => [$people['theo']->id],
], ['postDate' => $daysAgo(24), 'authorIds' => [$users['amelie']->id]], 'Bahnsteige');
ensureEntry('journal', 'route', 'Beijing to Hong Kong in one day', [
    'heroImage' => [$window->id],
    'summary' => 'Two thousand kilometres, one seat, and a lot of tea.',
    'stops' => [
        'new1' => ['type' => 'stop', 'enabled' => true, 'title' => 'Beijing West', 'fields' => ['destinations' => [$d['beijing']->id], 'travelTime' => 'Departure 08:00', 'image' => [$stadium->id]]],
        'new2' => ['type' => 'stop', 'enabled' => true, 'title' => 'Hong Kong West Kowloon', 'fields' => ['destinations' => [$d['hongkong']->id], 'travelTime' => '8 h 56 min', 'image' => [$temple->id]]],
    ],
    'topics' => [$topics['Rail']],
    'writtenBy' => [$people['jonas']->id],
], ['postDate' => $daysAgo(14), 'authorIds' => [$users['jonas']->id]], 'In einem Tag von Peking nach Hongkong');
ensureEntry('journal', 'review', 'The noodle shop on platform 3', [
    'heroImage' => [$street->id],
    'placeName' => 'Platform 3, Taipei Main Station',
    'rating' => '5',
    'body' => '<p>Six seats, one dish, no menu. Order before the train is announced.</p>',
    'destinations' => [$d['taipei']->id],
    'travelTags' => [$tag('Noodles')],
    'writtenBy' => [$people['mara']->id],
], ['postDate' => $daysAgo(9), 'authorIds' => [$users['mara']->id]], 'Der Nudelladen an Gleis 3');
ensureEntry('journal', 'review', 'A night in the sleeper cabin', [
    'heroImage' => [$tunnel->id],
    'placeName' => 'Overnight sleeper, berth 23',
    'rating' => '4',
    'body' => '<p>Clean sheets, a reading lamp, and a conductor who knocks at six with tea. One star off for the snoring above.</p>',
    'travelTags' => [$tag('Sleeper trains'), $tag('Night')],
    'writtenBy' => [$people['jonas']->id],
], ['postDate' => $daysAgo(2), 'authorIds' => [$users['jonas']->id]], 'Eine Nacht im Schlafwagen');

// a draft that swaps the hero image
if (!Entry::find()->draftOf($a['why']->id)->status(null)->exists()) {
    $draft = Craft::$app->getDrafts()->createDraft($a['why'], $users['jonas']->id, 'New hero image', 'Try the tunnel photo');
    $draft->setFieldValue('heroImage', [$tunnel->id]);
    $elements->saveElement($draft) or fail($draft);
}

// an article nobody has published yet: only a draft (the list's "Drafts")
$journal = Craft::$app->getEntries()->getSectionByHandle('journal');
if (!Entry::find()->sectionId($journal->id)->title('A ferry to Busan')->drafts(true)->status(null)->exists()) {
    $draft = new Entry([
        'sectionId' => $journal->id,
        'typeId' => Craft::$app->getEntries()->getEntryTypeByHandle('article')->id,
        'title' => 'A ferry to Busan',
        'authorIds' => [$users['mara']->id],
    ]);
    $draft->setFieldValue('heroImage', [$river->id]);
    Craft::$app->getDrafts()->saveElementAsDraft($draft, $users['mara']->id, null, null, false) or fail($draft);
}

// in the trash: an article (its usage is listed nowhere until it is restored) and a document
if (!Entry::find()->sectionId($journal->id)->title('Station guide 2019')->trashed(true)->status(null)->exists()) {
    $outdated = ensureEntry('journal', 'article', 'Station guide 2019', [
        'heroImage' => [$glassRoof->id],
        'body' => '<p>Replaced by the Rail Pass Guide 2026.</p>',
    ], ['postDate' => $daysAgo(2400), 'authorIds' => [$users['jonas']->id]]);
    // its relations now: the queued refresh would only run once it is in the trash
    foreach (Entry::find()->id($outdated->id)->site('*')->status(null)->all() as $localized) {
        internetztube\elementRelations\services\ExtractorService::refreshRelationsForElement($localized);
    }
    $elements->deleteElement($outdated) or fail($outdated);
}
if (!Asset::find()->filename('timetable-2019.pdf')->trashed(true)->exists()) {
    $timetable = ensureAsset('documents', 'timetable-2019.pdf', 'Timetable 2019', fn($p) => pdf($p, 'Timetable 2019', ['Superseded by the 2026 guide.']));
    $elements->deleteElement($timetable) or fail($timetable);
}

// many short articles, 30 per destination, sharing one image: the usage lists of the image and of each destination run
// over several pages (the field shows 20 a page), for ticking across pages and the wizard's "All … in the list"
$notesImage = ensureAsset('images', 'station-notes.jpg', 'Station notes', fn(string $path) => landscape($path, [
    'top' => '#0b1026', 'bottom' => '#2e3a6e', 'sun' => '#f5f3ce', 'layers' => ['#242c52', '#181e3b', '#0d1124'], 'night' => true,
], 14));
$stationsByDestination = [
    'beijing' => ['Beijing South', 'Beijing West', 'Beijing North', 'Beijing Chaoyang', 'Qianmen', 'Dongzhimen'],
    'hongkong' => ['Hung Hom', 'West Kowloon', 'Central', 'Admiralty', 'Mong Kok East', 'Tsim Sha Tsui'],
    'taipei' => ['Taipei Main', 'Songshan', 'Banqiao', 'Nangang', 'Zhongshan', 'Beitou'],
];
$writers = array_values($users);
$noteTitles = [];
$n = 0;
foreach ($stationsByDestination as $destination => $stations) {
    for ($i = 1; $i <= 30; $i++) {
        $station = $stations[($i - 1) % count($stations)];
        $noteTitles[] = $title = "Station notes: $station #$i";
        ensureEntry('journal', 'article', $title, [
            'heroImage' => [$notesImage->id],
            'destinations' => [$d[$destination]->id],
            'body' => "<p>Note $i from $station: where the good bench is, which exit has the lift, when the last train really leaves.</p>",
        ], ['postDate' => $daysAgo(100 + ++$n), 'authorIds' => [$writers[$n % count($writers)]->id]]);
    }
}
foreach (Entry::find()->sectionId($journal->id)->title('Station notes: *')->status(null)->all() as $stale) { // earlier seeds
    if (!in_array($stale->title, $noteTitles, true)) {
        $elements->deleteElement($stale, true);
    }
}

// pages
$about = ensureEntry('company', 'contentPage', 'About us', ['contentBlocks' => [
    'new1' => ['type' => 'hero', 'enabled' => true, 'fields' => ['headline' => 'A small team that would rather be on a train', 'heroImage' => [$street->id]]],
    'new2' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['body' => '<p>Transit started as a newsletter written on night trains in 2016. Today we are six writers and one photographer.</p>']],
    'new3' => ['type' => 'imageBlock', 'enabled' => true, 'fields' => ['image' => [$flags->id], 'caption' => 'Our first story: a station with a view.']],
]], ['authorIds' => [$admin->id]], 'Über uns');
$slow = ensureEntry('company', 'contentPage', 'Travel slowly', ['contentBlocks' => [
    'new1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['body' => sprintf('<p>Fewer flights, more windows. Read <a href="%s">why we take the train</a>.</p>', ref($a['why']))]],
    'new2' => ['type' => 'imageBlock', 'enabled' => true, 'fields' => ['image' => [$river->id], 'caption' => 'Ten minutes from the station.']],
]], ['authorIds' => [$admin->id]], 'Langsam reisen');
$thanks = ensureEntry('company', 'contentPage', 'Thank you', [], ['authorIds' => [$admin->id]], 'Danke');

// forms (Formie): contact and newsletter, both redirect to "Thank you"; the newsletter mails the rail pass guide
$forms = [];
foreach (['contactUs' => 'Contact us', 'newsletter' => 'Newsletter'] as $handle => $title) {
    $form = verbb\formie\elements\Form::find()->handle($handle)->status(null)->one();
    if (!$form) {
        $factory = new verbb\formie\factories\FormFactory(['title' => $title, 'handle' => $handle]);
        $factory = $handle === 'contactUs'
            ? $factory->singleLineTextField('yourName', ['label' => 'Name'])->emailField('email', ['label' => 'Email'])->multiLineTextField('message', ['label' => 'Message'])
            : $factory->emailField('email', ['label' => 'Email']);
        $form = $factory->completion('entry')->create();
    }
    $form->redirectEntryId = $thanks->id;
    $form->redirectEntrySiteId = $thanks->siteId;
    if ($handle === 'newsletter') {
        // always (re)point the attachment at the current guide, also on forms from an earlier demo run
        $notification = $form->getNotifications()[0] ?? new verbb\formie\models\Notification([
            'name' => 'Welcome', 'handle' => 'welcome', 'enabled' => true, 'subject' => 'Your Rail Pass Guide 2026', 'to' => '{field:email}',
        ]);
        $notification->attachAssets = [['id' => $guide->id]];
        $form->setNotifications([$notification]);
    }
    $elements->saveElement($form) or fail($form);
    $forms[$handle] = $form;
}
$contact = ensureEntry('company', 'contentPage', 'Contact', [
    'contactForm' => [$forms['contactUs']->id],
    'contentBlocks' => ['new1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['body' => '<p>A route we should ride, a station we should see? Write to us.</p>']]],
], ['authorIds' => [$admin->id]], 'Kontakt');

// homepage (a single)
$homepage = Entry::find()->section('homepage')->one();
if (!$homepage->getFieldValue('contentBlocks')->exists()) {
    $homepage->title = 'Homepage';
    $homepage->setFieldValue('contentBlocks', [
        'new1' => ['type' => 'hero', 'enabled' => true, 'fields' => [
            'headline' => 'Cities, and the trains between them', 'heroImage' => [$skyline->id], 'buttonLabel' => 'Read the journal',
            'buttonLink' => ['type' => 'entry', 'value' => $a['rails']->id],
        ]],
        'new2' => ['type' => 'featuredArticles', 'enabled' => true, 'fields' => ['headline' => 'From the journal', 'articles' => [$a['costumes']->id, $a['packing']->id, $a['stadium']->id]]],
        'new3' => ['type' => 'imageBlock', 'enabled' => true, 'fields' => ['image' => [$window->id], 'caption' => 'The best seat is by the window.']],
        'new4' => ['type' => 'callToAction', 'enabled' => true, 'fields' => [
            'headline' => 'Plan your trip', 'buttonLabel' => 'Download the Rail Pass Guide 2026',
            'buttonLink' => ['type' => 'asset', 'value' => $guide->id],
        ]],
    ]);
    $elements->saveElement($homepage) or fail($homepage);
}
translate($homepage, 'Startseite');

// navigation (verbb): main and footer menus
$menus = verbb\navigation\Navigation::$plugin->getMenus();
foreach ([
    'mainNavigation' => ['Main navigation', [
        ['Home', verbb\navigation\nodetypes\Entry::class, $homepage],
        ['Journal', verbb\navigation\nodetypes\Custom::class, '/journal'],
        ['About us', verbb\navigation\nodetypes\Entry::class, $about],
        ['Contact', verbb\navigation\nodetypes\Entry::class, $contact],
    ]],
    'footer' => ['Footer', [
        ['Travel slowly', verbb\navigation\nodetypes\Entry::class, $slow],
        ['Rail Pass Guide 2026 (PDF)', verbb\navigation\nodetypes\Asset::class, $guide],
    ]],
] as $handle => [$name, $nodes]) {
    if (!$menu = $menus->getMenuByHandle($handle)) {
        $menu = new verbb\navigation\models\MenuSettings(['name' => $name, 'handle' => $handle]);
        $menu->setSiteSettings(array_map(fn(Site $site) => new verbb\navigation\models\MenuSiteSettings(['siteId' => $site->id, 'enabled' => true]), $sites->getAllSites()));
        $menus->saveMenu($menu) or fail($menu);
    }
    foreach ($nodes as [$title, $type, $target]) {
        if (verbb\navigation\elements\Node::find()->menuId($menu->id)->title($title)->status(null)->exists()) {
            continue;
        }
        $node = new verbb\navigation\elements\Node(['menuId' => $menu->id, 'siteId' => $sites->getPrimarySite()->id, 'type' => $type, 'title' => $title]);
        is_string($target) ? $node->url = $target : $node->elementId = $target->id;
        $elements->saveElement($node) or fail($node);
    }
}

// the global set
$settings = Craft::$app->getGlobals()->getSetByHandle('siteSettings');
$settings->setFieldValues(['logoImage' => [$logo->id], 'footerText' => 'Transit. Cities, and the trains between them.', 'featuredDestination' => [$d['taipei']->id]]);
$elements->saveElement($settings) or fail($settings);

// SEOmatic: the logo as the site's identity image; the German site's social image. The English global SEO and Twitter
// images belong to the smoke test (dev/seed/seomatic.php), which counts on exactly what it set.
$seomatic = nystudio107\seomatic\Seomatic::$plugin;
foreach ($sites->getAllSites() as $site) {
    $bundle = $seomatic->metaBundles->getGlobalMetaBundle($site->id);
    if (!$bundle) {
        continue;
    }
    if (!$site->primary) {
        $bundle->metaBundleSettings->seoImageSource = 'fromAsset';
        $bundle->metaBundleSettings->seoImageIds = [$social->id];
        $bundle->metaBundleSettings->twitterImageSource = 'fromAsset';
        $bundle->metaBundleSettings->twitterImageIds = [$social->id];
    }
    $bundle->metaSiteVars->siteName = 'Transit';
    $bundle->metaSiteVars->identity->genericName = 'Transit';
    $bundle->metaSiteVars->identity->genericImageIds = [$logo->id];
    $seomatic->metaBundles->updateMetaBundle($bundle, $site->id);
}

Craft::$app->getProjectConfig()->saveModifiedConfigData();
echo "Demo content ready.\n";

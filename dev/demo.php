<?php

declare(strict_types=1);

/**
 * Demo site for screenshots and product reviews: "Transit", a fictional travel journal about cities and the trains
 * between them, with a journal, team, pages, a homepage built from content blocks and two sites. Photos by Frederic
 * Köberl (unsplash.com/@internetztube) are downloaded on the first run; without network a drawn picture takes their
 * place. Portraits, logo and PDFs are drawn here. Idempotent.
 *
 * The Craft 6 version of the 4.x demo: none of the plugins it shows there (CKEditor, Formie, SEOmatic, Commerce,
 * Navigation) has a Craft 6 release, so the body is Craft's Markdown field and there are no forms, menus, products or
 * SEO settings; no categories, tags or global set either (Craft 6 has them only through its Yii2 adapter). No front end.
 *
 * Lives next to the test fixtures of dev/seed.php (section "Pages", "Page via …" entries) in the same project; dev.sh
 * seed runs both, so CI proves the demo too. Its handles must not collide with the fixtures: the tests count on them.
 * Run: dev/dev.sh seed   (or: php dev/demo.php <craft-project-dir> schema|content, two processes: fields made in one
 *      process are not reliably writable in the same process)
 */

require __DIR__ . '/craft.php';
// a run that failed midway left Craft's project config lock behind
Illuminate\Support\Facades\Cache::lock(CraftCms\Cms\ProjectConfig\ProjectConfig::MUTEX_NAME)->forceRelease();

use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Folders;
use CraftCms\Cms\Asset\Validation\AssetRules;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Assets as AssetsField;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Dropdown;
use CraftCms\Cms\Field\Entries as EntriesField;
use CraftCms\Cms\Field\Link;
use CraftCms\Cms\Field\Markdown;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\FieldLayoutElement;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\Assets\AltField;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\Entries\EntryTitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Shared\Enums\Color;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\UserPermissions;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\User\Data\UserGroup;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Internetztube\ElementRelations\Fields\ElementRelationsField;
use Internetztube\ElementRelations\Services\ExtractorService;

function fail(object $model): never
{
    $errors = method_exists($model, 'errors') ? $model->errors()->all() : [];
    throw new RuntimeException($model::class . ': ' . json_encode($errors));
}

function field(string $handle): FieldInterface
{
    return Fields::getFieldByHandle($handle) ?? throw new RuntimeException("No field $handle");
}

/**
 * Creates a field, or saves an existing one whose settings changed since the last seed.
 * @param class-string<FieldInterface> $class
 * @param array<string, mixed> $config
 */
function ensureField(string $class, array $config): FieldInterface
{
    $field = Fields::getFieldByHandle($config['handle']);
    if ($field && array_filter($config, fn($value, $key) => $field->$key !== $value, ARRAY_FILTER_USE_BOTH) === []) {
        return $field;
    }
    if ($field) {
        foreach ($config as $key => $value) {
            $field->$key = $value;
        }
    } else {
        $field = Fields::createField(['type' => $class, ...$config]);
    }
    Fields::saveField($field) or fail($field);
    Fields::refreshFields();
    return field($config['handle']);
}

/**
 * A layout element: a field handle, [handle, ['thumb' => true]] (the element's thumbnail), or a layout element.
 * @param array{0: string, 1: array{thumb?: bool}}|string|FieldLayoutElement $element
 */
function layoutElement(array|string|FieldLayoutElement $element, FieldLayout $layout): FieldLayoutElement
{
    if ($element instanceof FieldLayoutElement) {
        return $element;
    }
    $custom = CustomField::make(field(is_array($element) ? $element[0] : $element));
    $custom->uid = (string)Str::uuid();
    if (is_array($element) && ($element[1]['thumb'] ?? false)) {
        $layout->thumbFieldKey = $custom->key();
    }
    return $custom;
}

/**
 * A layout. Craft puts an asset's title in its "Content" tab itself; an entry type with titles needs its EntryTitleField.
 * @param class-string<ElementInterface> $type
 * @param array<string, list<mixed>> $tabs tab name => elements (see layoutElement())
 */
function layout(string $type, array $tabs): FieldLayout
{
    $layout = FieldLayout::make($type);
    foreach ($tabs as $name => $elements) {
        $layout->tab($name, function (FieldLayoutTab $tab) use ($elements, $layout): void {
            foreach ($elements as $element) {
                $tab->add(layoutElement($element, $layout));
            }
        });
    }
    return $layout;
}

/** @param array<string, list<mixed>> $tabs */
function ensureEntryType(string $handle, array $config, array $tabs): EntryType
{
    if ($type = EntryTypes::getEntryTypeByHandle($handle)) {
        // add fields the demo gained since; never replace the layout (content is stored by layout element uid)
        $layout = $type->getFieldLayout();
        if (addMissingFields($layout, $tabs)) {
            $type->setFieldLayout($layout);
            EntryTypes::saveEntryType($type) or fail($type);
        }
        return $type;
    }
    $type = new EntryType(['handle' => $handle, ...$config]);
    $type->setFieldLayout(layout(Entry::class, $tabs));
    EntryTypes::saveEntryType($type) or fail($type);
    return EntryTypes::getEntryTypeByHandle($handle) ?? throw new RuntimeException("entry type $handle");
}

/**
 * Appends the custom fields of `$tabs` that the layout lacks to their tab (created if missing).
 * @param array<string, list<mixed>> $tabs
 */
function addMissingFields(FieldLayout $layout, array $tabs): bool
{
    $changed = false;
    foreach ($tabs as $name => $elements) {
        foreach ($elements as $element) {
            $handle = is_string($element) ? $element : (is_array($element) ? $element[0] : null);
            if ($handle === null || $layout->getFieldByHandle($handle)) {
                continue;
            }
            $tab = array_values(array_filter($layout->getTabs(), fn(FieldLayoutTab $t) => $t->name === $name))[0] ?? null;
            $add = layoutElement($element, $layout);
            $tab ? $tab->add($add) : $layout->tab($name, fn(FieldLayoutTab $new) => $new->add($add));
            $changed = true;
        }
    }
    return $changed;
}

/** @param list<EntryType> $entryTypes */
function ensureSection(string $handle, string $name, SectionType $sectionType, array $entryTypes, ?string $uriFormat): Section
{
    if ($section = Sections::getSectionByHandle($handle)) {
        $known = array_map(fn(EntryType $t) => $t->id, $section->getEntryTypes());
        $missing = array_filter($entryTypes, fn(EntryType $t) => !in_array($t->id, $known, true));
        if ($missing) {
            $section->entryTypes = [...$section->getEntryTypes(), ...$missing];
            Sections::saveSection($section) or fail($section);
        }
        return $section;
    }
    $siteSettings = [];
    foreach (Sites::getAllSites() as $site) {
        $siteSettings[$site->id] = new SectionSiteSettings(['siteId' => $site->id, 'hasUrls' => $uriFormat !== null, 'uriFormat' => $uriFormat, 'template' => '']);
    }
    $section = new Section([
        'name' => $name,
        'handle' => $handle,
        'type' => $sectionType,
        'maxLevels' => $sectionType === SectionType::Structure ? 2 : null,
        'entryTypes' => $entryTypes,
        'siteSettings' => $siteSettings,
    ]);
    Sections::saveSection($section) or fail($section);
    return Sections::getSectionByHandle($handle) ?? throw new RuntimeException("section $handle");
}

/** A volume on Laravel's public disk (served from /storage, see `artisan storage:link`). */
function ensureVolume(string $handle, string $name): Volume
{
    if ($volume = Volumes::getVolumeByHandle($handle)) {
        return $volume;
    }
    $volume = new Volume(['name' => $name, 'handle' => $handle, 'fsHandle' => 'public', 'subpath' => "demo-$handle", 'hasUrls' => true]);
    $volume->setFieldLayout(layout(Asset::class, ['Content' => [new AltField(), 'usedIn']]));
    Volumes::saveVolume($volume) or fail($volume);
    return Volumes::getVolumeByHandle($handle) ?? throw new RuntimeException("volume $handle");
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

/** A photo from Unsplash (images.unsplash.com/<path>, 1600px wide), or a drawn landscape when offline. */
function unsplash(string $path, string $imagePath, int $seed): void
{
    try {
        Http::timeout(30)->sink($path)->get("https://images.unsplash.com/$imagePath?w=1600&q=80&fm=jpg&fit=max");
        if (@getimagesize($path)) {
            return;
        }
    } catch (Throwable $e) {
        Log::warning("Demo photo $imagePath not downloaded: {$e->getMessage()}");
    }
    $palettes = [
        ['top' => '#1d2433', 'bottom' => '#e98b6d', 'sun' => '#ffd9a8', 'layers' => ['#5c6680', '#3b4560', '#20283a']],
        ['top' => '#3d5a80', 'bottom' => '#e0fbfc', 'sun' => '#ffffff', 'layers' => ['#98c1d9', '#5b7fa3', '#293241'], 'lake' => true],
        ['top' => '#0b1026', 'bottom' => '#2e3a6e', 'sun' => '#f5f3ce', 'layers' => ['#242c52', '#181e3b', '#0d1124'], 'night' => true],
    ];
    landscape($path, $palettes[$seed % 3], $seed);
}

function ensureAsset(string $volumeHandle, string $filename, string $title, callable $draw, ?string $alt = null): Asset
{
    $volume = Volumes::getVolumeByHandle($volumeHandle) ?? throw new RuntimeException("volume $volumeHandle");
    if ($asset = Asset::find()->volumeId($volume->id)->filename($filename)->one()) {
        return $asset;
    }
    // a file left from an earlier install (dev.sh reset keeps the uploads) would get the new asset a suffixed name
    $file = trim("$volume->subpath/$filename", '/');
    if (Storage::disk((string)$volume->fsHandle)->exists($file)) {
        Storage::disk((string)$volume->fsHandle)->delete($file);
    }
    $tmp = sys_get_temp_dir() . '/demo-' . uniqid() . '-' . $filename;
    $draw($tmp);
    $asset = Elements::createElement([
        'type' => Asset::class,
        'volumeId' => $volume->id,
        'newFolderId' => app(Folders::class)->getRootFolderByVolumeId((int)$volume->id)->id,
        'tempFilePath' => $tmp,
        'newFilename' => $filename,
        'title' => $title,
        'alt' => $alt,
        'avoidFilenameConflicts' => true,
    ]);
    $asset->ruleset->useScenario(AssetRules::SCENARIO_CREATE);
    Elements::saveElement($asset) or fail($asset);
    return $asset;
}

/**
 * Creates an entry once (existing ones are left alone, so re-runs add no revisions).
 * @param array<string, mixed> $fields
 * @param array<string, mixed> $attributes e.g. postDate, authorIds, enabled, expiryDate, parentId
 */
function ensureEntry(string $section, string $type, string $title, array $fields = [], array $attributes = [], ?string $titleDe = null): Entry
{
    $sectionModel = Sections::getSectionByHandle($section) ?? throw new RuntimeException("section $section");
    $entry = Entry::find()->sectionId($sectionModel->id)->title($title)->status(null)->one();
    if (!$entry) {
        $entry = new Entry([
            'sectionId' => $sectionModel->id,
            'typeId' => EntryTypes::getEntryTypeByHandle($type)?->id,
            'title' => $title,
            ...$attributes,
        ]);
        $entry->setFieldValues($fields);
        Elements::saveElement($entry) or fail($entry);
    }
    if ($titleDe) {
        translate($entry, $titleDe);
    }
    return $entry;
}

/** The German title (the rest of the content is shared between the sites). */
function translate(ElementInterface $element, string $title): void
{
    $de = Sites::getSiteByHandle('de') ?? throw new RuntimeException('de');
    $localized = $element::find()->id($element->id)->siteId($de->id)->status(null)->one();
    if ($localized && $localized->title !== $title) {
        $localized->title = $title;
        $localized->slug = ElementHelper::generateSlug($title, null, 'de');
        Elements::saveElement($localized) or fail($localized);
    }
}

/** A reference tag to the element, for the Markdown body. */
function ref(ElementInterface $element): string
{
    $kind = $element instanceof Asset ? 'asset' : 'entry';
    return sprintf('{%s:%d@%d:url||%s}', $kind, $element->id, $element->siteId, $element->getUrl() ?: 'https://example.com');
}

$phase = $argv[2] ?? 'schema';

if ($phase === 'schema') {
    // the demo shows Craft Pro (several authors, user groups)
    Edition::set(Edition::Pro);
    ProjectConfig::set('system.name', 'Transit');

    $primary = Sites::getPrimarySite();
    if ($primary->name !== 'English') {
        $primary->name = 'English';
        Sites::saveSite($primary) or fail($primary);
    }

    ensureField(ElementRelationsField::class, ['name' => 'Used in', 'handle' => 'usedIn', 'instructions' => 'Everything that links to this in this site. Other sites are listed below.']);
    $images = ensureVolume('images', 'Images');
    $documents = ensureVolume('documents', 'Documents');

    $imageField = fn(string $name, string $handle) => ensureField(AssetsField::class, [
        'name' => $name, 'handle' => $handle, 'sources' => ["volume:$images->uid"], 'maxRelations' => 1, 'viewMode' => 'large',
    ]);
    $imageField('Hero image', 'heroImage');
    $imageField('Image', 'image');
    $imageField('Portrait', 'portrait');
    ensureField(PlainText::class, ['name' => 'Headline', 'handle' => 'headline']);
    ensureField(PlainText::class, ['name' => 'Summary', 'handle' => 'summary', 'multiline' => true, 'initialRows' => 3]);
    ensureField(PlainText::class, ['name' => 'Caption', 'handle' => 'caption']);
    ensureField(PlainText::class, ['name' => 'Role', 'handle' => 'jobTitle']);
    ensureField(PlainText::class, ['name' => 'Button label', 'handle' => 'buttonLabel']);
    ensureField(Markdown::class, ['name' => 'Body', 'handle' => 'body']);
    ensureField(EntriesField::class, ['name' => 'Written by', 'handle' => 'writtenBy', 'maxRelations' => 2]);
    ensureField(EntriesField::class, ['name' => 'Related articles', 'handle' => 'relatedArticles', 'maxRelations' => 3]);
    ensureField(EntriesField::class, ['name' => 'Articles', 'handle' => 'articles', 'maxRelations' => 3]);
    ensureField(Link::class, ['name' => 'Link', 'handle' => 'buttonLink', 'types' => ['entry', 'asset', 'url']]);
    ensureField(AssetsField::class, ['name' => 'Gallery', 'handle' => 'gallery', 'sources' => ["volume:$images->uid"], 'viewMode' => 'large']);
    ensureField(EntriesField::class, ['name' => 'Destinations', 'handle' => 'destinations', 'maxRelations' => 3]);
    ensureField(PlainText::class, ['name' => 'Place', 'handle' => 'placeName']);
    ensureField(PlainText::class, ['name' => 'Best time to visit', 'handle' => 'bestTime']);
    ensureField(PlainText::class, ['name' => 'Travel time', 'handle' => 'travelTime']);
    ensureField(Dropdown::class, ['name' => 'Rating', 'handle' => 'rating', 'options' => array_map(
        fn(int $n) => ['label' => str_repeat('★', $n) . str_repeat('☆', 5 - $n), 'value' => (string)$n, 'default' => $n === 3],
        [5, 4, 3, 2, 1],
    )]);

    // content blocks for the homepage and pages
    $blocks = [
        ensureEntryType('hero', ['name' => 'Hero', 'icon' => 'image', 'color' => Color::Emerald, 'hasTitleField' => false], ['Content' => ['headline', ['heroImage', ['thumb' => true]], 'buttonLabel', 'buttonLink']]),
        ensureEntryType('textBlock', ['name' => 'Text', 'icon' => 'paragraph', 'color' => Color::Gray, 'hasTitleField' => false], ['Content' => ['body']]),
        ensureEntryType('imageBlock', ['name' => 'Image', 'icon' => 'photo-film', 'color' => Color::Sky, 'hasTitleField' => false], ['Content' => [['image', ['thumb' => true]], 'caption']]),
        ensureEntryType('featuredArticles', ['name' => 'Featured articles', 'icon' => 'newspaper', 'color' => Color::Amber, 'hasTitleField' => false], ['Content' => ['headline', 'articles']]),
        ensureEntryType('callToAction', ['name' => 'Call to action', 'icon' => 'bullhorn', 'color' => Color::Rose, 'hasTitleField' => false], ['Content' => ['headline', 'buttonLabel', 'buttonLink']]),
    ];
    ensureField(Matrix::class, ['name' => 'Content blocks', 'handle' => 'contentBlocks', 'entryTypes' => $blocks, 'viewMode' => Matrix::VIEW_MODE_BLOCKS]);
    $stop = ensureEntryType('stop', ['name' => 'Stop', 'icon' => 'location-dot', 'color' => Color::Orange], ['Content' => [new EntryTitleField(['required' => true]), 'destinations', 'travelTime', ['image', ['thumb' => true]]]]);
    ensureField(Matrix::class, ['name' => 'Stops', 'handle' => 'stops', 'entryTypes' => [$stop], 'viewMode' => Matrix::VIEW_MODE_BLOCKS]);

    $title = fn() => new EntryTitleField(['required' => true]);
    $article = ensureEntryType('article', ['name' => 'Article', 'icon' => 'newspaper', 'color' => Color::Blue], [
        'Content' => [$title(), ['heroImage', ['thumb' => true]], 'summary', 'body', 'destinations', 'writtenBy', 'relatedArticles'],
        'Used in' => ['usedIn'],
    ]);
    $photoEssay = ensureEntryType('photoEssay', ['name' => 'Photo essay', 'icon' => 'camera', 'color' => Color::Fuchsia], [
        'Content' => [$title(), ['heroImage', ['thumb' => true]], 'summary', 'gallery', 'destinations', 'writtenBy'],
        'Used in' => ['usedIn'],
    ]);
    $route = ensureEntryType('route', ['name' => 'Route', 'icon' => 'route', 'color' => Color::Orange], [
        'Content' => [$title(), ['heroImage', ['thumb' => true]], 'summary', 'stops', 'writtenBy'],
        'Used in' => ['usedIn'],
    ]);
    $review = ensureEntryType('review', ['name' => 'Review', 'icon' => 'star', 'color' => Color::Yellow], [
        'Content' => [$title(), ['heroImage', ['thumb' => true]], 'placeName', 'rating', 'body', 'destinations', 'writtenBy'],
        'Used in' => ['usedIn'],
    ]);
    $destination = ensureEntryType('destination', ['name' => 'Destination', 'icon' => 'earth-asia', 'color' => Color::Cyan], [
        'Content' => [$title(), ['heroImage', ['thumb' => true]], 'summary', 'bestTime'],
        'Used in' => ['usedIn'],
    ]);
    $person = ensureEntryType('person', ['name' => 'Person', 'icon' => 'user', 'color' => Color::Violet], [
        'Content' => [$title(), ['portrait', ['thumb' => true]], 'jobTitle', 'summary'],
        'Used in' => ['usedIn'],
    ]);
    $page = ensureEntryType('contentPage', ['name' => 'Content page', 'icon' => 'file-lines', 'color' => Color::Teal], [
        'Content' => [$title(), 'contentBlocks'],
        'Used in' => ['usedIn'],
    ]);
    $home = ensureEntryType('homepage', ['name' => 'Homepage', 'icon' => 'house', 'color' => Color::Green], [
        'Content' => [$title(), 'contentBlocks'],
        'Used in' => ['usedIn'],
    ]);
    ensureSection('homepage', 'Homepage', SectionType::Single, [$home], '__home__');
    $journal = ensureSection('journal', 'Journal', SectionType::Channel, [$article, $photoEssay, $route, $review], 'journal/{slug}');
    $destinations = ensureSection('destinations', 'Destinations', SectionType::Structure, [$destination], 'destinations/{slug}');
    $team = ensureSection('team', 'Team', SectionType::Channel, [$person], 'team/{slug}');
    ensureSection('company', 'Company', SectionType::Structure, [$page], 'company/{slug}');

    // entries fields point at their sections (they exist only now)
    foreach (['writtenBy' => $team, 'relatedArticles' => $journal, 'articles' => $journal, 'destinations' => $destinations] as $handle => $section) {
        $f = field($handle);
        if ($f->sources !== ["section:$section->uid"]) {
            $f->sources = ["section:$section->uid"];
            Fields::saveField($f) or fail($f);
        }
    }

    // index columns: "Used in" next to the file info, entry type, authors and dates for the journal (merged into
    // whatever source settings exist, Craft adds the sources not listed)
    $usedInUid = field('usedIn')->uid;
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
        $configs = ProjectConfig::get($path) ?? [];
        foreach ($columns as $key => $attributes) {
            $index = array_search($key, array_column($configs, 'key'), true);
            $config = ['type' => 'native', 'key' => $key, 'tableAttributes' => $attributes];
            $index === false ? $configs[] = $config : $configs[$index] = $config + $configs[$index];
        }
        ProjectConfig::set($path, array_values($configs));
    }

    // Craft Pro's custom control panel icon: the Transit mark (general config `cpIconUrl`, from the .env)
    if (!is_file(public_path('transit-icon.svg'))) {
        file_put_contents(public_path('transit-icon.svg'), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120">' . markSvg('icon') . '</svg>');
    }
    $env = base_path('.env');
    if (!str_contains((string)file_get_contents($env), 'CRAFT_CP_ICON_URL')) {
        file_put_contents($env, "\nCRAFT_CP_ICON_URL=/transit-icon.svg\n", FILE_APPEND);
    }

    // an editors group
    if (!UserGroups::getGroupByHandle('editors')) {
        $editors = new UserGroup(['name' => 'Editors', 'handle' => 'editors']);
        UserGroups::saveGroup($editors) or fail($editors);
    }
    ProjectConfig::saveModifiedConfigData();
    echo "Demo schema ready.\n";
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------
// Content
// ---------------------------------------------------------------------------------------------------------------
Plugins::switchEdition('element-relations', 'pro');
$deSite = Sites::getSiteByHandle('de') ?? throw new RuntimeException('de');

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

// users (authors) with photos
$users = [];
$editors = UserGroups::getGroupByHandle('editors') ?? throw new RuntimeException('editors');
// what editors may: the control panel, every site, the demo's sections and volumes, replace and roll back; not
// "Delete or disable elements that are in use"
$editorPermissions = ['accessCp', 'element-relations-replace', 'element-relations-rollback'];
foreach (Sites::getAllSites() as $site) {
    $editorPermissions[] = "editSite:$site->uid";
}
foreach (['journal', 'destinations', 'team', 'company', 'homepage'] as $handle) {
    $uid = Sections::getSectionByHandle($handle)?->uid;
    foreach (['viewEntries', 'createEntries', 'saveEntries', 'deleteEntries', 'viewPeerEntries', 'savePeerEntries', 'deletePeerEntries'] as $permission) {
        $editorPermissions[] = "$permission:$uid";
    }
}
foreach (['images', 'documents'] as $handle) {
    $uid = Volumes::getVolumeByHandle($handle)?->uid;
    foreach (['viewAssets', 'saveAssets', 'deleteAssets', 'replaceFiles', 'editImages', 'viewPeerAssets', 'savePeerAssets', 'deletePeerAssets', 'replacePeerFiles', 'editPeerImages'] as $permission) {
        $editorPermissions[] = "$permission:$uid";
    }
}
UserPermissions::saveGroupPermissions((int)$editors->id, $editorPermissions);
foreach (['mara' => ['Mara', 'Lindqvist'], 'jonas' => ['Jonas', 'Berger'], 'amelie' => ['Amelie', 'Novak']] as $key => [$first, $last]) {
    $user = User::find()->username($key)->status(null)->one();
    if (!$user) {
        $user = new User(['username' => $key, 'email' => "$key@example.com", 'firstName' => $first, 'lastName' => $last]);
        Elements::saveElement($user) or fail($user);
        Users::assignUserToGroups((int)$user->id, [(int)$editors->id]);
        Users::activateUser($user);
    }
    if ($key === 'mara' && !$user->admin) {
        // the editor the screenshots log in as (dev/screenshots.mts), with the dev project's password
        $user->admin = true;
        $user->newPassword = 'password';
        Elements::saveElement($user) or fail($user);
    }
    if ($key === 'jonas' && $user->password === null) {
        // a non-admin editor (Editors group) for the screenshots of what editors may not do, dev password too
        $user->newPassword = 'password';
        Elements::saveElement($user) or fail($user);
    }
    if ($user->photoId !== $portraits[$key]->id) {
        // straight to the column: saving a user with a photo copies it into the user photo volume, which is the test
        // fixtures' volume here (dev/seed/user-photo.php); the relations cache is rebuilt after the seed anyway
        DB::table('users')->where('id', $user->id)->update(['photoId' => $portraits[$key]->id]);
    }
    $users[$key] = $user;
}
$admin = User::find()->username('admin')->one() ?? throw new RuntimeException('admin'); // stays "admin": the GUI test looks for that label

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

// journal
$a = [];
$a['rails'] = ensureEntry('journal', 'article', 'Twelve hours on the rails', [
    'heroImage' => [$skyline->id],
    'summary' => 'From one skyline to the next without leaving the train: what a long day of rail travel teaches you.',
    'body' => "We boarded before sunrise and stepped off after dark, with four changes, one missed connection and more noodles than planned.\n\nThe trick is not speed. It is a window seat, a full water bottle and a timetable printed on paper, just in case.\n",
    'destinations' => [$d['beijing']->id, $d['hongkong']->id],
    'writtenBy' => [$people['mara']->id],
], ['postDate' => $daysAgo(64), 'authorIds' => [$users['mara']->id]], 'Zwölf Stunden auf Schienen');

$a['station'] = ensureEntry('journal', 'article', 'How to read a station', [
    'heroImage' => [$clock->id],
    'summary' => 'Clocks, boards, platform letters: a short guide to finding your train in a hurry.',
    'body' => sprintf(
        "Every station tells you where to go, if you know where to look. On [our twelve-hour day](%s) we changed trains four times.\n\n![Train passing the city towers](%s)\n\nLook for the clock first: the departure boards are always nearby.\n",
        ref($a['rails']), ref($skyline),
    ),
    'writtenBy' => [$people['jonas']->id],
    'relatedArticles' => [$a['rails']->id],
], ['postDate' => $daysAgo(41), 'authorIds' => [$users['jonas']->id]], 'Wie man einen Bahnhof liest');

$a['stadium'] = ensureEntry('journal', 'article', 'Steel, glass and a bird\'s nest', [
    'heroImage' => [$stadium->id],
    'summary' => 'A walk through the architecture you see from the train, and the one stadium worth getting off for.',
    'body' => sprintf(
        "Some buildings are best seen from a moving window. Others deserve a stop. Our [Rail Pass Guide 2026](%s) lists the stations closest to each.\n",
        ref($guide),
    ),
    'destinations' => [$d['beijing']->id],
    'writtenBy' => [$people['amelie']->id],
], ['postDate' => $daysAgo(30), 'authorIds' => [$users['amelie']->id]], 'Stahl, Glas und ein Vogelnest');

$a['packing'] = ensureEntry('journal', 'article', 'Packing light for a week of trains', [
    'heroImage' => [$window->id],
    'summary' => 'One bag that fits under the seat, and the three things we never travel without.',
    'body' => sprintf("Earplugs, a scarf, a power bank. The rest of the planning is in [how to read a station](%s).\n", ref($a['station'])),
    'writtenBy' => [$people['mara']->id, $people['jonas']->id],
    'relatedArticles' => [$a['rails']->id, $a['station']->id],
], ['postDate' => $daysAgo(18), 'authorIds' => [$users['mara']->id]], 'Leicht packen für eine Woche Zug');

$a['temple'] = ensureEntry('journal', 'article', 'Incense and quiet in Hong Kong', [
    'heroImage' => [$temple->id],
    'summary' => 'Ten minutes from the busiest station in town, a temple where nobody is in a hurry. A photo essay.',
    'destinations' => [$d['hongkong']->id],
    'writtenBy' => [$people['theo']->id],
    'relatedArticles' => [$a['rails']->id],
], ['postDate' => $daysAgo(11), 'authorIds' => [$users['amelie']->id]], 'Räucherstäbchen und Stille in Hongkong');

$a['costumes'] = ensureEntry('journal', 'article', 'Costume day on the subway', [
    'heroImage' => [$costumes->id],
    'summary' => 'Once a year the morning commute turns into a parade. We rode along.',
    'writtenBy' => [$people['amelie']->id, $people['theo']->id],
], ['postDate' => $daysAgo(5), 'authorIds' => [$users['amelie']->id]], 'Kostümtag in der U-Bahn');

$a['why'] = ensureEntry('journal', 'article', 'Why we take the train', [
    'heroImage' => [$platform->id],
    'summary' => 'Slower, cheaper than you think, and the only way to arrive in the middle of town.',
    'body' => "A train arrives where the city is. That alone is worth the extra hour.\n",
    'writtenBy' => [$people['jonas']->id],
], ['postDate' => $daysAgo(3), 'authorIds' => [$users['jonas']->id]], 'Warum wir Zug fahren');

$a['night'] = ensureEntry('journal', 'article', 'Night trains are back', [
    'heroImage' => [$tunnel->id],
    'summary' => 'Coming soon: the sleeper routes worth booking this winter.',
    'writtenBy' => [$people['jonas']->id],
], ['postDate' => (new DateTime())->modify('+12 days')->setTime(9, 0), 'authorIds' => [$users['jonas']->id]], 'Die Nachtzüge sind zurück'); // pending

ensureEntry('journal', 'article', 'Holiday timetable 2025', [
    'heroImage' => [$glassRoof->id],
    'summary' => 'Last year\'s holiday timetable (archived).',
], ['postDate' => $daysAgo(400), 'expiryDate' => $daysAgo(200), 'authorIds' => [$users['mara']->id]], 'Feiertagsfahrplan 2025'); // expired

// the other journal entry types: photo essay, route, reviews
ensureEntry('journal', 'photoEssay', 'Platforms', [
    'heroImage' => [$platform->id],
    'summary' => 'Five stations, five minutes before departure.',
    'gallery' => [$platform->id, $clock->id, $glassRoof->id, $tunnel->id, $crowd->id],
    'writtenBy' => [$people['theo']->id],
], ['postDate' => $daysAgo(24), 'authorIds' => [$users['amelie']->id]], 'Bahnsteige');
ensureEntry('journal', 'route', 'Beijing to Hong Kong in one day', [
    'heroImage' => [$window->id],
    'summary' => 'Two thousand kilometres, one seat, and a lot of tea.',
    'stops' => [
        'new1' => ['type' => 'stop', 'enabled' => true, 'title' => 'Beijing West', 'fields' => ['destinations' => [$d['beijing']->id], 'travelTime' => 'Departure 08:00', 'image' => [$stadium->id]]],
        'new2' => ['type' => 'stop', 'enabled' => true, 'title' => 'Hong Kong West Kowloon', 'fields' => ['destinations' => [$d['hongkong']->id], 'travelTime' => '8 h 56 min', 'image' => [$temple->id]]],
    ],
    'writtenBy' => [$people['jonas']->id],
], ['postDate' => $daysAgo(14), 'authorIds' => [$users['jonas']->id]], 'In einem Tag von Peking nach Hongkong');
ensureEntry('journal', 'review', 'The noodle shop on platform 3', [
    'heroImage' => [$street->id],
    'placeName' => 'Platform 3, Taipei Main Station',
    'rating' => '5',
    'body' => "Six seats, one dish, no menu. Order before the train is announced.\n",
    'destinations' => [$d['taipei']->id],
    'writtenBy' => [$people['mara']->id],
], ['postDate' => $daysAgo(9), 'authorIds' => [$users['mara']->id]], 'Der Nudelladen an Gleis 3');
ensureEntry('journal', 'review', 'A night in the sleeper cabin', [
    'heroImage' => [$tunnel->id],
    'placeName' => 'Overnight sleeper, berth 23',
    'rating' => '4',
    'body' => "Clean sheets, a reading lamp, and a conductor who knocks at six with tea. One star off for the snoring above.\n",
    'writtenBy' => [$people['jonas']->id],
], ['postDate' => $daysAgo(2), 'authorIds' => [$users['jonas']->id]], 'Eine Nacht im Schlafwagen');

// a draft that swaps the hero image
if (!Entry::find()->draftOf($a['why']->id)->status(null)->exists()) {
    $draft = Drafts::createDraft($a['why'], (int)$users['jonas']->id, 'New hero image', 'Try the tunnel photo');
    $draft->setFieldValue('heroImage', [$tunnel->id]);
    Elements::saveElement($draft) or fail($draft);
}

// an article nobody has published yet: only a draft (the list's "Drafts")
$journal = Sections::getSectionByHandle('journal') ?? throw new RuntimeException('journal');
if (!Entry::find()->sectionId($journal->id)->title('A ferry to Busan')->drafts(true)->status(null)->exists()) {
    $draft = new Entry([
        'sectionId' => $journal->id,
        'typeId' => EntryTypes::getEntryTypeByHandle('article')?->id,
        'title' => 'A ferry to Busan',
        'authorIds' => [$users['mara']->id],
    ]);
    $draft->setFieldValue('heroImage', [$river->id]);
    Drafts::saveElementAsDraft($draft, (int)$users['mara']->id, null, null, false) or fail($draft);
}

// in the trash: an article (its usage is listed nowhere until it is restored) and a document
if (!Entry::find()->sectionId($journal->id)->title('Station guide 2019')->trashed(true)->status(null)->exists()) {
    $outdated = ensureEntry('journal', 'article', 'Station guide 2019', [
        'heroImage' => [$glassRoof->id],
        'body' => "Replaced by the Rail Pass Guide 2026.\n",
    ], ['postDate' => $daysAgo(2400), 'authorIds' => [$users['jonas']->id]]);
    // its relations now: the queued refresh would only run once it is in the trash
    foreach (Entry::find()->id($outdated->id)->site('*')->status(null)->all() as $localized) {
        ExtractorService::refreshRelationsForElement($localized);
    }
    Elements::deleteElement($outdated) or fail($outdated);
}
if (!Asset::find()->filename('timetable-2019.pdf')->trashed(true)->exists()) {
    $timetable = ensureAsset('documents', 'timetable-2019.pdf', 'Timetable 2019', fn($p) => pdf($p, 'Timetable 2019', ['Superseded by the 2026 guide.']));
    Elements::deleteElement($timetable) or fail($timetable);
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
            'body' => "Note $i from $station: where the good bench is, which exit has the lift, when the last train really leaves.\n",
        ], ['postDate' => $daysAgo(100 + ++$n), 'authorIds' => [$writers[$n % count($writers)]->id]]);
    }
}
foreach (Entry::find()->sectionId($journal->id)->title('Station notes: *')->status(null)->all() as $stale) { // earlier seeds
    if (!in_array($stale->title, $noteTitles, true)) {
        Elements::deleteElement($stale, true);
    }
}

// pages
ensureEntry('company', 'contentPage', 'About us', ['contentBlocks' => [
    'new1' => ['type' => 'hero', 'enabled' => true, 'fields' => ['headline' => 'A small team that would rather be on a train', 'heroImage' => [$street->id]]],
    'new2' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['body' => "Transit started as a newsletter written on night trains in 2016. Today we are six writers and one photographer.\n"]],
    'new3' => ['type' => 'imageBlock', 'enabled' => true, 'fields' => ['image' => [$flags->id], 'caption' => 'Our first story: a station with a view.']],
]], ['authorIds' => [$admin->id]], 'Über uns');
ensureEntry('company', 'contentPage', 'Travel slowly', ['contentBlocks' => [
    'new1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['body' => sprintf("Fewer flights, more windows. Read [why we take the train](%s).\n", ref($a['why']))]],
    'new2' => ['type' => 'imageBlock', 'enabled' => true, 'fields' => ['image' => [$river->id], 'caption' => 'Ten minutes from the station.']],
]], ['authorIds' => [$admin->id]], 'Langsam reisen');

// homepage (a single)
$homepage = Entry::find()->section('homepage')->one() ?? throw new RuntimeException('homepage');
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
    Elements::saveElement($homepage) or fail($homepage);
}
translate($homepage, 'Startseite');

// German texts link to the German versions: Craft copies a new entry's English body (reference tags with `@<en>`) to
// its German site, which would make every German text a cross-site link to the English elements
$primarySiteId = Sites::getPrimarySite()->id;
foreach (Entry::find()->siteId($deSite->id)->status(null)->all() as $localized) {
    if (!$localized->getFieldLayout()?->getFieldByHandle('body')) {
        continue;
    }
    $body = (string)$localized->getFieldValue('body');
    $german = (string)preg_replace('/(\{(?:entry|asset):\d+)@' . $primarySiteId . ':/', '$1@' . $deSite->id . ':', $body);
    if ($german !== $body) {
        $localized->setFieldValue('body', $german);
        Elements::saveElement($localized) or fail($localized);
    }
}

ProjectConfig::saveModifiedConfigData();
echo "Demo content ready.\n";

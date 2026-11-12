<?php

namespace App\Console\Commands;

use App\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sets brand logos from a local folder: brand.js lists brand name → file, the files live in img/.
 * Default folder .claude/brand (git-ignored, so on the server copy the folder there or use --path).
 */
class ImportBrandLogos extends Command
{
    protected $signature = 'brands:import-logos {--path= : Folder with brand.js and img/ (default .claude/brand)} {--dry-run : Only check, change nothing}';

    protected $description = 'Set brand logos from brand.js and the img/ folder';

    /** Same types the admin upload accepts (Laravel "image" rule, without SVG). */
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function handle(): int
    {
        $dir = rtrim($this->option('path') ?: base_path('.claude/brand'), '/\\');
        $list = $this->readList($dir.DIRECTORY_SEPARATOR.'brand.js');
        if ($list === null) {
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $brands = Brand::all();
        $done = $skipped = $failed = 0;

        foreach ($list as $i => $item) {
            if (! is_string($item['name'] ?? '') || ! is_string($item['logo'] ?? '')) {
                $this->warn("#{$i}: \"name\" and \"logo\" must be text in double quotes.");
                $failed++;

                continue;
            }
            $name = trim($item['name'] ?? '');
            $file = trim($item['logo'] ?? '');
            if ($name === '' || $file === '') {
                $skipped++;

                continue;
            }

            $brand = $brands->first(fn (Brand $b) => collect($b->getTranslations('name'))
                ->contains(fn ($n) => mb_strtolower(trim((string) $n)) === mb_strtolower($name)));
            if (! $brand) {
                $this->warn("#{$i} \"{$name}\": no brand with this name in Admin → Brands.");
                $failed++;

                continue;
            }

            // Only a plain file name inside img/ (no ../ or sub-paths).
            $path = $dir.DIRECTORY_SEPARATOR.'img'.DIRECTORY_SEPARATOR.basename($file);
            if (basename($file) !== $file || ! is_file($path)) {
                $this->warn("\"{$name}\": file img/{$file} not found.");
                $failed++;

                continue;
            }
            if (filesize($path) > self::MAX_BYTES) {
                $this->warn("\"{$name}\": {$file} is larger than 2 MB.");
                $failed++;

                continue;
            }
            $ext = self::TYPES[(string) (new \finfo(FILEINFO_MIME_TYPE))->file($path)] ?? null;
            if (! $ext || @getimagesize($path) === false) {
                $this->warn("\"{$name}\": {$file} is not a PNG, JPG, WebP or GIF image.");
                $failed++;

                continue;
            }

            $contents = file_get_contents($path);
            $disk = Storage::disk('public');
            if ($brand->logo && $disk->exists($brand->logo) && hash_equals(sha1($disk->get($brand->logo)), sha1($contents))) {
                $this->line("\"{$name}\": already up to date.");
                $skipped++;

                continue;
            }

            if ($dry) {
                $this->info("\"{$name}\": would set {$file}.");
                $done++;

                continue;
            }

            $new = 'brands/'.Str::random(40).'.'.$ext;
            if (! $disk->put($new, $contents)) {
                $this->warn("\"{$name}\": could not save {$file} to storage.");
                $failed++;

                continue;
            }
            $old = $brand->logo;
            $brand->update(['logo' => $new]);
            if ($old && str_starts_with($old, 'brands/') && $old !== $new) {
                $disk->delete($old);
            }
            $this->info("\"{$name}\": logo set from {$file}.");
            $done++;
        }

        $this->newLine();
        $this->line(($dry ? 'Would set' : 'Set')." {$done}, skipped {$skipped}, problems {$failed}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, array{name?: string, logo?: string}>|null */
    private function readList(string $file): ?array
    {
        if (! is_file($file)) {
            $this->error("Not found: {$file}");

            return null;
        }

        $js = (string) file_get_contents($file);
        $js = preg_replace('#/\*.*?\*/#s', '', $js);          // block comments
        $js = preg_replace('#^\s*//.*$#m', '', $js);           // whole-line comments (keeps "https://" in values)
        $start = strpos($js, '[');
        $end = strrpos($js, ']');
        $json = $start === false || $end === false ? '' : substr($js, $start, $end - $start + 1);
        $json = preg_replace('/([{,]\s*)(name|logo)\s*:/', '$1"$2":', $json); // unquoted keys
        $json = preg_replace('/,\s*([\]}])/', '$1', $json);                    // trailing commas
        $list = json_decode($json, true);

        if (! is_array($list) || ! array_is_list($list)) {
            $this->error('brand.js could not be read: keep one list like [ { "name": "Zebra", "logo": "zebra.png" } ].');

            return null;
        }

        return array_values(array_filter($list, 'is_array'));
    }
}

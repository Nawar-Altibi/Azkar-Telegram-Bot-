<?php

namespace App\Services;

use App\Models\AdhkarItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Throwable;

class AdhkarPdfService
{
    // A5 height minus the top and bottom margins below.
    private const PAGE_HEIGHT_MM = 170.0;

    // .entry margin-bottom in the card view; the last card on a page does not need it.
    private const CARD_GAP_MM = 4.0;

    private const MAX_EXTRA_GAP_MM = 6.0;

    // Steps of smaller card text defined in the styles view (.compact-1, .compact-2).
    private const MAX_COMPACT = 2;

    public function __construct(private readonly AdhkarContentService $content) {}

    /**
     * Changes whenever the wird or its rendering changes, so cached files and Telegram file_ids stay valid.
     *
     * @param  Collection<int, AdhkarItem>  $items
     */
    public function contentHash(string $collectionKey, Collection $items, bool $fullOnly = false): string
    {
        $parts = [$collectionKey, $fullOnly ? 'f' : '', $this->viewsHash(), md5_file(__FILE__)];
        foreach ($items as $item) {
            $parts[] = $item->key.'@'.$item->content_version;
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /**
     * @param  Collection<int, AdhkarItem>  $items
     * @return array{path:string,filename:string,title:string,hash:string}
     */
    public function build(string $collectionKey, Collection $items, bool $fullOnly = false): array
    {
        $file = $this->describe($collectionKey, $items, $fullOnly);

        if (! is_file($file['path'])) {
            File::ensureDirectoryExists(dirname($file['path']));
            $this->render($collectionKey, $items, $fullOnly, $file['title'], $file['path']);
        }

        return $file;
    }

    /**
     * Same result as build() without rendering, so a file Telegram already holds is never re-made.
     *
     * @param  Collection<int, AdhkarItem>  $items
     * @return array{path:string,filename:string,title:string,hash:string}
     */
    public function describe(string $collectionKey, Collection $items, bool $fullOnly = false): array
    {
        $hash = $this->contentHash($collectionKey, $items, $fullOnly);
        $title = $this->content->wirdTitle($collectionKey, $fullOnly);

        return [
            'path' => storage_path('app/pdf').'/'.$collectionKey.($fullOnly ? '-extra' : '').'-'.$hash.'.pdf',
            'filename' => str_replace([' — ', ' '], '-', $title).'.pdf',
            'title' => $title,
            'hash' => $hash,
        ];
    }

    /**
     * PNG pages of a built wird file, rendered once by pdftoppm and cached next to it.
     *
     * @return list<string>|null null when pdftoppm is not installed or fails, so the caller sends the PDF instead
     */
    public function pageImages(string $pdfPath): ?array
    {
        $directory = substr($pdfPath, 0, -4).'-pages';
        $pages = $this->existingPages($directory);
        if ($pages !== []) {
            return $pages;
        }

        $partial = $directory.'.'.getmypid().'.tmp';
        File::deleteDirectory($partial);
        File::ensureDirectoryExists($partial);

        try {
            $result = Process::timeout(60)->run([
                (string) config('adhkar.pdftoppm_binary', 'pdftoppm'),
                '-png',
                '-r', (string) config('adhkar.page_image_dpi', 150),
                $pdfPath,
                $partial.'/page',
            ]);
            $pages = $result->successful() ? $this->existingPages($partial) : [];
        } catch (Throwable) {
            $pages = [];
        }

        if ($pages === []) {
            File::deleteDirectory($partial);

            return null;
        }

        if (! is_dir($directory) && @rename($partial, $directory)) {
            return $this->existingPages($directory);
        }
        File::deleteDirectory($partial);
        $pages = $this->existingPages($directory);

        return $pages === [] ? null : $pages;
    }

    /**
     * @return list<string>
     */
    private function existingPages(string $directory): array
    {
        $pages = glob($directory.'/page*.png') ?: [];
        natsort($pages);

        return array_values($pages);
    }

    /**
     * @param  Collection<int, AdhkarItem>  $items
     */
    private function render(string $collectionKey, Collection $items, bool $fullOnly, string $title, string $path): void
    {
        $heading = $this->content->collectionHeading($collectionKey);
        $subtitle = $this->subtitle($collectionKey, $fullOnly, $items->count());
        $t = fn (?string $text): string => $this->typeset($text);

        $css = view('pdf.wird.styles')->render();
        $cover = view('pdf.wird.cover', ['heading' => $heading, 'subtitle' => $subtitle])->render();
        $items = $items->values();
        $card = fn (int $index, int $compact = 0): string => view('pdf.wird.card', [
            'item' => $items[$index], 'number' => $index + 1, 'compact' => $compact, 't' => $t,
        ])->render();
        $cards = $items->keys()->map(fn (int $index): string => $card($index))->all();
        $closing = view('pdf.wird.closing', ['footer' => $t($this->content->footerText($collectionKey))])->render();

        $heights = $this->measure($css, [$cover, ...$cards, $closing]);
        $closingHeight = array_pop($heights);
        $coverHeight = array_shift($heights);

        // A card taller than its page (under the cover, for the first) gets slightly smaller text until it fits whole.
        foreach ($heights as $index => $height) {
            $room = self::PAGE_HEIGHT_MM + self::CARD_GAP_MM - ($index === 0 ? $coverHeight : 0.0);
            for ($compact = 1; $height > $room && $compact <= self::MAX_COMPACT; $compact++) {
                $cards[$index] = $card($index, $compact);
                $height = $heights[$index] = $this->measure($css, [$cards[$index]])[0];
            }
        }

        $pages = $this->paginate($heights, $coverHeight, $closingHeight);

        $mpdf = $this->mpdf('A5');
        $mpdf->SetTitle($title);
        $mpdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($cover, HTMLParserMode::HTML_BODY);
        foreach ($pages as $number => $page) {
            if ($number > 0) {
                $mpdf->AddPage();
            }
            foreach ($page['cards'] as $position => $index) {
                if ($position > 0 && $page['gap'] > 0) {
                    $mpdf->Ln($page['gap']);
                }
                $mpdf->WriteHTML($cards[$index], HTMLParserMode::HTML_BODY);
            }
        }
        $mpdf->WriteHTML($closing, HTMLParserMode::HTML_BODY);

        // Written next to the final name, then renamed, so a concurrent reader never sees a half file.
        $partial = $path.'.'.getmypid().'.tmp';
        $mpdf->Output($partial, 'F');
        rename($partial, $path);
    }

    /**
     * Height in mm of each block, laid out at the real page width on one very tall page.
     *
     * @param  list<string>  $blocks
     * @return list<float>
     */
    private function measure(string $css, array $blocks): array
    {
        $mpdf = $this->mpdf([148, 5000]);
        $mpdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        // Opened up front so the first block is not charged the top margin.
        $mpdf->AddPage();

        $heights = [];
        foreach ($blocks as $html) {
            $top = $mpdf->y;
            $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);
            $heights[] = $mpdf->y - $top;
        }

        return $heights;
    }

    /**
     * Whole cards only: a card that does not fit starts the next page, and the closing always shares a page
     * with the last card. Leftover space on a full page is shared between its cards, up to a limit.
     *
     * @param  list<float>  $heights  card heights, each including its bottom gap
     * @return list<array{cards: list<int>, gap: float}>
     */
    private function paginate(array $heights, float $coverHeight, float $closingHeight): array
    {
        $pages = [['cards' => [], 'used' => $coverHeight]];
        foreach ($heights as $index => $height) {
            $current = count($pages) - 1;
            $fits = $pages[$current]['used'] + $height - self::CARD_GAP_MM <= self::PAGE_HEIGHT_MM;
            if (! $fits && $pages[$current]['cards'] !== []) {
                $pages[] = ['cards' => [], 'used' => 0.0];
                $current++;
            }
            $pages[$current]['cards'][] = $index;
            $pages[$current]['used'] += $height;
        }

        $last = count($pages) - 1;
        if ($pages[$last]['used'] + $closingHeight > self::PAGE_HEIGHT_MM && count($pages[$last]['cards']) > 1) {
            $moved = array_pop($pages[$last]['cards']);
            $pages[$last]['used'] -= $heights[$moved];
            $pages[] = ['cards' => [$moved], 'used' => $heights[$moved]];
            $last++;
        }

        return array_map(function (array $page, int $number) use ($last): array {
            $gaps = count($page['cards']) - 1;
            $spare = self::PAGE_HEIGHT_MM - ($page['used'] - self::CARD_GAP_MM);
            $gap = $number === $last || $gaps < 1 ? 0.0 : min(self::MAX_EXTRA_GAP_MM, max(0.0, $spare - 1) / $gaps);

            return ['cards' => $page['cards'], 'gap' => round($gap, 2)];
        }, $pages, array_keys($pages));
    }

    /**
     * @param  string|array{0: float|int, 1: float|int}  $format
     */
    private function mpdf(string|array $format): Mpdf
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'directionality' => 'rtl',
            'fontDir' => [...$fontDirs, resource_path('fonts/amiri'), resource_path('fonts/tajawal')],
            'fontdata' => $fontData + [
                'amiri' => [
                    'R' => 'Amiri-Regular.ttf',
                    'B' => 'Amiri-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
                // Titles, labels and page furniture; the adhkar themselves stay in Amiri.
                'tajawal' => [
                    'R' => 'Tajawal-Medium.ttf',
                    'B' => 'Tajawal-Bold.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'default_font' => 'amiri',
            // Automatic script detection would swap Arabic runs to mPDF's own Arabic font.
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'tempDir' => $tempDir,
            'margin_top' => 22,
            'margin_bottom' => 18,
            'margin_left' => 13,
            'margin_right' => 13,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);
    }

    /**
     * mPDF mirrors guillemets a second time in RTL runs, and Amiri has no emoji glyphs.
     */
    private function typeset(?string $text): string
    {
        $text = strtr((string) $text, ['«' => '»', '»' => '«']);
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', $text) ?? $text;

        $text = preg_replace(['/[ \t]{2,}/u', '/[ \t]*\n[ \t]*/u'], [' ', "\n"], $text) ?? $text;
        // Keeps a reference such as "[البقرة: 255]" on one line, so its number cannot wrap away from the name.
        $text = preg_replace('/:\s(\d)/u', ":\u{00A0}$1", $text) ?? $text;

        return trim($text);
    }

    private function subtitle(string $collectionKey, bool $fullOnly, int $count): string
    {
        $amount = match (true) {
            $count === 1 => 'ذكر واحد',
            $count === 2 => 'ذكران',
            $count <= 10 => $count.' أذكار',
            default => $count.' ذكرًا',
        };

        if ($this->content->isSingleVersion($collectionKey)) {
            return $amount;
        }

        $wird = match (true) {
            $fullOnly => 'تتمة الورد الكامل',
            str_contains($collectionKey, '_full') => 'الورد الكامل',
            default => 'الورد المختصر',
        };

        return $wird.' · '.$amount;
    }

    private function viewsHash(): string
    {
        $files = glob(resource_path('views/pdf/wird/*.blade.php')) ?: [];
        sort($files);

        return md5(implode('|', array_map('md5_file', $files)));
    }
}

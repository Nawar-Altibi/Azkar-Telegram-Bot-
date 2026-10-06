<?php

namespace App\Services;

use App\Models\AdhkarItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Throwable;

class AdhkarPdfService
{
    public function __construct(private readonly AdhkarContentService $content) {}

    /**
     * Changes whenever the wird or its rendering changes, so cached files and Telegram file_ids stay valid.
     *
     * @param  Collection<int, AdhkarItem>  $items
     */
    public function contentHash(string $collectionKey, Collection $items, bool $fullOnly = false): string
    {
        $parts = [$collectionKey, $fullOnly ? 'f' : '', md5_file($this->viewPath()), md5_file(__FILE__)];
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
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A5',
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
        $mpdf->SetTitle($title);

        $html = view('pdf.wird', [
            'title' => $title,
            'heading' => $this->content->collectionHeading($collectionKey),
            'subtitle' => $this->subtitle($collectionKey, $fullOnly, $items->count()),
            'items' => $items->values(),
            'footer' => $this->content->footerText($collectionKey),
            'splitAbove' => 200,
            't' => fn (?string $text): string => $this->typeset($text),
        ])->render();

        $mpdf->WriteHTML($html);

        // Written next to the final name, then renamed, so a concurrent reader never sees a half file.
        $partial = $path.'.'.getmypid().'.tmp';
        $mpdf->Output($partial, 'F');
        rename($partial, $path);
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

    private function viewPath(): string
    {
        return resource_path('views/pdf/wird.blade.php');
    }
}

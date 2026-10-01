<?php

namespace App\Services;

use App\Models\AdhkarItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

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
        $parts = [$collectionKey, $fullOnly ? 'f' : '', (string) filemtime($this->viewPath())];
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
        $hash = $this->contentHash($collectionKey, $items, $fullOnly);
        $title = $this->content->wirdTitle($collectionKey, $fullOnly);
        $filename = str_replace([' — ', ' '], '-', $title).'.pdf';
        $directory = storage_path('app/pdf');
        $path = $directory.'/'.$collectionKey.($fullOnly ? '-extra' : '').'-'.$hash.'.pdf';

        if (! is_file($path)) {
            File::ensureDirectoryExists($directory);
            $this->render($collectionKey, $items, $title, $path);
        }

        return ['path' => $path, 'filename' => $filename, 'title' => $title, 'hash' => $hash];
    }

    /**
     * @param  Collection<int, AdhkarItem>  $items
     */
    private function render(string $collectionKey, Collection $items, string $title, string $path): void
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A5',
            'directionality' => 'rtl',
            'default_font' => 'xbriyaz',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
            'margin_top' => 14,
            'margin_bottom' => 14,
            'margin_left' => 12,
            'margin_right' => 12,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetHTMLFooter('<div style="text-align:center;font-size:9pt;color:#829ab1;">صفحة {PAGENO} من {nbpg}</div>');

        $html = view('pdf.wird', [
            'title' => $title,
            'items' => $items->values(),
            'footer' => $this->content->footerText($collectionKey),
        ])->render();

        $mpdf->WriteHTML($html);

        // Written next to the final name, then renamed, so a concurrent reader never sees a half file.
        $partial = $path.'.'.getmypid().'.tmp';
        $mpdf->Output($partial, 'F');
        rename($partial, $path);
    }

    private function viewPath(): string
    {
        return resource_path('views/pdf/wird.blade.php');
    }
}

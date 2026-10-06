<?php

namespace App\Services\Telegram;

use App\Models\AdhkarItem;
use App\Services\AdhkarPdfService;
use App\Support\Telegram\KeyboardFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends a whole wird as page images or as a PDF to any chat, reusing Telegram file ids when it already has the file.
 */
class WirdSender
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly AdhkarPdfService $pdf,
        private readonly KeyboardFactory $keyboards,
    ) {}

    /**
     * Sends the wird pages as photos that open inside Telegram, optionally with a button for the printable PDF.
     *
     * @param  Collection<int, AdhkarItem>  $items
     * @return bool false when page images cannot be made here, so the caller sends the PDF
     */
    public function sendImages(int $chatId, string $collectionKey, Collection $items, bool $fullOnly, ?string $printCallback, ?string $caption = null): bool
    {
        // Container disks are wiped on restart while the file ids live in the shared cache,
        // so pages are rendered only when Telegram has no copy of this exact content.
        $file = $this->pdf->describe($collectionKey, $items, $fullOnly);
        $cacheKey = 'adhkar:page_file_ids:'.$file['hash'];
        $cached = Cache::get($cacheKey);
        $fileIds = is_array($cached) && $cached !== [] && ! in_array(null, $cached, true) ? array_values($cached) : null;
        $renderPages = fn () => $this->pdf->pageImages($this->pdf->build($collectionKey, $items, $fullOnly)['path']);

        $pages = null;
        if ($fileIds === null) {
            $pages = $renderPages();
            if ($pages === null) {
                return false;
            }
            $pages = array_values($pages);
        }
        $pageCount = count($fileIds ?? $pages);

        try {
            $this->telegram->sendChatAction($chatId, 'upload_photo');
        } catch (Throwable) {
            // Only a typing indicator.
        }

        $caption ??= __('bot.pdf_album_caption', ['title' => e($file['title'])]);

        $sent = [];
        foreach (array_chunk(range(0, $pageCount - 1), 10) as $chunk) {
            $photos = array_map(
                fn (int $i) => $fileIds !== null ? ['file_id' => $fileIds[$i]] : ['path' => $pages[$i]],
                $chunk,
            );
            $chunkCaption = $sent === [] ? $caption : '';

            try {
                $result = $this->sendPhotos($chatId, $photos, $chunkCaption);
            } catch (TelegramApiException $exception) {
                if ($fileIds === null) {
                    throw $exception;
                }
                Cache::forget($cacheKey);
                $fileIds = null;
                $pages = $renderPages();
                if ($pages === null || count($pages) !== $pageCount) {
                    throw $exception;
                }
                $pages = array_values($pages);
                $result = $this->sendPhotos($chatId, array_map(fn (int $i) => ['path' => $pages[$i]], $chunk), $chunkCaption);
            }

            $messages = isset($result['result']['message_id']) ? [$result['result']] : (array) ($result['result'] ?? []);
            foreach ($messages as $message) {
                $photo = collect($message['photo'] ?? [])->last();
                $sent[] = is_array($photo) ? ($photo['file_id'] ?? null) : null;
            }
        }

        if (count($sent) === $pageCount && ! in_array(null, $sent, true)) {
            Cache::forever($cacheKey, $sent);
        }

        if ($printCallback !== null) {
            $this->telegram->sendMessage($chatId, __('bot.pdf_print_hint'), $this->keyboards->printPdf($printCallback));
        }

        return true;
    }

    /**
     * @param  Collection<int, AdhkarItem>  $items
     */
    public function sendPdf(int $chatId, string $collectionKey, Collection $items, bool $fullOnly, ?string $caption = null): void
    {
        $file = $this->pdf->describe($collectionKey, $items, $fullOnly);
        $cacheKey = 'adhkar:pdf_file_id:'.$file['hash'];
        $caption ??= __('bot.pdf_caption', ['title' => e($file['title'])]);
        $fileId = Cache::get($cacheKey);
        if (! is_string($fileId) || $fileId === '') {
            $fileId = null;
            $file = $this->pdf->build($collectionKey, $items, $fullOnly);
        }

        try {
            $this->telegram->sendChatAction($chatId, 'upload_document');
        } catch (Throwable) {
            // Only a typing indicator.
        }

        try {
            $result = $this->telegram->sendDocument($chatId, $file['path'], $file['filename'], $caption, $fileId);
        } catch (TelegramApiException $exception) {
            if ($fileId === null) {
                throw $exception;
            }
            Cache::forget($cacheKey);
            $file = $this->pdf->build($collectionKey, $items, $fullOnly);
            $result = $this->telegram->sendDocument($chatId, $file['path'], $file['filename'], $caption);
        }

        $newFileId = data_get($result, 'result.document.file_id');
        if (is_string($newFileId) && $newFileId !== '') {
            Cache::forever($cacheKey, $newFileId);
        }
    }

    /**
     * @param  array<int, array{path?:string,file_id?:string}>  $photos
     * @return array<string, mixed>
     */
    private function sendPhotos(int $chatId, array $photos, string $caption): array
    {
        return count($photos) === 1
            ? $this->telegram->sendPhoto($chatId, $photos[0], $caption)
            : $this->telegram->sendMediaGroup($chatId, $photos, $caption);
    }
}

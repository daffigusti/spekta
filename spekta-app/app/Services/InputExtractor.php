<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/**
 * FR-01: ekstraksi teks dari file input (.txt, .md, .docx, .pdf).
 * ponytail: audio/video (Whisper) + Fireflies = akhir Fase 2, belum di sini.
 */
class InputExtractor
{
    public function extract(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        $text = match ($ext) {
            'txt', 'md' => (string) file_get_contents($file->getRealPath()),
            'docx' => $this->fromDocx($file->getRealPath()),
            'pdf' => $this->fromPdf($file->getRealPath()),
            default => throw new \InvalidArgumentException("Format .$ext tidak didukung"),
        };

        // Normalisasi whitespace, buang karakter kontrol
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function fromDocx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            throw new \InvalidArgumentException('File .docx tidak valid');
        }
        $zip->close();

        // </w:p> = akhir paragraf → newline; sisanya strip tag
        $xml = str_replace(['</w:p>', '<w:br/>', '<w:tab/>'], ["\n", "\n", ' '], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function fromPdf(string $path): string
    {
        try {
            return (new PdfParser)->parseFile($path)->getText();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('File .pdf tidak bisa dibaca: '.$e->getMessage());
        }
    }
}

<?php

namespace App\Services;

use App\Models\Document;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordParser;
use Illuminate\Http\UploadedFile;

class DocumentService
{
    public function process(UploadedFile $file, string $type): Document
    {
        // \Log::info("DocumentService: Bắt đầu xử lý file {$file->getClientOriginalName()} (loại: {$type})");
        $content = '';
        $extension = $file->getClientOriginalExtension();

        try {
            if ($extension === 'pdf') {
                // \Log::info("DocumentService: Đang parse PDF");
                $content = $this->parsePdf($file->getPathname());
            } elseif (in_array($extension, ['docx', 'doc'])) {
                // \Log::info("DocumentService: Đang parse Word");
                $content = $this->parseDocx($file->getPathname());
            } else {
                // \Log::info("DocumentService: Đang đọc file text");
                $content = file_get_contents($file->getPathname());
            }

            // \Log::info("DocumentService: Đã lấy được nội dung (độ dài: " . strlen($content) . ")");

            // Sanitize content to remove invalid UTF-8 characters
            $content = $this->sanitizeUtf8($content);

            return Document::create([
                'type' => $type,
                'name' => $file->getClientOriginalName(),
                'content' => $content,
            ]);
        } catch (\Exception $e) {
            \Log::error("DocumentService Error: " . $e->getMessage());
            throw $e;
        }
    }

    private function sanitizeUtf8(string $text): string
    {
        // Remove null bytes
        $text = str_replace("\0", "", $text);
        
        // Use mb_convert_encoding to fix invalid UTF-8 sequences
        // This is often more robust than iconv for this specific error
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Also remove invalid control characters but keep newlines and tabs
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        
        return $text;
    }

    private function parsePdf(string $path): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);
        return $pdf->getText();
    }

    private function parseDocx(string $path): string
    {
        $phpWord = WordParser::load($path);
        $content = '';
        foreach ($phpWord->getSections() as $section) {
            $content .= $this->extractTextFromElements($section->getElements());
        }
        return $content;
    }

    private function extractTextFromElements(array $elements): string
    {
        $text = '';
        foreach ($elements as $element) {
            if (method_exists($element, 'getText')) {
                $text .= $element->getText() . "\n";
            } elseif (method_exists($element, 'getElements')) {
                $text .= $this->extractTextFromElements($element->getElements());
            } elseif (get_class($element) === 'PhpOffice\PhpWord\Element\TextRun') {
                foreach ($element->getElements() as $textElement) {
                    if (method_exists($textElement, 'getText')) {
                        $text .= $textElement->getText();
                    }
                }
                $text .= "\n";
            }
        }
        return $text;
    }
}

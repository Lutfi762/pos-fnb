<?php

declare(strict_types=1);

namespace App\Support\Pdf;

/**
 * Makes PDFs when an engine is installed, and says so when it is not.
 *
 * Without one, callers fall back to the same document as a printable page:
 * every browser can save a page as PDF, so a manager is never left without
 * the report, only without the one-click download.
 */
class PdfRenderer
{
    public function __construct(private readonly ?PdfEngine $engine = null) {}

    public static function detect(): self
    {
        return new self(DompdfEngine::installed() ? new DompdfEngine : null);
    }

    public function available(): bool
    {
        return $this->engine !== null;
    }

    public function render(string $html, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        if ($this->engine === null) {
            throw new \RuntimeException('No PDF engine installed.');
        }

        return $this->engine->render($html, $paper, $orientation);
    }
}

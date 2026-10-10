<?php

declare(strict_types=1);

namespace App\Support\Pdf;

/**
 * Turns an HTML document into PDF bytes.
 */
interface PdfEngine
{
    public function render(string $html, string $paper, string $orientation): string;
}

<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF through dompdf, when it is installed (composer require dompdf/dompdf).
 *
 * Remote fetching is off: a report never needs anything from the network,
 * and a PDF engine that follows URLs in user text is a way in.
 */
final class DompdfEngine implements PdfEngine
{
    public static function installed(): bool
    {
        return class_exists(Dompdf::class);
    }

    public function render(string $html, string $paper, string $orientation): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([resource_path()]);
        $options->setTempDir(storage_path('framework/cache'));
        $options->setFontCache(storage_path('framework/cache'));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }
}

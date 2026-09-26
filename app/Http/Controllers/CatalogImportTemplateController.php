<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\ImportMenu;
use Symfony\Component\HttpFoundation\Response;

/**
 * The empty spreadsheet a manager fills in before importing a menu.
 */
class CatalogImportTemplateController
{
    public function __invoke(ImportMenu $import): Response
    {
        return response($import->template(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="template-menu-meja.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }
}

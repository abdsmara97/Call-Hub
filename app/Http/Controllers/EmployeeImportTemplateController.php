<?php

namespace App\Http\Controllers;

use App\Imports\EmployeeImport;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads the employee import template.
 *
 * The file is generated rather than shipped as a static asset so the company
 * and administration columns always carry names that exist right now — the
 * importer matches those by name, so a stale template is a file that fails on
 * every row.
 */
class EmployeeImportTemplateController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('import', User::class);

        $blank = $request->boolean('blank');

        $csv = EmployeeImport::sampleCsv(withExamples: ! $blank);

        $filename = $blank
            ? 'saai-employee-import-blank.csv'
            : 'saai-employee-import-template.csv';

        // A BOM, so Excel opens the file as UTF-8 instead of mangling any
        // non-ASCII name in the roster.
        $body = "\u{FEFF}".$csv;

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

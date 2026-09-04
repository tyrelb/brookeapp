<?php

namespace App\Http\Controllers;

use App\Support\UserGuide;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the pre-built user guide PDF (resources/docs/BrookeApp-User-Guide.pdf) to
 * logged-in trainers. Inline, so it opens in a tab they can print or save from.
 */
class DocsPdfController extends Controller
{
    public function __invoke(UserGuide $guide): BinaryFileResponse
    {
        $path = $guide->pdfPath();

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="BrookeApp-User-Guide.pdf"',
        ]);
    }
}

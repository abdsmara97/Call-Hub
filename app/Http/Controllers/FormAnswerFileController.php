<?php

namespace App\Http\Controllers;

use App\Models\FormAnswerFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way a picture answer leaves the private disk.
 *
 * Two gates, both required: a valid signature (route middleware) AND the right
 * to read that particular response. Note this is narrower than the attachment
 * equivalent — being in the room is not enough, because a form's answers are
 * visible only to the person who wrote them, the form's author, and admins.
 */
class FormAnswerFileController extends Controller
{
    public function __invoke(Request $request, FormAnswerFile $file): Response
    {
        $answer = $file->answer()->with('response.form')->firstOrFail();

        $this->authorize('view', $answer->response);

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        // Only ever inline what is safe to render in this origin. An SVG or an
        // HTML file uploaded as a "picture" would otherwise run here.
        $disposition = $this->isInlineSafe($file) ? 'inline' : 'attachment';

        return Storage::disk($file->disk)->download(
            $file->path,
            $file->original_name,
            [
                'Content-Type' => $file->mime_type,
                'Content-Disposition' => $disposition.'; filename="'.addslashes($file->original_name).'"',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }

    private function isInlineSafe(FormAnswerFile $file): bool
    {
        return in_array($file->mime_type, [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        ], true);
    }
}

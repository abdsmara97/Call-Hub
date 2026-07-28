<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way an attachment leaves the private disk.
 *
 * Two gates, both required: the URL must carry a valid signature (route
 * middleware) AND the requester must still be able to read the room the file
 * was posted in. A signature alone would keep working after someone leaves a
 * room, which is exactly the leak worth closing.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): Response
    {
        $message = $attachment->message()->withTrashed()->firstOrFail();

        $this->authorize('view', $message->room);

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        // Never inline arbitrary uploads: an inline SVG or HTML file would run
        // in the app's origin. Images are the only inline-safe exception.
        $disposition = $this->isInlineSafe($attachment) ? 'inline' : 'attachment';

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => $disposition.'; filename="'.addslashes($attachment->original_name).'"',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }

    private function isInlineSafe(Attachment $attachment): bool
    {
        return in_array($attachment->mime_type, [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        ], true);
    }
}

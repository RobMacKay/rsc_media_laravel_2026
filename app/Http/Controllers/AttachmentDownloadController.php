<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentDownloadController extends Controller
{
    /**
     * Hand back an attached file.
     *
     * Files live on the private disk, so this is the only way to get one, and
     * a client only ever gets their own business's shared files.
     */
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->isVisibleTo($request->user()), 404);

        abort_if($attachment->path === null || ! Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->name);
    }
}

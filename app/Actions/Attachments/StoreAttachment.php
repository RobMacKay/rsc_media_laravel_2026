<?php

namespace App\Actions\Attachments;

use App\Contracts\HasAttachments;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The single place an uploaded file is put on disk and recorded.
 *
 * Files live on the private disk and are only ever served back through
 * AttachmentDownloadController, so nothing is reachable by guessing a URL.
 */
class StoreAttachment
{
    /**
     * Store an uploaded file against a ticket or a project.
     */
    public function handle(
        Model&HasAttachments $attachable,
        UploadedFile $file,
        ?User $uploader = null,
        bool $sharedWithClient = true,
    ): Attachment {
        $name = $this->safeName($file->getClientOriginalName());

        // The stored name is a ULID, so two people uploading "screenshot.png"
        // do not collide and nothing user-supplied reaches the filesystem.
        $path = $file->storeAs(
            $this->directory($attachable),
            (string) Str::ulid().'.'.Str::lower($file->getClientOriginalExtension() ?: 'bin'),
            'local',
        );

        $attachment = new Attachment([
            'uploaded_by' => $uploader?->id,
            'name' => $name,
            'path' => $path,
            'kind' => Attachment::kindFor($name),
            'size' => $file->getSize(),
            'shared_with_client' => $sharedWithClient,
        ]);

        $attachment->attachable()->associate($attachable);
        $attachment->save();

        return $attachment;
    }

    /**
     * Get the directory a file for this record belongs in.
     */
    private function directory(Model&HasAttachments $attachable): string
    {
        return 'attachments/'.Str::of(class_basename($attachable))->kebab()->plural().'/'.$attachable->getKey();
    }

    /**
     * Get a display name that is safe to show and to send back as a filename.
     */
    private function safeName(string $original): string
    {
        $name = Str::of($original)->basename()->trim()->limit(120, '')->toString();

        return $name === '' ? 'file' : $name;
    }
}

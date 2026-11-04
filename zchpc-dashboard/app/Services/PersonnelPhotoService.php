<?php

namespace App\Services;

use App\Models\Personnel;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Personnel photos are optional and are served through an authenticated route
 * from a private disk, never from a public directory.
 */
class PersonnelPhotoService
{
    public function store(UploadedFile $file, Personnel $personnel): string
    {
        $this->delete($personnel);

        return $file->store('access-photos', (string) config('access.photos.disk', 'local'));
    }

    public function delete(Personnel $personnel): void
    {
        if (blank($personnel->photo_path)) {
            return;
        }

        Storage::disk($this->disk())->delete($personnel->photo_path);
    }

    public function response(Personnel $personnel): Response
    {
        $disk = Storage::disk($this->disk());

        abort_if(blank($personnel->photo_path) || ! $disk->exists($personnel->photo_path), 404);

        return response($disk->get($personnel->photo_path), 200, [
            'Content-Type' => $disk->mimeType($personnel->photo_path) ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function disk(): string
    {
        return (string) config('access.photos.disk', 'local');
    }
}

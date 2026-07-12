<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class MediaController extends Controller
{
    private const UPLOAD_DIRECTORY = 'uploads/media'; // relative to public/

    /**
     * Return the media gallery as JSON, most recent first, for the picker
     * modal in the product form. Not an Inertia page — this is called via
     * fetch() from within the form itself.
     */
    public function index(Request $request)
    {
        $media = Media::orderBy('created_at', 'desc')
            ->limit(100)
            ->get()
            ->map(fn (Media $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'url' => $item->resolvedUrl(),
            ]);

        return response()->json($media);
    }

    /**
     * Upload a new file into the shared media library.
     */
    public function store(Request $request)
{
    $isImage = $request->hasFile('file')
        && str_starts_with((string) $request->file('file')->getClientMimeType(), 'image/');
 
    $validated = $request->validate([
        'file' => [
            'required',
            'file',
            'max:4096',
            $isImage ? 'image' : 'mimes:pdf,doc,docx',
        ],
        'name' => ['nullable', 'string', 'max:255'],
    ]);
 
    $file = $request->file('file');
    $filename = uniqid('media_') . '.' . $file->getClientOriginalExtension();
 
    $destination = public_path(self::UPLOAD_DIRECTORY);
    if (! File::isDirectory($destination)) {
        File::makeDirectory($destination, 0755, true);
    }
 
    $file->move($destination, $filename);
 
    $media = Media::create([
        'type' => 'upload',
        'path' => $filename,
        'name' => $validated['name'] ?? $file->getClientOriginalName(),
        'original_name' => $file->getClientOriginalName(),
        'mime_type' => $file->getClientMimeType(),
        'size' => $file->getSize(),
    ]);
 
    return response()->json([
        'id' => $media->id,
        'name' => $media->name,
        'url' => $media->resolvedUrl(),
        'original_name' => $media->original_name,
        'mime_type' => $media->mime_type,
        'is_image' => $media->is_image,
        'formatted_size' => $media->formatted_size,
    ]);
}

    /**
     * Register an external image URL into the shared media library,
     * so it can be reused across products without downloading it.
     */
    public function storeUrl(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $media = Media::create([
            'type' => 'url',
            'url' => $validated['url'],
            'name' => $validated['name'] ?? null,
        ]);

        return response()->json([
            'id' => $media->id,
            'name' => $media->name,
            'url' => $media->resolvedUrl(),
        ]);
    }
}
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    /**
     * Store an uploaded image on the public disk and return its URL.
     * Used for book covers, chapter illustrations, etc.
     */
    public function image(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'max:8192'], // up to 8 MB
        ]);

        $path = $request->file('file')->store('uploads', 'public');

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ],
        ]);
    }
}

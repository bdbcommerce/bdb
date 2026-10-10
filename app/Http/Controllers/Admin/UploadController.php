<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function image(Request $request): JsonResponse
    {
        $request->validate(['image' => 'required|image|max:8192']);

        $path = $request->file('image')->store('editor', 'public');

        return response()->json(['url' => asset('storage/'.$path)]);
    }
}

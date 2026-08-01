<?php

namespace App\Http\Middleware;

use App\Domain\Teachers\Models\Teachers;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeacher
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Teachers) {
            return response()->json([
                'message' => 'Forbidden. Teacher access only.',
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Domain\Student\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Student) {
            return response()->json([
                'message' => 'Forbidden. Student access only.',
            ], 403);
        }

        // Every request from the student app keeps them "active now" for their teacher.
        $request->user()->markSeen();

        return $next($request);
    }
}

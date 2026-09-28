<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Speech\Services\PronunciationAssessmentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Hands the browser a short-lived Azure Speech token.
 *
 * Real-time assessment needs the child's device to stream audio straight to
 * Azure — routing it through this API would add the very latency that makes
 * word-by-word feedback feel live. The subscription key stays here; the browser
 * only ever gets a ten-minute token, and only while signed in.
 */
class SpeechTokenController extends Controller
{
    public function __construct(private PronunciationAssessmentService $speech) {}

    public function __invoke(): JsonResponse
    {
        if (! $this->speech->isConfigured()) {
            return response()->json([
                'message' => 'Speech is not configured. Add AZURE_SPEECH_KEY and AZURE_SPEECH_REGION to the API .env file.',
            ], 503);
        }

        try {
            $token = $this->speech->issueToken();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not start live reading. Please try again.',
            ], 502);
        }

        return response()->json([
            'data' => [
                'token' => $token,
                'region' => config('services.azure_speech.region'),
                // Azure's tokens last ten minutes. Telling the client nine
                // leaves it room to refresh before a long reading is cut off
                // mid-sentence.
                'expires_in' => 540,
            ],
        ]);
    }
}

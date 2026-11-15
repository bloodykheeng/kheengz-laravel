<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

#[Group('Email Test', 'Endpoints for testing email delivery and user lookups.', weight: 99)]
class EmailTestController extends Controller
{
    /**
     * Send a test email to a given address.
     *
     * Useful for verifying mail configuration in any environment.
     */
    #[BodyParameter('email', 'Recipient email address.', required: true, type: 'string', example: 'test@example.com')]
    #[BodyParameter('message', 'Body text to include in the test email.', required: true, type: 'string', example: 'Hello from Kheengz!')]
    public function testEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'message' => ['required', 'string'],
        ]);

        try {
            Mail::send(
                'emails.email_test',
                ['messageBody' => $validated['message']],
                function ($message) use ($validated) {
                    $message->to($validated['email'])->subject(config('app.name').' test email');
                }
            );

            return response()->json(['status' => 'Email sent successfully.']);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'Email failed to send.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Look up a user by email address and return their roles.
     *
     * Helpful for debugging role assignments in development.
     */
    #[QueryParameter('email', 'The user\'s email address.', required: true, type: 'string', example: 'john@example.com')]
    public function getUserByEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::with(['roles'])->where('email', $request->input('email'))->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'user' => new UserResource($user),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }
}

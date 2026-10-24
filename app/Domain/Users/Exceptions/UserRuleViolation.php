<?php

namespace App\Domain\Users\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule stopped a user-management change. Renders itself in the app's standard
 * {message, errors, code} error shape (14.3), so Actions can throw it from anywhere.
 */
final class UserRuleViolation extends RuntimeException
{
    private function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function selfModification(): self
    {
        return new self("You can't change your own role or deactivate your own account.", 'cannot_modify_self', 422);
    }

    public static function lastAdmin(): self
    {
        return new self(
            'This is the last active administrator. Make someone else an administrator first.',
            'last_admin',
            409,
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => [], 'code' => $this->errorCode], $this->status);
    }

    /** An expected business outcome, not an error worth logging. */
    public function report(): bool
    {
        return true;
    }
}

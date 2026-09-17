<?php

namespace App\Exceptions;

use App\Support\Enums\ApiErrorCode;
use RuntimeException;

/**
 * Thrown when a client attempts to write a stale version of a resource.
 * Rendered as a 409 with the SYNC_CONFLICT error code and conflict metadata.
 */
class ConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Resource has changed on the server.',
        public readonly array $context = [],
        public readonly ApiErrorCode $errorCode = ApiErrorCode::SyncConflict,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function forResource(array $context): self
    {
        return new self('Resource has changed on the server.', $context);
    }
}

<?php

namespace App\Support\Enums;

/**
 * Stable, machine-readable API error codes. Clients should branch on these
 * values rather than on human readable messages.
 */
enum ApiErrorCode: string
{
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case TwoFactorRequired = 'TWO_FACTOR_REQUIRED';
    case InvalidToken = 'INVALID_TOKEN';
    case TokenExpired = 'TOKEN_EXPIRED';
    case TokenReused = 'TOKEN_REUSED';
    case PasswordConfirmationRequired = 'PASSWORD_CONFIRMATION_REQUIRED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case ValidationError = 'VALIDATION_ERROR';
    case SyncConflict = 'SYNC_CONFLICT';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case OperationFailed = 'OPERATION_FAILED';
    case InternalError = 'INTERNAL_ERROR';
}

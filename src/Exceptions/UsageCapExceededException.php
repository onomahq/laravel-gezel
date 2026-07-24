<?php

namespace Onomahq\Gezel\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * The middleware refused the call because the owner's monthly token cap is
 * exhausted (429 + error.type=usage_limit_exceeded). Not retryable until
 * $resetsAt: retrying earlier can only burn requests, never succeed, so the
 * compute clients treat it as permanent regardless of the caller's `retries`.
 */
class UsageCapExceededException extends RuntimeException
{
    public function __construct(string $message, public readonly ?CarbonImmutable $resetsAt)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponseBody(array $body): self
    {
        $resetsAt = null;

        if (is_string($body['reset'] ?? null)) {
            try {
                $resetsAt = CarbonImmutable::parse($body['reset']);
            } catch (Throwable) {
                $resetsAt = null;
            }
        }

        $message = is_string($body['error']['message'] ?? null)
            ? $body['error']['message']
            : 'Monthly usage limit exceeded.';

        return new self($message, $resetsAt);
    }
}

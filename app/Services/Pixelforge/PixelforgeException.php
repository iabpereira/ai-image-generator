<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A Pixelforge API error, classified by how the caller should react to it.
 */
final class PixelforgeException extends RuntimeException
{
    private const array THROTTLE_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded'];

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            status: $response->status(),
            errorCode: (string) $response->json('error.code', 'http_'.$response->status()),
            message: (string) $response->json('error.message', 'Pixelforge request failed.'),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public static function connectionFailed(ConnectionException $exception): self
    {
        return new self(0, 'connection_failed', $exception->getMessage());
    }

    /**
     * Temporarily over a rate or concurrency limit: wait and try again.
     */
    public function isThrottled(): bool
    {
        return in_array($this->errorCode, self::THROTTLE_CODES, true);
    }

    /**
     * Pixelforge or the network is struggling: back off and retry a few times.
     */
    public function isTransient(): bool
    {
        return $this->errorCode === 'overloaded' || $this->status === 0 || $this->status >= 500;
    }

    /**
     * A message safe to show the end user.
     */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Your prompt was rejected by the content policy. You were not charged.',
            'daily_limit_exceeded' => 'The daily generation limit was reached. Please try again tomorrow. You were not charged.',
            'validation_failed' => 'Pixelforge rejected the request: '.$this->getMessage(),
            default => 'Pixelforge could not accept this generation. You were not charged.',
        };
    }
}

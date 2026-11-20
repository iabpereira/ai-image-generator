<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Stored locally, not yet accepted by Pixelforge. */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function canTransitionTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    /**
     * The statuses a generation may be in to legally move into $next.
     *
     * @return list<self>
     */
    public static function predecessorsOf(self $next): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => $status->canTransitionTo($next),
        ));
    }

    private function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}

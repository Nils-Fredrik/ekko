<?php

namespace App\Enums;

/**
 * Status of an action (docs/spec.md 11.3, ADR 0034). new and expired are set by the generation only; a user
 * moves an action between the others. The open statuses are the ones the generation keeps up to date.
 */
enum ActionStatus: string
{
    case New = 'new';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Declined = 'declined';
    case Expired = 'expired';

    /** @return list<self> */
    public static function open(): array
    {
        return [self::New, self::Planned, self::InProgress];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * The statuses a user may move an action to from this one.
     *
     * @return list<self>
     */
    public function userTargets(): array
    {
        return match ($this) {
            self::New => [self::Planned, self::InProgress, self::Done, self::Declined],
            self::Planned => [self::InProgress, self::Done, self::Declined],
            self::InProgress => [self::Planned, self::Done, self::Declined],
            self::Done => [self::InProgress],
            self::Declined, self::Expired => [self::Planned],
        };
    }

    public function userMayMoveTo(self $target): bool
    {
        return in_array($target, $this->userTargets(), true);
    }
}

<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Support;

use CraftCms\Cms\User\Elements\User;

use function CraftCms\Cms\craftAuth;

/**
 * Runs code as another control panel user (a queued replacement runs as the user who started it: revision creator,
 * permission and field condition checks), then switches back: a queue worker goes on with other jobs.
 */
final class ActingUser
{
    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(?User $user, callable $fn): mixed
    {
        $guard = craftAuth();
        $previous = $guard->user();
        self::set($user);
        try {
            return $fn();
        } finally {
            self::set($previous);
        }
    }

    private static function set(mixed $user): void
    {
        $guard = craftAuth();
        if ($user instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            $guard->setUser($user);
        } elseif (method_exists($guard, 'forgetUser')) {
            $guard->forgetUser();
        }
    }
}

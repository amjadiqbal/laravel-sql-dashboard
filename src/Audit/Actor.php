<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/** Who ran something, as recorded in the audit log. */
final readonly class Actor
{
    public function __construct(
        public ?string $id,
        public string $label,
        public ?string $ip = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $user = $request->user();

        return new self(self::id($user), self::label($user), $request->ip());
    }

    public static function system(string $label): self
    {
        return new self(null, $label);
    }

    public static function id(?Authenticatable $user): ?string
    {
        $id = $user?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    public static function label(?Authenticatable $user): string
    {
        if ($user === null) {
            return 'guest';
        }
        foreach (['name', 'email'] as $attribute) {
            $value = $user->{$attribute} ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'user #'.self::id($user);
    }
}

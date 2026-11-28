<?php

namespace App\Modules\Tenancy\Data;

use DateTimeZone;
use InvalidArgumentException;

final readonly class SchoolProfileUpdate
{
    public string $name;

    public function __construct(
        string $name,
        public string $timezone,
        public ?string $accentColor,
    ) {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 200
            || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)
            || ($accentColor !== null && preg_match('/\A#[0-9a-fA-F]{6}\z/', $accentColor) !== 1)) {
            throw new InvalidArgumentException('Invalid school profile update.');
        }
        $this->name = $name;
    }

    /** Reject unknown fields rather than silently mass-assigning tenant metadata. */
    public static function fromArray(array $input): self
    {
        if (count($input) !== 3 || array_diff(array_keys($input), ['name', 'timezone', 'accent_color']) !== []
            || ! is_string($input['name'] ?? null) || ! is_string($input['timezone'] ?? null)
            || ! array_key_exists('accent_color', $input)
            || ($input['accent_color'] !== null && ! is_string($input['accent_color']))) {
            throw new InvalidArgumentException('Only name, timezone and accent_color are accepted.');
        }

        return new self($input['name'], $input['timezone'], $input['accent_color']);
    }
}

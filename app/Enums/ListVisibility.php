<?php

namespace App\Enums;

enum ListVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Unlisted = 'unlisted';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Private => 'Private',
            self::Unlisted => 'Unlisted',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'Visible on your profile and searchable',
            self::Private => 'Only you can see this list',
            self::Unlisted => 'Anyone with the link can view',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Public => 'globe-alt',
            self::Private => 'lock-closed',
            self::Unlisted => 'link',
        };
    }
}

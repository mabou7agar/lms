<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Where an expiry reminder is delivered. Stored per product as a list so an admin can, for example,
 * send an email for a certificate expiry but only surface a dashboard banner for course access.
 * Delivery itself is scheduled by the reminder wave; this enum is the stored configuration.
 */
enum ReminderChannel: string
{
    case Email = 'email';
    case InApp = 'in_app';
    case Banner = 'banner';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::InApp => 'In-app notification',
            self::Banner => 'Dashboard banner',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}

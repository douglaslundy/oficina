<?php
declare(strict_types=1);

namespace App\Tenancy;

class TenancyContext
{
    private static ?string $oficina_id  = null;
    private static ?string $oficina_slug = null;

    public static function set(string $id, ?string $slug = null): void
    {
        static::$oficina_id   = $id;
        static::$oficina_slug = $slug;
    }

    public static function get(): ?string
    {
        return static::$oficina_id;
    }

    public static function getSlug(): ?string
    {
        return static::$oficina_slug;
    }

    public static function clear(): void
    {
        static::$oficina_id   = null;
        static::$oficina_slug = null;
    }

    public static function has(): bool
    {
        return static::$oficina_id !== null;
    }
}

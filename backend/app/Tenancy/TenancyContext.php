<?php
declare(strict_types=1);

namespace App\Tenancy;

class TenancyContext
{
    private static ?string $oficina_id = null;

    public static function set(string $id): void
    {
        static::$oficina_id = $id;
    }

    public static function get(): ?string
    {
        return static::$oficina_id;
    }

    public static function clear(): void
    {
        static::$oficina_id = null;
    }

    public static function has(): bool
    {
        return static::$oficina_id !== null;
    }
}

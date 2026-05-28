<?php
declare(strict_types=1);

namespace API\Core;

/**
 * Singleton holding the current establishment ID for the request.
 * Set once during auth/middleware, read everywhere.
 */
class EstablishmentContext
{
    private static ?int $id = null;
    private static ?int $resolvedDefault = null;

    public static function set(int $id): void
    {
        self::$id = $id;
    }

    public static function id(): int
    {
        if (self::$id !== null) {
            return self::$id;
        }
        // No scope set: bind to the sole establishment if there is exactly one,
        // otherwise refuse to guess (data-scope safety — no silent fallback to 1).
        return self::$id = self::resolveDefault();
    }

    /**
     * Resolve the default establishment when no scope is set.
     * Returns the sole establishment's id, or throws if zero/multiple exist.
     */
    private static function resolveDefault(): int
    {
        if (self::$resolvedDefault !== null) {
            return self::$resolvedDefault;
        }
        $ids = getPDO()->query('SELECT id FROM etablissements')->fetchAll(\PDO::FETCH_COLUMN);
        if (count($ids) === 1) {
            return self::$resolvedDefault = (int) $ids[0];
        }
        throw new \RuntimeException(
            'EstablishmentContext is not set and ' . count($ids) . ' establishments exist; '
            . 'refusing to default establishment scope (data-scope safety).'
        );
    }

    public static function isSet(): bool
    {
        return self::$id !== null;
    }

    /**
     * Scope a PDO query builder by adding WHERE etablissement_id = ?
     * Returns the value to bind.
     */
    public static function scopeValue(): int
    {
        return self::id();
    }

    /**
     * Returns SQL fragment: "AND etablissement_id = ?"
     * For use in existing WHERE clauses.
     */
    public static function sqlAnd(): string
    {
        return ' AND etablissement_id = ' . self::id();
    }

    /**
     * Returns SQL fragment: "WHERE etablissement_id = ?"
     * For use as the primary WHERE clause.
     */
    public static function sqlWhere(): string
    {
        return ' WHERE etablissement_id = ' . self::id();
    }

    public static function reset(): void
    {
        self::$id = null;
        self::$resolvedDefault = null;
    }
}

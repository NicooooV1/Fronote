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
     * Prefer placeholderAnd() + scopeValue() for new code to keep the parameterized-query pattern consistent.
     */
    public static function sqlAnd(): string
    {
        return ' AND etablissement_id = ' . self::id();
    }

    /**
     * Returns SQL fragment: "WHERE etablissement_id = ?"
     * Prefer placeholderWhere() + scopeValue() for new code to keep the parameterized-query pattern consistent.
     */
    public static function sqlWhere(): string
    {
        return ' WHERE etablissement_id = ' . self::id();
    }

    /**
     * Returns placeholder SQL fragment: " AND etablissement_id = ?"
     * Bind with scopeValue(). Preferred over sqlAnd() for new parameterized queries.
     */
    public static function placeholderAnd(): string
    {
        return ' AND etablissement_id = ?';
    }

    /**
     * Returns placeholder SQL fragment: " WHERE etablissement_id = ?"
     * Bind with scopeValue(). Preferred over sqlWhere() for new parameterized queries.
     */
    public static function placeholderWhere(): string
    {
        return ' WHERE etablissement_id = ?';
    }

    public static function reset(): void
    {
        self::$id = null;
        self::$resolvedDefault = null;
    }
}

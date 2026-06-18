<?php
declare(strict_types=1);

namespace API\Core;

/**
 * Splits a SQL script into individual statements.
 *
 * Handles single-quoted and double-quoted strings, -- and # line comments,
 * and block comments. Does NOT handle DELIMITER changes or stored procedures.
 * Sufficient for schema migrations and mysqldump output.
 */
final class SqlSplitter
{
    /**
     * @return string[]
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $inSingle = $inDouble = $inLineComment = $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $c    = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($c === "\n") {
                    $inLineComment = false;
                    $buffer .= $c;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($c === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }
            if (!$inSingle && !$inDouble) {
                if ($c === '-' && $next === '-') { $inLineComment = true; $i++; continue; }
                if ($c === '#')                  { $inLineComment = true; continue; }
                if ($c === '/' && $next === '*') { $inBlockComment = true; $i++; continue; }
            }

            $prev = $i > 0 ? $sql[$i - 1] : '';
            if ($c === "'" && !$inDouble && $prev !== '\\') {
                $inSingle = !$inSingle;
            } elseif ($c === '"' && !$inSingle && $prev !== '\\') {
                $inDouble = !$inDouble;
            }

            if ($c === ';' && !$inSingle && !$inDouble) {
                $stmt = trim($buffer);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buffer = '';
            } else {
                $buffer .= $c;
            }
        }

        $stmt = trim($buffer);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }
}

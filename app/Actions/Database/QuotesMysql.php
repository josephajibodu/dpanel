<?php

namespace App\Actions\Database;

trait QuotesMysql
{
    /**
     * Quote a value as a MySQL string literal. Backslashes are escaped as well as
     * quotes, since MySQL treats a backslash as an escape character by default and
     * a trailing one would otherwise swallow the closing quote.
     */
    private function mysqlString(string $value): string
    {
        return "'".strtr($value, ['\\' => '\\\\', "'" => "\\'", "\0" => '\\0'])."'";
    }

    /**
     * Quote a value as a MySQL identifier (database name and the like).
     */
    private function mysqlIdentifier(string $value): string
    {
        return '`'.str_replace('`', '``', $value).'`';
    }
}

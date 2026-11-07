<?php

namespace App\Actions\Backups;

use App\Actions\Database\GrantsPostgresPrivileges;
use App\Actions\Database\RunsSqlPrivately;
use App\Exceptions\BackupFailedException;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Services\Ssh\SshConnection;
use RuntimeException;

/**
 * Loads a dump made somewhere else into a chosen database. Unlike a Flitops
 * backup, such a dump may name another database, belong to roles that don't
 * exist here, or expect an empty database, so it is cleaned up on the way in.
 * Classes using this trait must also use EscapesShell.
 */
trait ImportsForeignDumps
{
    use GrantsPostgresPrivileges, RunsSqlPrivately;

    /**
     * The shell pipeline that streams the decompressed dump (from `$download`)
     * into the database. `gunzip -cf` passes a plain .sql file through as is.
     */
    private function buildImportPipeline(
        SshConnection $connection,
        string $directory,
        Server $server,
        ServerDatabase $serverDatabase,
        string $mysqlCredentials,
        string $download,
    ): string {
        $databaseName = $this->escapeForShell($serverDatabase->name);
        $credentialsOption = '--defaults-extra-file='.$this->escapeForShell($mysqlCredentials);

        return match ($server->database_type) {
            'mysql' => $download.' | gunzip -cf | '.$this->mysqlImportFilter()." | sudo mysql {$credentialsOption} {$databaseName}",
            'mariadb' => $download.' | gunzip -cf | '.$this->mysqlImportFilter()." | sudo mariadb {$credentialsOption} {$databaseName}",
            'postgresql' => $this->buildPostgresImportPipeline($connection, $directory, $serverDatabase, $download),
            default => throw new RuntimeException("Importing a dump isn't supported for {$server->database_type}."),
        };
    }

    /**
     * Work left after a Postgres import has committed: the schema was
     * recreated, so every user of the database gets its access back.
     */
    private function finishImport(SshConnection $connection, Server $server, ServerDatabase $serverDatabase): void
    {
        if ($server->database_type !== 'postgresql') {
            return;
        }

        $users = DatabaseUser::query()
            ->where('server_id', $server->id)
            ->where('status', 'ready')
            ->whereJsonContains('databases', $serverDatabase->name)
            ->get();

        foreach ($users as $databaseUser) {
            $this->grantPostgresPrivileges($connection, $databaseUser, $this->postgresIdentifier($databaseUser->username));
        }
    }

    /**
     * Drops the `CREATE DATABASE`/`USE` lines that would send the data to the
     * database the dump came from. Anchored to the start of the line so a row
     * that merely mentions them is kept. `|| [ $? -eq 1 ]` lets an empty
     * result through: grep exits 1 when nothing is left.
     */
    private function mysqlImportFilter(): string
    {
        return "{ grep -Eiv '^(CREATE DATABASE|USE )' || [ \$? -eq 1 ]; }";
    }

    /**
     * Postgres dumps usually lack `DROP TABLE` lines, so the public schema is
     * emptied first, and everything runs in one transaction: if any statement
     * fails, or the download stops early (no COMMIT arrives), Postgres rolls
     * it all back and the database is left as it was. Objects are created as
     * the postgres superuser, so they are handed to the database's read-write
     * user afterwards — otherwise the app couldn't use or migrate its tables.
     */
    private function buildPostgresImportPipeline(
        SshConnection $connection,
        string $directory,
        ServerDatabase $serverDatabase,
        string $download,
    ): string {
        $owner = $serverDatabase->importOwner();

        if (! $owner) {
            throw new BackupFailedException("Add a database user with read-write access to {$serverDatabase->name} first. The imported tables are handed to that user.");
        }

        $prelude = "{$directory}/prelude.sql";
        $filter = "{$directory}/filter.awk";
        $postlude = "{$directory}/postlude.sql";

        $connection->upload("BEGIN;\nDROP SCHEMA IF EXISTS public CASCADE;\nCREATE SCHEMA public;\n", $prelude);
        $connection->upload($this->postgresImportFilter(), $filter);
        $connection->upload($this->postgresOwnershipTransfer($owner->username)."\nCOMMIT;\n", $postlude);

        $stream = '{ cat '.$this->escapeForShell($prelude).
            ' && '.$download.' | gunzip -cf | awk -f '.$this->escapeForShell($filter).
            ' && cat '.$this->escapeForShell($postlude).'; }';

        return $stream.' | sudo -u postgres psql -X -q -o /dev/null -v ON_ERROR_STOP=1 -d '.$this->escapeForShell($serverDatabase->name);
    }

    /**
     * Skips what can't or shouldn't run inside the target database: switching
     * or recreating databases, `DROP`s (the schema was just emptied), and
     * owner and privilege lines naming roles from the old host. psql's
     * `\restrict` lines are dropped too, since older servers' psql doesn't
     * know them. COPY data is passed through untouched, so a row that starts
     * with one of these words is never lost.
     */
    private function postgresImportFilter(): string
    {
        return <<<'AWK'
            in_copy { print; if ($0 == "\\.") in_copy = 0; next }
            /^COPY .* FROM stdin;$/ { in_copy = 1; print; next }
            /^(DROP |CREATE DATABASE |ALTER DATABASE |GRANT |REVOKE |ALTER DEFAULT PRIVILEGES |CREATE SCHEMA public;|ALTER SCHEMA public OWNER )/ { next }
            /^ALTER .* OWNER TO / { next }
            /^\\(connect|c|restrict|unrestrict)( |$)/ { next }
            { print }
            AWK;
    }

    /**
     * Hand every table, view, sequence, function and type in the public
     * schema to the given role. Objects that belong to an extension stay with
     * it, and sequences behind a serial or identity column follow their table.
     */
    private function postgresOwnershipTransfer(string $owner): string
    {
        $ownerLiteral = "'".str_replace("'", "''", $owner)."'";

        return <<<SQL
            DO \$flitops\$
            DECLARE
                owner_name text := {$ownerLiteral};
                obj record;
            BEGIN
                FOR obj IN
                    SELECT c.oid::regclass::text AS name,
                        CASE c.relkind WHEN 'S' THEN 'SEQUENCE' WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'f' THEN 'FOREIGN TABLE' ELSE 'TABLE' END AS kind
                    FROM pg_catalog.pg_class c
                    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                    WHERE n.nspname = 'public'
                        AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S')
                        AND NOT EXISTS (
                            SELECT 1 FROM pg_catalog.pg_depend d
                            WHERE d.classid = 'pg_catalog.pg_class'::regclass AND d.objid = c.oid
                                AND (d.deptype = 'e' OR (c.relkind = 'S' AND d.deptype IN ('a', 'i')))
                        )
                LOOP
                    EXECUTE format('ALTER %s %s OWNER TO %I', obj.kind, obj.name, owner_name);
                END LOOP;

                FOR obj IN
                    SELECT p.oid::regprocedure::text AS name,
                        CASE p.prokind WHEN 'p' THEN 'PROCEDURE' ELSE 'FUNCTION' END AS kind
                    FROM pg_catalog.pg_proc p
                    JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                    WHERE n.nspname = 'public'
                        AND p.prokind IN ('f', 'p')
                        AND NOT EXISTS (
                            SELECT 1 FROM pg_catalog.pg_depend d
                            WHERE d.classid = 'pg_catalog.pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e'
                        )
                LOOP
                    EXECUTE format('ALTER %s %s OWNER TO %I', obj.kind, obj.name, owner_name);
                END LOOP;

                FOR obj IN
                    SELECT t.oid::regtype::text AS name,
                        CASE t.typtype WHEN 'd' THEN 'DOMAIN' ELSE 'TYPE' END AS kind
                    FROM pg_catalog.pg_type t
                    JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
                    WHERE n.nspname = 'public'
                        AND t.typtype IN ('e', 'd')
                        AND NOT EXISTS (
                            SELECT 1 FROM pg_catalog.pg_depend d
                            WHERE d.classid = 'pg_catalog.pg_type'::regclass AND d.objid = t.oid AND d.deptype = 'e'
                        )
                LOOP
                    EXECUTE format('ALTER %s %s OWNER TO %I', obj.kind, obj.name, owner_name);
                END LOOP;
            END
            \$flitops\$;
            SQL;
    }
}

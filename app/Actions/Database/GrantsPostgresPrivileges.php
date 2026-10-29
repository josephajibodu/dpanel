<?php

namespace App\Actions\Database;

use App\Models\DatabaseUser;
use App\Services\Ssh\SshConnection;

trait GrantsPostgresPrivileges
{
    /**
     * Grant the user access to each of their assigned databases, matching
     * their `permission`.
     *
     * Read-write users also get CREATE on the public schema: since PostgreSQL 15
     * (Ubuntu 24.04 ships 16) the database-level GRANT ALL no longer lets them
     * create tables there, which breaks the app's migrations.
     *
     * Read-only users need their own schema/table-level grants, plus a
     * default-privileges rule so they can read tables created later. Such a rule
     * only covers tables created by the role it names, so one is set for each
     * read-write user on the database (the roles the app's migrations run as),
     * from whichever side is set up second.
     */
    private function grantPostgresPrivileges(
        SshConnection $connection,
        DatabaseUser $databaseUser,
        string $userIdent,
    ): void {
        foreach ($databaseUser->databases as $dbName) {
            $dbIdent = $this->postgresIdentifier($dbName);

            if ($databaseUser->permission !== 'readonly') {
                $this->runPostgresAsSuperuser($connection, ['GRANT ALL PRIVILEGES ON DATABASE '.$dbIdent.' TO '.$userIdent]);

                $statements = ['GRANT ALL ON SCHEMA public TO '.$userIdent];

                foreach ($this->postgresPeerUsernames($databaseUser, $dbName, 'readonly') as $readonlyUsername) {
                    $statements[] = 'ALTER DEFAULT PRIVILEGES FOR ROLE '.$userIdent.' IN SCHEMA public GRANT SELECT ON TABLES TO '.$this->postgresIdentifier($readonlyUsername);
                }

                $this->runPostgresAsSuperuser($connection, $statements, $dbName);

                continue;
            }

            $this->runPostgresAsSuperuser($connection, ['GRANT CONNECT ON DATABASE '.$dbIdent.' TO '.$userIdent]);

            $statements = [
                'GRANT USAGE ON SCHEMA public TO '.$userIdent,
                'GRANT SELECT ON ALL TABLES IN SCHEMA public TO '.$userIdent,
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO '.$userIdent,
            ];

            foreach ($this->postgresPeerUsernames($databaseUser, $dbName, 'readwrite') as $readwriteUsername) {
                $statements[] = 'ALTER DEFAULT PRIVILEGES FOR ROLE '.$this->postgresIdentifier($readwriteUsername).' IN SCHEMA public GRANT SELECT ON TABLES TO '.$userIdent;
            }

            $this->runPostgresAsSuperuser($connection, $statements, $dbName);
        }
    }

    /**
     * Take back everything grantPostgresPrivileges() may have given the user,
     * so switching between readonly and readwrite doesn't leave stale broader
     * privileges (schema CREATE, future-table rules) from the previous grant.
     */
    private function revokePostgresPrivileges(
        SshConnection $connection,
        DatabaseUser $databaseUser,
        string $userIdent,
    ): void {
        foreach ($databaseUser->databases as $dbName) {
            $this->runPostgresAsSuperuser($connection, ['REVOKE ALL PRIVILEGES ON DATABASE '.$this->postgresIdentifier($dbName).' FROM '.$userIdent]);

            $statements = [
                'REVOKE ALL ON SCHEMA public FROM '.$userIdent,
                'REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM '.$userIdent,
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON TABLES FROM '.$userIdent,
            ];

            foreach ($this->postgresPeerUsernames($databaseUser, $dbName, 'readwrite') as $readwriteUsername) {
                $statements[] = 'ALTER DEFAULT PRIVILEGES FOR ROLE '.$this->postgresIdentifier($readwriteUsername).' IN SCHEMA public REVOKE SELECT ON TABLES FROM '.$userIdent;
            }

            $this->runPostgresAsSuperuser($connection, $statements, $dbName);
        }
    }

    /**
     * Usernames of the server's other ready users with the given permission on
     * the database. Only ready users are known to exist as roles on the server.
     *
     * @return array<int, string>
     */
    private function postgresPeerUsernames(DatabaseUser $databaseUser, string $dbName, string $permission): array
    {
        return DatabaseUser::query()
            ->where('server_id', $databaseUser->server_id)
            ->whereKeyNot($databaseUser->getKey())
            ->where('permission', $permission)
            ->where('status', 'ready')
            ->whereJsonContains('databases', $dbName)
            ->pluck('username')
            ->all();
    }

    private function postgresIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}

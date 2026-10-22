<?php

namespace App\Actions\Database;

use App\Models\DatabaseUser;
use App\Services\Ssh\SshConnection;

trait GrantsPostgresPrivileges
{
    /**
     * Grant the user access to each of their assigned databases, matching
     * their `permission`. Read-only access needs its own schema/table-level
     * grants (plus a default-privileges rule for tables created later)
     * since PostgreSQL's database-level GRANT ALL doesn't cover those.
     */
    private function grantPostgresPrivileges(
        SshConnection $connection,
        DatabaseUser $databaseUser,
        string $userIdent,
    ): void {
        $readonly = $databaseUser->permission === 'readonly';

        foreach ($databaseUser->databases as $dbName) {
            $dbIdent = '"'.str_replace('"', '""', $dbName).'"';

            if (! $readonly) {
                $this->runPostgresAsSuperuser($connection, ['GRANT ALL PRIVILEGES ON DATABASE '.$dbIdent.' TO '.$userIdent]);

                continue;
            }

            $this->runPostgresAsSuperuser($connection, ['GRANT CONNECT ON DATABASE '.$dbIdent.' TO '.$userIdent]);

            $this->runPostgresAsSuperuser($connection, [
                'GRANT USAGE ON SCHEMA public TO '.$userIdent,
                'GRANT SELECT ON ALL TABLES IN SCHEMA public TO '.$userIdent,
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO '.$userIdent,
            ], $dbName);
        }
    }
}

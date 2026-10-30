<?php

namespace App\Actions\Database;

use App\Models\DatabaseUser;

/**
 * MySQL accounts are tied to where the user signs in from. While other
 * servers are connected to a database server, each user also has an account
 * for any host ('%') with the same password and grants; the firewall is what
 * limits which servers can reach MySQL at all.
 * Classes using this trait must also use QuotesMysql.
 */
trait ManagesRemoteMysqlAccounts
{
    /**
     * Statements creating or refreshing the user's '%' account to match their own.
     *
     * @return array<int, string>
     */
    private function remoteMysqlAccountStatements(DatabaseUser $user): array
    {
        $account = $this->remoteMysqlAccount($user);
        $password = $this->mysqlString($user->password);
        $privilege = $user->permission === 'readonly' ? 'SELECT' : 'ALL PRIVILEGES';

        $statements = [
            "CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$password}",
            "ALTER USER {$account} IDENTIFIED BY {$password}",
            "REVOKE ALL PRIVILEGES ON *.* FROM {$account}",
        ];

        foreach ($user->databases ?? [] as $database) {
            $statements[] = "GRANT {$privilege} ON {$this->mysqlIdentifier($database)}.* TO {$account}";
        }

        return $statements;
    }

    private function remoteMysqlAccount(DatabaseUser $user): string
    {
        return $this->mysqlString($user->username)."@'%'";
    }

    /**
     * Whether the user needs a '%' account: other servers are connected, and
     * their own account isn't already for any host.
     */
    private function needsRemoteMysqlAccount(DatabaseUser $user): bool
    {
        return ($user->host ?? 'localhost') !== '%' && $user->server->hasRemoteDatabaseClients();
    }
}

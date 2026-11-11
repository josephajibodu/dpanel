import { Link } from '@inertiajs/react';
import {
    CircleNotchIcon,
    EyeIcon,
    EyeSlashIcon,
    KeyIcon,
} from '@phosphor-icons/react';
import { type ReactNode, useState } from 'react';

import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePermission } from '@/hooks/use-permission';
import {
    type DatabaseUser,
    type Server,
    type ServerDatabase,
} from '@/types/server';

interface ConnectionInfoDialogProps {
    server: Pick<
        Server,
        'id' | 'name' | 'ip_address' | 'ssh_port' | 'database_type'
    >;
    sshUser: string;
    hasSshKey: boolean;
    databases: ServerDatabase[];
    databaseUsers: DatabaseUser[];
    initialDatabase: string;
    initialUserId?: number;
    teamPath: (path: string) => string;
    onOpenChange: (open: boolean) => void;
}

/**
 * Stands in for the password inside connection URLs until it is revealed.
 */
const PASSWORD_PLACEHOLDER = 'PASSWORD';

function FieldLabel({ children }: { children: ReactNode }) {
    return (
        <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {children}
        </div>
    );
}

function DetailField({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div className="space-y-1.5">
            <FieldLabel>{label}</FieldLabel>
            <div className="flex min-w-0 items-center gap-1 rounded-lg border bg-muted p-2.5">
                <code className="min-w-0 flex-1 truncate font-mono text-xs leading-relaxed text-foreground">
                    {value}
                </code>
                <CopyButton value={value} className="shrink-0" />
            </div>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3">
            <div className="space-y-1">
                <h3 className="text-sm font-semibold">{title}</h3>
                {description && (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

export function ConnectionInfoDialog({
    server,
    sshUser,
    hasSshKey,
    databases,
    databaseUsers,
    initialDatabase,
    initialUserId,
    teamPath,
    onOpenChange,
}: ConnectionInfoDialogProps) {
    const can = usePermission();
    const canRevealPassword = can('view_secrets');
    const [databaseName, setDatabaseName] = useState(initialDatabase);
    const [selectedUserId, setSelectedUserId] = useState(initialUserId);
    const [passwordsByUserId, setPasswordsByUserId] = useState<
        Record<number, string>
    >({});
    const [revealed, setRevealed] = useState(false);
    const [revealing, setRevealing] = useState(false);

    const databaseOptions = databases.filter((db) => db.status !== 'deleting');
    const usersWithAccess = databaseUsers.filter(
        (user) =>
            user.status !== 'deleting' && user.databases.includes(databaseName),
    );
    const databaseUser =
        usersWithAccess.find((user) => user.id === selectedUserId) ??
        usersWithAccess[0] ??
        null;
    const password = databaseUser
        ? (passwordsByUserId[databaseUser.id] ?? null)
        : null;
    const shownPassword = revealed ? password : null;

    const dbPort = server.database_type === 'postgresql' ? 5432 : 3306;
    const sshPort = server.ssh_port;
    const ip = server.ip_address ?? '';

    const handleReveal = async () => {
        if (!databaseUser) {
            return;
        }

        if (password !== null) {
            setRevealed(true);
            return;
        }

        setRevealing(true);
        try {
            const csrfToken =
                document
                    .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '';

            const response = await fetch(
                teamPath(
                    `/servers/${server.id}/database-users/${databaseUser.id}/reveal-password`,
                ),
                {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        Accept: 'application/json',
                    },
                },
            );

            if (!response.ok) {
                throw new Error(
                    `Failed to reveal password: ${response.status}`,
                );
            }

            const data = (await response.json()) as { password: string };
            setPasswordsByUserId((previous) => ({
                ...previous,
                [databaseUser.id]: data.password,
            }));
            setRevealed(true);
        } catch {
            // Leave the field masked; the user can retry.
        } finally {
            setRevealing(false);
        }
    };

    const selectDatabase = (name: string) => {
        setDatabaseName(name);
        setRevealed(false);
    };

    const selectUser = (id: string) => {
        setSelectedUserId(Number(id));
        setRevealed(false);
    };

    const scheme =
        server.database_type === 'postgresql' ? 'postgresql' : 'mysql';
    const urlUsername = encodeURIComponent(databaseUser?.username ?? '');
    const urlPassword = encodeURIComponent(
        shownPassword ?? PASSWORD_PLACEHOLDER,
    );
    const urlDatabase = encodeURIComponent(databaseName);
    const urlConnectionName = encodeURIComponent(
        `${server.name} - ${databaseName}`,
    );
    const databaseClientUrl = `${scheme}+ssh://${sshUser}@${ip}:${sshPort}/${urlUsername}:${urlPassword}@127.0.0.1:${dbPort}/${urlDatabase}?name=${urlConnectionName}&usePrivateKey=true`;
    const standardUrl = `${scheme}://${urlUsername}:${urlPassword}@127.0.0.1:${dbPort}/${urlDatabase}`;
    const tunnelCommand = `ssh -N -L ${dbPort}:127.0.0.1:${dbPort} ${sshUser}@${ip} -p ${sshPort}`;

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Connect to {databaseName}</DialogTitle>
                    <DialogDescription>
                        Use these details in TablePlus, TablePro, DBeaver, MySQL
                        Workbench, or any database app that can connect over
                        SSH.
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[70vh] space-y-6 overflow-y-auto pr-1">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="connect-database">Database</Label>
                            <Select
                                value={databaseName}
                                onValueChange={selectDatabase}
                            >
                                <SelectTrigger
                                    id="connect-database"
                                    className="font-mono"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {databaseOptions.map((db) => (
                                        <SelectItem
                                            key={db.id}
                                            value={db.name}
                                            className="font-mono"
                                        >
                                            {db.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {databaseUser && (
                            <div className="space-y-1.5">
                                <Label htmlFor="connect-user">Connect as</Label>
                                <Select
                                    value={String(databaseUser.id)}
                                    onValueChange={selectUser}
                                >
                                    <SelectTrigger
                                        id="connect-user"
                                        className="font-mono"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {usersWithAccess.map((user) => (
                                            <SelectItem
                                                key={user.id}
                                                value={String(user.id)}
                                                className="font-mono"
                                            >
                                                {user.permission === 'readonly'
                                                    ? `${user.username} (read-only)`
                                                    : user.username}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                    </div>

                    {!databaseUser ? (
                        <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                            No database user can access{' '}
                            <span className="font-mono text-foreground">
                                {databaseName}
                            </span>{' '}
                            yet. Add one on the server's Databases page, then
                            come back here to connect.
                        </div>
                    ) : (
                        <>
                            {!hasSshKey && (
                                <div className="space-y-2 rounded-md border border-yellow-200 bg-yellow-50 p-3 dark:border-yellow-900 dark:bg-yellow-950">
                                    <p className="text-sm text-yellow-800 dark:text-yellow-200">
                                        {can('access_servers')
                                            ? 'You need to add and sync an SSH key to this server before you can connect.'
                                            : "Your role doesn't include SSH access to this team's servers."}
                                    </p>
                                    {can('access_servers') && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={teamPath('/ssh-keys')}>
                                                <KeyIcon className="h-4 w-4" />
                                                Add SSH key
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            )}

                            <p className="rounded-md bg-muted/60 p-3 text-sm text-muted-foreground">
                                This database isn't open to the internet. Your
                                database app first connects to the server over
                                SSH, then reaches the database from there. That
                                means you need to fill in{' '}
                                <span className="font-medium text-foreground">
                                    both
                                </span>{' '}
                                parts below: the database details and the SSH
                                details.
                            </p>

                            <Section
                                title="1. Database details"
                                description="The main connection fields in your database app."
                            >
                                <div className="grid grid-cols-2 gap-3">
                                    <DetailField
                                        label="Host"
                                        value="127.0.0.1"
                                    />
                                    <DetailField
                                        label="Port"
                                        value={String(dbPort)}
                                    />
                                </div>
                                <p className="-mt-1 text-xs text-muted-foreground">
                                    127.0.0.1 means “the server itself”. That's
                                    correct here, because the connection goes
                                    through SSH first.
                                </p>
                                <DetailField
                                    label="Database"
                                    value={databaseName}
                                />
                                <DetailField
                                    label="Username"
                                    value={databaseUser.username}
                                />

                                {canRevealPassword && (
                                    <div className="space-y-1.5">
                                        <FieldLabel>Password</FieldLabel>
                                        <div className="flex min-w-0 items-center gap-1 rounded-lg border bg-muted p-2.5">
                                            <code className="min-w-0 flex-1 truncate font-mono text-xs leading-relaxed text-foreground">
                                                {shownPassword ??
                                                    '••••••••••••'}
                                            </code>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="h-8 w-8 shrink-0"
                                                disabled={revealing}
                                                onClick={() =>
                                                    revealed
                                                        ? setRevealed(false)
                                                        : handleReveal()
                                                }
                                                aria-label={
                                                    revealed
                                                        ? 'Hide password'
                                                        : 'Reveal password'
                                                }
                                            >
                                                {revealing ? (
                                                    <CircleNotchIcon className="h-4 w-4 animate-spin" />
                                                ) : revealed ? (
                                                    <EyeSlashIcon className="h-4 w-4" />
                                                ) : (
                                                    <EyeIcon className="h-4 w-4" />
                                                )}
                                            </Button>
                                            {shownPassword !== null && (
                                                <CopyButton
                                                    value={shownPassword}
                                                    className="shrink-0"
                                                />
                                            )}
                                        </div>
                                    </div>
                                )}
                            </Section>

                            <Section
                                title="2. SSH details"
                                description="Turn on the SSH option in your database app (“Over SSH” in TablePlus and TablePro, the SSH tab in DBeaver, “Standard TCP/IP over SSH” in MySQL Workbench), then enter these."
                            >
                                <DetailField label="SSH host" value={ip} />
                                <div className="grid grid-cols-2 gap-3">
                                    <DetailField
                                        label="SSH port"
                                        value={String(sshPort)}
                                    />
                                    <DetailField
                                        label="SSH user"
                                        value={sshUser}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <FieldLabel>SSH authentication</FieldLabel>
                                    <p className="text-sm text-muted-foreground">
                                        Choose{' '}
                                        <span className="font-medium text-foreground">
                                            private key
                                        </span>{' '}
                                        and pick the key file on your computer
                                        that matches the SSH key you added under
                                        SSH keys (for example{' '}
                                        <code className="font-mono text-xs">
                                            ~/.ssh/id_ed25519
                                        </code>
                                        ). There is no SSH password.
                                    </p>
                                </div>
                            </Section>

                            <Section
                                title="Connection URLs"
                                description={
                                    shownPassword !== null
                                        ? 'These include the password, so keep them private.'
                                        : canRevealPassword
                                          ? `Reveal the password above to put it into these URLs. Until then they show ${PASSWORD_PLACEHOLDER} in its place.`
                                          : `Replace ${PASSWORD_PLACEHOLDER} with this user's password.`
                                }
                            >
                                <DetailField
                                    label="TablePlus / TablePro URL"
                                    value={databaseClientUrl}
                                    hint="Fills in both the database and SSH details at once. In TablePlus or TablePro, create a new connection from a URL and paste this."
                                />
                                <DetailField
                                    label="Standard connection URL"
                                    value={standardUrl}
                                    hint="For tools that ask for a connection string. From your computer it only works while the SSH tunnel below is running."
                                />
                                <DetailField
                                    label="SSH tunnel command"
                                    value={tunnelCommand}
                                    hint="Run this in a terminal and leave it open. It forwards the database to the same port on your computer."
                                />
                            </Section>
                        </>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

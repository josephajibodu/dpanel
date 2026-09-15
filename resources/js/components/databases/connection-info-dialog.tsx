import { Link } from '@inertiajs/react';
import { EyeIcon, EyeOffIcon, KeyIcon, Loader2Icon } from 'lucide-react';
import { useState } from 'react';

import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { type DatabaseUser, type Server } from '@/types/server';

interface ConnectionInfoDialogProps {
    server: Pick<Server, 'id' | 'ip_address' | 'ssh_port' | 'database_type'>;
    sshUser: string;
    hasSshKey: boolean;
    databaseUser: DatabaseUser;
    teamPath: (path: string) => string;
    onOpenChange: (open: boolean) => void;
}

function DetailField({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-1.5">
            <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div className="flex min-w-0 items-center gap-1 rounded-md border bg-muted/80 p-2.5">
                <code className="min-w-0 flex-1 truncate font-mono text-xs leading-relaxed text-foreground">
                    {value}
                </code>
                <CopyButton value={value} className="shrink-0" />
            </div>
        </div>
    );
}

export function ConnectionInfoDialog({
    server,
    sshUser,
    hasSshKey,
    databaseUser,
    teamPath,
    onOpenChange,
}: ConnectionInfoDialogProps) {
    const [revealed, setRevealed] = useState(false);
    const [password, setPassword] = useState<string | null>(null);
    const [revealing, setRevealing] = useState(false);

    const dbPort = server.database_type === 'postgresql' ? 5432 : 3306;
    const sshPort = server.ssh_port;
    const ip = server.ip_address ?? '';

    const handleReveal = async () => {
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
            setPassword(data.password);
            setRevealed(true);
        } catch {
            // Leave the field masked; the user can retry.
        } finally {
            setRevealing(false);
        }
    };

    const tunnelCommand = `ssh -N -L ${dbPort}:127.0.0.1:${dbPort} ${sshUser}@${ip} -p ${sshPort}`;

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Connect to {databaseUser.username}
                    </DialogTitle>
                    <DialogDescription>
                        Connect from TablePlus, MySQL Workbench, DBeaver, or any
                        client that supports connecting over SSH.
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[60vh] space-y-4 overflow-y-auto pr-1">
                    {!hasSshKey && (
                        <div className="space-y-2 rounded-md border border-yellow-200 bg-yellow-50 p-3 dark:border-yellow-900 dark:bg-yellow-950">
                            <p className="text-sm text-yellow-800 dark:text-yellow-200">
                                You need to add and sync an SSH key to this
                                server before you can connect.
                            </p>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={teamPath('/ssh-keys')}>
                                    <KeyIcon className="mr-2 h-4 w-4" />
                                    Add SSH key
                                </Link>
                            </Button>
                        </div>
                    )}

                    <div>
                        <div className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            SSH connection
                        </div>
                        <div className="space-y-3">
                            <DetailField label="SSH Host" value={ip} />
                            <div className="grid grid-cols-2 gap-3">
                                <DetailField
                                    label="SSH Port"
                                    value={String(sshPort)}
                                />
                                <DetailField label="SSH User" value={sshUser} />
                            </div>
                        </div>
                    </div>

                    <div>
                        <div className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Database
                        </div>
                        <div className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <DetailField label="Host" value="127.0.0.1" />
                                <DetailField
                                    label="Port"
                                    value={String(dbPort)}
                                />
                            </div>
                            <DetailField
                                label={
                                    databaseUser.databases.length > 1
                                        ? 'Databases'
                                        : 'Database'
                                }
                                value={databaseUser.databases.join(', ') || '—'}
                            />
                            <DetailField
                                label="Username"
                                value={databaseUser.username}
                            />

                            <div className="space-y-1.5">
                                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Password
                                </div>
                                <div className="flex min-w-0 items-center gap-1 rounded-md border bg-muted/80 p-2.5">
                                    <code className="min-w-0 flex-1 truncate font-mono text-xs leading-relaxed text-foreground">
                                        {revealed && password !== null
                                            ? password
                                            : '••••••••••••'}
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
                                            <Loader2Icon className="h-4 w-4 animate-spin" />
                                        ) : revealed ? (
                                            <EyeOffIcon className="h-4 w-4" />
                                        ) : (
                                            <EyeIcon className="h-4 w-4" />
                                        )}
                                    </Button>
                                    {revealed && password !== null && (
                                        <CopyButton
                                            value={password}
                                            className="shrink-0"
                                        />
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    <DetailField
                        label="Quick tunnel command"
                        value={tunnelCommand}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}

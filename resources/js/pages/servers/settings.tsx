import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { destroy as destroyServer } from '@/actions/App/Http/Controllers/ServerController';
import { destroy as resetHostKey } from '@/actions/App/Http/Controllers/ServerHostKeyController';
import { store as transferServer } from '@/actions/App/Http/Controllers/ServerTransferController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import { SettingsSection } from '@/components/settings-section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { usePermission } from '@/hooks/use-permission';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type PlanLock, type SharedData } from '@/types';
import { type Server } from '@/types/server';

interface Props {
    server: { data: Server } | Server;
    ssh_command: string | null;
    has_ssh_key: boolean;
    host_key: { type: string; fingerprint: string } | null;
    /** Teams this server can move to. Null when the user can't transfer it. */
    transfer_teams:
        | {
              slug: string;
              name: string;
              /** Cloud accounts the server could be linked to in that team. */
              provider_accounts: { ulid: string; name: string }[];
              /** Set when that team's plan has no room for another server. */
              server_limit: PlanLock | null;
          }[]
        | null;
}

export default function ServerSettings({
    server: serverProp,
    ssh_command,
    has_ssh_key,
    host_key,
    transfer_teams,
}: Props) {
    const { currentTeam, errors } = usePage<SharedData>().props;
    const teamPath = useTeamPath();
    const can = usePermission();
    const server =
        (serverProp as { data?: Server })?.data ?? (serverProp as Server);
    const isCustom = server.provider === 'custom';
    const teamSlug = currentTeam?.slug ?? '';

    const [removeDialogOpen, setRemoveDialogOpen] = useState(false);
    const [isRemoving, setIsRemoving] = useState(false);
    const [resetHostKeyDialogOpen, setResetHostKeyDialogOpen] = useState(false);
    const [isResettingHostKey, setIsResettingHostKey] = useState(false);
    const [transferTeamSlug, setTransferTeamSlug] = useState('');
    const [transferAccountUlid, setTransferAccountUlid] = useState('');
    const [transferDialogOpen, setTransferDialogOpen] = useState(false);
    const [isTransferring, setIsTransferring] = useState(false);
    const transferTeam = transfer_teams?.find(
        (team) => team.slug === transferTeamSlug,
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Settings', href: teamPath(`/servers/${server.id}/settings`) },
    ];

    const handleRemove = () => {
        setIsRemoving(true);
        router.delete(
            destroyServer.url({ team: teamSlug, server: server.id }),
            {
                onFinish: () => {
                    setIsRemoving(false);
                    setRemoveDialogOpen(false);
                },
            },
        );
    };

    const handleTransfer = () => {
        setIsTransferring(true);
        router.post(
            transferServer.url({ team: teamSlug, server: server.id }),
            {
                team: transferTeamSlug,
                provider_account: isCustom ? null : transferAccountUlid,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsTransferring(false);
                    setTransferDialogOpen(false);
                },
            },
        );
    };

    const handleResetHostKey = () => {
        setIsResettingHostKey(true);
        router.delete(resetHostKey.url({ team: teamSlug, server: server.id }), {
            preserveScroll: true,
            onFinish: () => {
                setIsResettingHostKey(false);
                setResetHostKeyDialogOpen(false);
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(teamSlug, server)}
        >
            <Head title={`Settings - ${server.name}`} />

            <SettingsLayout
                title="Settings"
                description={`SSH access, identity and lifecycle for ${server.name}.`}
            >
                <SettingsSection
                    title="SSH access"
                    description="Connect to this server from your own terminal."
                >
                    {has_ssh_key && ssh_command ? (
                        <div className="flex items-center gap-2 rounded-md border bg-muted/40 py-1 pr-1 pl-3">
                            <code className="flex-1 truncate font-mono text-sm">
                                {ssh_command}
                            </code>
                            <CopyButton value={ssh_command} />
                        </div>
                    ) : !server.ip_address ? (
                        <p className="text-sm text-muted-foreground">
                            This server doesn&apos;t have an IP address yet. It
                            may still be provisioning.
                        </p>
                    ) : !can('access_servers') ? (
                        <p className="text-sm text-muted-foreground">
                            Your role doesn&apos;t include SSH access to this
                            team&apos;s servers.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-muted-foreground">
                                Add an SSH key and sync it to this server to get
                                a connect command here.
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                className="shrink-0"
                                asChild
                            >
                                <Link href={teamPath('/ssh-keys')}>
                                    Add SSH key
                                </Link>
                            </Button>
                        </div>
                    )}
                </SettingsSection>

                <SettingsSection
                    title="Server identity"
                    description="The key this server proved its identity with the first time we connected. Every later connection must match it."
                >
                    {host_key ? (
                        <div className="flex flex-col gap-6">
                            <dl className="divide-y rounded-md border text-sm">
                                <div className="grid gap-1 px-4 py-3 sm:grid-cols-[7rem_1fr] sm:items-center">
                                    <dt className="text-muted-foreground">
                                        Key type
                                    </dt>
                                    <dd className="font-mono">
                                        {host_key.type}
                                    </dd>
                                </div>
                                <div className="grid gap-1 px-4 py-2 sm:grid-cols-[7rem_1fr] sm:items-center">
                                    <dt className="text-muted-foreground">
                                        Fingerprint
                                    </dt>
                                    <dd className="flex items-center gap-2">
                                        <span className="flex-1 font-mono break-all">
                                            {host_key.fingerprint}
                                        </span>
                                        <CopyButton
                                            value={host_key.fingerprint}
                                        />
                                    </dd>
                                </div>
                            </dl>
                            {can('manage_servers') && (
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="text-sm text-muted-foreground">
                                        Rebuilt or reinstalled this server?
                                        Reset so we record its new identity.
                                    </p>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="shrink-0"
                                        onClick={() =>
                                            setResetHostKeyDialogOpen(true)
                                        }
                                    >
                                        Reset identity
                                    </Button>
                                </div>
                            )}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Not recorded yet. We&apos;ll record it the next time
                            we connect to this server.
                        </p>
                    )}
                </SettingsSection>

                {transfer_teams && (
                    <SettingsSection
                        title="Transfer server"
                        description="Move this server, with its sites, databases and everything else on it, to another team."
                    >
                        {transfer_teams.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                You don&apos;t manage servers in any other team.
                            </p>
                        ) : (
                            <div className="flex flex-col gap-4">
                                <ul className="list-disc space-y-1 pl-4 text-sm text-muted-foreground">
                                    <li>
                                        Members of this team lose access, and
                                        their SSH keys are removed unless they
                                        have server access in the new team.
                                    </li>
                                    {!isCustom && (
                                        <li>
                                            The new team needs its own{' '}
                                            {server.provider_label} account for
                                            the cloud account this server lives
                                            in. This team&apos;s API key stays
                                            here.
                                        </li>
                                    )}
                                    <li>
                                        Backup schedules and backup history are
                                        removed. Backup files already uploaded
                                        stay in this team&apos;s storage.
                                    </li>
                                </ul>
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <Select
                                        value={transferTeamSlug}
                                        onValueChange={(slug) => {
                                            setTransferTeamSlug(slug);
                                            setTransferAccountUlid('');
                                        }}
                                    >
                                        <SelectTrigger
                                            className="sm:w-64"
                                            aria-label="Team to transfer to"
                                        >
                                            <SelectValue placeholder="Choose a team" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {transfer_teams.map((team) => (
                                                <SelectItem
                                                    key={team.slug}
                                                    value={team.slug}
                                                    disabled={
                                                        team.server_limit !==
                                                        null
                                                    }
                                                >
                                                    {team.name}
                                                    {team.server_limit && (
                                                        <>
                                                            <span className="text-xs text-muted-foreground">
                                                                No room on its
                                                                plan
                                                            </span>
                                                            <Badge variant="secondary">
                                                                {
                                                                    team
                                                                        .server_limit
                                                                        .plan
                                                                }
                                                            </Badge>
                                                        </>
                                                    )}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {!isCustom &&
                                        transferTeam &&
                                        transferTeam.provider_accounts.length >
                                            0 && (
                                            <Select
                                                value={transferAccountUlid}
                                                onValueChange={
                                                    setTransferAccountUlid
                                                }
                                            >
                                                <SelectTrigger
                                                    className="sm:w-64"
                                                    aria-label={`${server.provider_label} account in the new team`}
                                                >
                                                    <SelectValue
                                                        placeholder={`Choose a ${server.provider_label} account`}
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {transferTeam.provider_accounts.map(
                                                        (account) => (
                                                            <SelectItem
                                                                key={
                                                                    account.ulid
                                                                }
                                                                value={
                                                                    account.ulid
                                                                }
                                                            >
                                                                {account.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        )}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="shrink-0"
                                        disabled={
                                            !transferTeam ||
                                            (!isCustom && !transferAccountUlid)
                                        }
                                        onClick={() =>
                                            setTransferDialogOpen(true)
                                        }
                                    >
                                        Transfer server
                                    </Button>
                                </div>
                                {!isCustom &&
                                    transferTeam?.provider_accounts.length ===
                                        0 && (
                                        <p className="text-sm text-muted-foreground">
                                            {transferTeam.name} has no{' '}
                                            {server.provider_label} account. Add
                                            the account this server was created
                                            with to that team first.
                                        </p>
                                    )}
                                {(errors?.team ?? errors?.provider_account) && (
                                    <p className="text-sm text-destructive">
                                        {errors.team ?? errors.provider_account}
                                    </p>
                                )}
                            </div>
                        )}
                    </SettingsSection>
                )}

                {can('manage_servers') &&
                    (isCustom ? (
                        <SettingsSection
                            title="Disconnect server"
                            description="Remove this server from Flitops. The machine and everything running on it stays in place."
                            variant="danger"
                        >
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <ul className="list-disc space-y-1 pl-4 text-sm text-muted-foreground">
                                    <li>
                                        Flitops&apos; SSH key is removed from
                                        the server, so we can no longer log in.
                                    </li>
                                    <li>
                                        Deploy webhooks, GitHub deploy keys and
                                        free flitops.xyz domains are removed.
                                    </li>
                                    <li>
                                        Your sites, databases and other SSH keys
                                        stay on the server.
                                    </li>
                                </ul>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    className="shrink-0"
                                    onClick={() => setRemoveDialogOpen(true)}
                                >
                                    Disconnect server
                                </Button>
                            </div>
                        </SettingsSection>
                    ) : (
                        <SettingsSection
                            title="Destroy server"
                            description={`Delete this server at ${server.provider_label}, along with all its sites, databases and data.`}
                            variant="danger"
                        >
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-sm text-muted-foreground">
                                    This can&apos;t be undone.
                                </p>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    className="shrink-0"
                                    onClick={() => setRemoveDialogOpen(true)}
                                >
                                    Destroy server
                                </Button>
                            </div>
                        </SettingsSection>
                    ))}

                <ConfirmDialog
                    open={transferDialogOpen}
                    onOpenChange={setTransferDialogOpen}
                    title={`Transfer ${server.name} to ${transferTeam?.name}?`}
                    description={`The server and everything on it moves to ${transferTeam?.name} straight away. Its backup schedules and backup history are removed, and people without server access in ${transferTeam?.name} lose their SSH keys on it.`}
                    confirmLabel="Transfer server"
                    loading={isTransferring}
                    onConfirm={handleTransfer}
                />

                <ConfirmDialog
                    open={resetHostKeyDialogOpen}
                    onOpenChange={setResetHostKeyDialogOpen}
                    title="Reset this server's identity?"
                    description="Only do this if you rebuilt or reinstalled this server. If you didn't, a changed identity can mean someone is intercepting the connection, so contact your hosting provider before resetting. After the reset, the next successful connection records the server's new identity."
                    confirmLabel="Reset identity"
                    variant="destructive"
                    loading={isResettingHostKey}
                    onConfirm={handleResetHostKey}
                />

                {isCustom ? (
                    <ConfirmDialog
                        open={removeDialogOpen}
                        onOpenChange={setRemoveDialogOpen}
                        title={`Disconnect ${server.name}?`}
                        description="Flitops removes its SSH key from the server and forgets it. Your sites keep running, but git pull on the server stops working and sites on a free flitops.xyz address go offline at that address. If the server can't be reached, its SSH key stays and you'll need to remove the flitops-worker line from authorized_keys yourself."
                        confirmLabel="Disconnect server"
                        variant="destructive"
                        loading={isRemoving}
                        onConfirm={handleRemove}
                    />
                ) : (
                    <ConfirmDialog
                        open={removeDialogOpen}
                        onOpenChange={setRemoveDialogOpen}
                        title={`Destroy ${server.name}?`}
                        description={`This deletes the server at ${server.provider_label} along with all its sites, databases and data. This can't be undone.`}
                        confirmLabel="Destroy server"
                        variant="destructive"
                        loading={isRemoving}
                        onConfirm={handleRemove}
                    />
                )}
            </SettingsLayout>
        </AppLayout>
    );
}

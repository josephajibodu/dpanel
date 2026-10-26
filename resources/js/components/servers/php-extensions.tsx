import { router } from '@inertiajs/react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { CardDescription, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatGroup } from '@/components/ui/stat-group';
import { AutoStatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTeamPath } from '@/hooks/use-team-path';

export interface PhpExtensionOption {
    value: string;
    label: string;
    description: string;
}

export interface PhpExtensionsVersion {
    version: string;
    extensions: string[];
    extensions_sync_status: string | null;
    extensions_sync_error: string | null;
    extensions_pending: {
        extension: string;
        action: 'install' | 'remove';
    } | null;
}

interface Props {
    serverId: number;
    serverIsReady: boolean;
    canManagePhp: boolean;
    versions: PhpExtensionsVersion[];
    defaultVersion: string;
    availableExtensions: PhpExtensionOption[];
    includedExtensions: string[];
}

export function PhpExtensions({
    serverId,
    serverIsReady,
    canManagePhp,
    versions,
    defaultVersion,
    availableExtensions,
    includedExtensions,
}: Props) {
    const teamPath = useTeamPath();
    const [selectedVersion, setSelectedVersion] = useState(defaultVersion);
    const [extensionToRemove, setExtensionToRemove] =
        useState<PhpExtensionOption | null>(null);
    const [submitting, setSubmitting] = useState<string | null>(null);

    const current =
        versions.find((v) => v.version === selectedVersion) ?? versions[0];

    if (!current) {
        return null;
    }

    const isBusy =
        current.extensions_sync_status === 'pending' ||
        current.extensions_sync_status === 'syncing';

    const changeExtension = (
        extension: PhpExtensionOption,
        install: boolean,
    ) => {
        setSubmitting(extension.value);
        const payload = {
            version: current.version,
            extension: extension.value,
        };
        const options = {
            preserveScroll: true,
            onFinish: () => {
                setSubmitting(null);
                setExtensionToRemove(null);
            },
        };
        const url = teamPath(`/servers/${serverId}/php/extensions`);

        if (install) {
            router.post(url, payload, options);
        } else {
            router.delete(url, { data: payload, ...options });
        }
    };

    const statusFor = (extension: PhpExtensionOption) => {
        const pending = current.extensions_pending;
        if (isBusy && pending?.extension === extension.value) {
            return pending.action === 'install' ? (
                <AutoStatusBadge status="installing" label="Installing" />
            ) : (
                <AutoStatusBadge status="deleting" label="Removing" />
            );
        }

        if (current.extensions.includes(extension.value)) {
            return <AutoStatusBadge status="installed" label="Installed" />;
        }

        return <span className="text-sm text-muted-foreground">—</span>;
    };

    return (
        <StatGroup>
            <div className="rounded-lg border bg-card shadow-md shadow-black/5 dark:shadow-black/20">
                <div className="flex flex-col gap-3 border-b px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle>Extensions</CardTitle>
                        <CardDescription className="mt-1">
                            Add or remove optional PHP extensions. PHP is
                            reloaded afterwards, so sites keep running.
                            {current.extensions_sync_status === 'failed' &&
                                current.extensions_sync_error && (
                                    <span className="mt-1 block text-destructive">
                                        The last change failed:{' '}
                                        {current.extensions_sync_error}
                                    </span>
                                )}
                        </CardDescription>
                    </div>
                    {versions.length > 1 && (
                        <Select
                            value={current.version}
                            onValueChange={setSelectedVersion}
                        >
                            <SelectTrigger
                                className="w-full sm:w-[140px]"
                                aria-label="PHP version"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {versions.map((v) => (
                                    <SelectItem
                                        key={v.version}
                                        value={v.version}
                                    >
                                        PHP {v.version}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </div>
                <div className="flex flex-col gap-4 p-6">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Extension</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-[120px]" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {availableExtensions.map((extension) => {
                                const isInstalled = current.extensions.includes(
                                    extension.value,
                                );

                                return (
                                    <TableRow key={extension.value}>
                                        <TableCell>
                                            <p className="font-medium">
                                                {extension.label}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {extension.description}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            {statusFor(extension)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {canManagePhp && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={
                                                        !serverIsReady ||
                                                        isBusy ||
                                                        submitting !== null
                                                    }
                                                    onClick={() =>
                                                        isInstalled
                                                            ? setExtensionToRemove(
                                                                  extension,
                                                              )
                                                            : changeExtension(
                                                                  extension,
                                                                  true,
                                                              )
                                                    }
                                                >
                                                    {isInstalled
                                                        ? 'Remove'
                                                        : 'Install'}
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                    <p className="text-sm text-muted-foreground">
                        Always installed:{' '}
                        <span className="font-mono">
                            {includedExtensions.join(', ')}
                        </span>
                    </p>
                </div>
            </div>

            <ConfirmDialog
                open={extensionToRemove !== null}
                onOpenChange={(open) => !open && setExtensionToRemove(null)}
                title={`Remove ${extensionToRemove?.label ?? ''}?`}
                description={`Any site on PHP ${current.version} that uses ${extensionToRemove?.label ?? 'this extension'} will stop working until it is installed again.`}
                confirmLabel="Remove"
                variant="destructive"
                loading={submitting !== null}
                onConfirm={() =>
                    extensionToRemove &&
                    changeExtension(extensionToRemove, false)
                }
            />
        </StatGroup>
    );
}

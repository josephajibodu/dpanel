import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { type StorageProvider } from '@/types/storage-provider';

interface ImportDumpDialogProps {
    databaseName: string;
    importUrl: string;
    storageProviders: StorageProvider[];
    defaultStorageProviderId: number | null;
    onClose: () => void;
}

/**
 * Uploads a .sql or .sql.gz dump and loads it into the database, after the
 * user types the database name to confirm its data will be replaced.
 */
export function ImportDumpDialog({
    databaseName,
    importUrl,
    storageProviders,
    defaultStorageProviderId,
    onClose,
}: ImportDumpDialogProps) {
    const [confirmText, setConfirmText] = useState('');
    const form = useForm<{
        storage_provider_id: number | '';
        dump: File | null;
    }>({
        storage_provider_id:
            defaultStorageProviderId ?? storageProviders[0]?.id ?? '',
        dump: null,
    });

    const submit = () => {
        form.post(importUrl, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) {
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Import a dump</DialogTitle>
                    <DialogDescription>
                        Load a <span className="font-mono">.sql</span> or{' '}
                        <span className="font-mono">.sql.gz</span> dump into{' '}
                        <span className="font-mono">{databaseName}</span>. This
                        replaces the data in the tables the dump contains.
                    </DialogDescription>
                </DialogHeader>

                {storageProviders.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Connect a storage provider first. The dump is kept there
                        so the server can download it.
                    </p>
                ) : (
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="import-dump">Dump file</Label>
                            <Input
                                id="import-dump"
                                type="file"
                                accept=".sql,.gz"
                                onChange={(e) =>
                                    form.setData(
                                        'dump',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            <InputError message={form.errors.dump} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="import-storage-provider">
                                Keep the dump in
                            </Label>
                            <Select
                                value={String(form.data.storage_provider_id)}
                                onValueChange={(value) =>
                                    form.setData(
                                        'storage_provider_id',
                                        Number(value),
                                    )
                                }
                            >
                                <SelectTrigger id="import-storage-provider">
                                    <SelectValue placeholder="Select storage" />
                                </SelectTrigger>
                                <SelectContent>
                                    {storageProviders.map((provider) => (
                                        <SelectItem
                                            key={provider.id}
                                            value={String(provider.id)}
                                        >
                                            {provider.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={form.errors.storage_provider_id}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="import-confirm">
                                Type the database name to confirm
                            </Label>
                            <Input
                                id="import-confirm"
                                value={confirmText}
                                onChange={(e) => setConfirmText(e.target.value)}
                                placeholder={databaseName}
                                className="font-mono"
                                autoComplete="off"
                            />
                        </div>

                        {form.progress && (
                            <progress
                                className="w-full"
                                value={form.progress.percentage}
                                max={100}
                            />
                        )}
                    </div>
                )}

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={
                            confirmText !== databaseName ||
                            !form.data.dump ||
                            form.data.storage_provider_id === '' ||
                            form.processing
                        }
                        onClick={submit}
                    >
                        {form.processing ? 'Uploading…' : 'Import'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

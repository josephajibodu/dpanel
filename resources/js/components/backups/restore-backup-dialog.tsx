import { router } from '@inertiajs/react';
import { useState } from 'react';

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
import { type Backup } from '@/types/backup';

interface RestoreBackupDialogProps {
    backup: Backup;
    databaseName: string;
    backupsUrl: string;
    onClose: () => void;
}

/**
 * Asks the user to type the database name before a backup overwrites it.
 */
export function RestoreBackupDialog({
    backup,
    databaseName,
    backupsUrl,
    onClose,
}: RestoreBackupDialogProps) {
    const [confirmText, setConfirmText] = useState('');

    const confirmRestore = () => {
        router.post(
            `${backupsUrl}/${backup.id}/restore`,
            {},
            {
                preserveScroll: true,
                onFinish: onClose,
            },
        );
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Restore backup</DialogTitle>
                    <DialogDescription>
                        This overwrites the live data in{' '}
                        <span className="font-mono">{databaseName}</span> with
                        this backup. Type the database name to confirm.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2">
                    <Label htmlFor="restore-confirm">Database name</Label>
                    <Input
                        id="restore-confirm"
                        value={confirmText}
                        onChange={(e) => setConfirmText(e.target.value)}
                        placeholder={databaseName}
                        className="font-mono"
                        autoComplete="off"
                    />
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={confirmText !== databaseName}
                        onClick={confirmRestore}
                    >
                        Restore
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

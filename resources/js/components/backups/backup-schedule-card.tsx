import { Transition } from '@headlessui/react';
import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { CardDescription, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatGroup } from '@/components/ui/stat-group';
import { Switch } from '@/components/ui/switch';
import { type BackupSchedule } from '@/types/backup';
import { type StorageProvider } from '@/types/storage-provider';

interface Props {
    schedule: BackupSchedule | null;
    storageProviders: StorageProvider[];
    /** Team-prefixed URL the schedule is PUT to. */
    scheduleUrl: string;
    description: string;
}

export function BackupScheduleCard({
    schedule,
    storageProviders,
    scheduleUrl,
    description,
}: Props) {
    const scheduleForm = useForm({
        storage_provider_id: schedule?.storage_provider_id ?? '',
        frequency: schedule?.frequency ?? 'daily',
        retention_count: schedule?.retention_count ?? 7,
        enabled: schedule?.enabled ?? false,
    });

    const handleSaveSchedule = (e: React.FormEvent) => {
        e.preventDefault();
        scheduleForm.put(scheduleUrl, {
            preserveScroll: true,
        });
    };

    return (
        <StatGroup>
            <div className="rounded-lg border bg-card shadow-md shadow-black/5 dark:shadow-black/20">
                <div className="border-b px-6 py-4">
                    <CardTitle>Schedule</CardTitle>
                    <CardDescription className="mt-1">
                        {description}
                    </CardDescription>
                </div>
                <div className="p-6">
                    {storageProviders.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Connect a storage provider first, then come back to
                            set up a schedule.
                        </p>
                    ) : (
                        <form
                            onSubmit={handleSaveSchedule}
                            className="space-y-5"
                        >
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="space-y-2">
                                    <Label htmlFor="storage_provider_id">
                                        Storage provider
                                    </Label>
                                    <Select
                                        value={String(
                                            scheduleForm.data
                                                .storage_provider_id,
                                        )}
                                        onValueChange={(value) =>
                                            scheduleForm.setData(
                                                'storage_provider_id',
                                                Number(value),
                                            )
                                        }
                                    >
                                        <SelectTrigger id="storage_provider_id">
                                            <SelectValue placeholder="Select storage" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {storageProviders.map(
                                                (provider) => (
                                                    <SelectItem
                                                        key={provider.id}
                                                        value={String(
                                                            provider.id,
                                                        )}
                                                    >
                                                        {provider.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="frequency">Frequency</Label>
                                    <Select
                                        value={scheduleForm.data.frequency}
                                        onValueChange={(value) =>
                                            scheduleForm.setData(
                                                'frequency',
                                                value as
                                                    | 'hourly'
                                                    | 'daily'
                                                    | 'weekly',
                                            )
                                        }
                                    >
                                        <SelectTrigger id="frequency">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="hourly">
                                                Hourly
                                            </SelectItem>
                                            <SelectItem value="daily">
                                                Daily
                                            </SelectItem>
                                            <SelectItem value="weekly">
                                                Weekly
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="retention_count">
                                        Keep last
                                    </Label>
                                    <Input
                                        id="retention_count"
                                        type="number"
                                        min={1}
                                        max={365}
                                        value={
                                            scheduleForm.data.retention_count
                                        }
                                        onChange={(e) =>
                                            scheduleForm.setData(
                                                'retention_count',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="enabled">Enabled</Label>
                                    <div className="flex h-9 items-center">
                                        <Switch
                                            id="enabled"
                                            checked={scheduleForm.data.enabled}
                                            onCheckedChange={(checked) =>
                                                scheduleForm.setData(
                                                    'enabled',
                                                    checked,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3">
                                <Transition
                                    show={scheduleForm.recentlySuccessful}
                                    enter="transition ease-in-out"
                                    enterFrom="opacity-0"
                                    leave="transition ease-in-out"
                                    leaveTo="opacity-0"
                                >
                                    <p className="text-sm text-muted-foreground">
                                        Saved
                                    </p>
                                </Transition>
                                <Button
                                    type="submit"
                                    disabled={
                                        scheduleForm.processing ||
                                        !scheduleForm.data.storage_provider_id
                                    }
                                >
                                    {scheduleForm.processing
                                        ? 'Saving…'
                                        : 'Save'}
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            </div>
        </StatGroup>
    );
}

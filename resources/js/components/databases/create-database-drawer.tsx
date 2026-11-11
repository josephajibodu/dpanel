import { Link, usePage } from '@inertiajs/react';
import { PlusIcon } from '@phosphor-icons/react';

import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';

interface CreateDatabaseDrawerProps {
    serverId: number;
}

export function CreateDatabaseDrawer({ serverId }: CreateDatabaseDrawerProps) {
    const { currentTeam } = usePage<SharedData>().props;
    const slug = currentTeam?.slug ?? '';

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={`/${slug}/servers/${serverId}/databases`}>
                <PlusIcon className="h-4 w-4" />
                New database
            </Link>
        </Button>
    );
}

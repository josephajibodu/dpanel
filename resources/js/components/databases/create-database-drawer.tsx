import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';

interface CreateDatabaseDrawerProps {
    serverId: number;
}

export function CreateDatabaseDrawer({ serverId }: CreateDatabaseDrawerProps) {
    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={`/servers/${serverId}/databases`}>
                <PlusIcon className="mr-2 h-4 w-4" />
                New database
            </Link>
        </Button>
    );
}

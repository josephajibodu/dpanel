import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';

interface CreateDatabaseDrawerProps {
    serverId: number;
}

export function CreateDatabaseDrawer({ serverId }: CreateDatabaseDrawerProps) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="outline" size="sm">
                    <PlusIcon className="mr-2 h-4 w-4" />
                    New database
                </Button>
            </SheetTrigger>
            <SheetContent side="right">
                <SheetHeader>
                    <SheetTitle>Create database</SheetTitle>
                </SheetHeader>
                <div className="flex flex-1 flex-col gap-4 p-4 pt-0 text-sm">
                    <p className="text-muted-foreground">
                        Database creation in the drawer will live here. For
                        now, this opens the full database creation flow.
                    </p>
                </div>
                <SheetFooter>
                    <Button asChild>
                        <Link href={`/servers/${serverId}/databases/create`}>
                            Open full create form
                        </Link>
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}


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

interface CreateSiteDrawerProps {
    serverId: number;
}

export function CreateSiteDrawer({ serverId }: CreateSiteDrawerProps) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="outline" size="sm">
                    <PlusIcon className="mr-2 h-4 w-4" />
                    New site
                </Button>
            </SheetTrigger>
            <SheetContent side="right">
                <SheetHeader>
                    <SheetTitle>Create site</SheetTitle>
                </SheetHeader>
                <div className="flex flex-1 flex-col gap-4 p-4 pt-0 text-sm">
                    <p className="text-muted-foreground">
                        Site creation in the drawer will live here. For now,
                        use the full site creation flow.
                    </p>
                </div>
                <SheetFooter>
                    <Button asChild>
                        <Link href={`/servers/${serverId}/sites/create`}>
                            Open full create form
                        </Link>
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}


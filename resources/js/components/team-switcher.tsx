import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus, Users } from 'lucide-react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type SharedData, type Team } from '@/types';

export function TeamSwitcher() {
    const { currentTeam, teams } = usePage<SharedData>().props;

    if (!currentTeam) {
        return null;
    }

    const switchTeam = (team: Pick<Team, 'id' | 'name' | 'personal_team'>) => {
        if (team.id === currentTeam.id) {
            return;
        }
        router.put('/user/current-team', { team_id: team.id });
    };

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="group text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent"
                        >
                            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-lg">
                                <Users className="size-4" />
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-semibold">
                                    {currentTeam.name}
                                </span>
                                <span className="text-muted-foreground truncate text-xs">
                                    {currentTeam.personal_team ? 'Personal' : 'Team'}
                                </span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side="bottom"
                        sideOffset={4}
                    >
                        <DropdownMenuLabel className="text-muted-foreground text-xs">
                            Teams
                        </DropdownMenuLabel>
                        {teams.map((team) => (
                            <DropdownMenuItem
                                key={team.id}
                                onClick={() => switchTeam(team)}
                                className="cursor-pointer gap-2 p-2"
                            >
                                <div className="flex size-6 items-center justify-center rounded-sm border">
                                    <Users className="size-4 shrink-0" />
                                </div>
                                {team.name}
                                {team.id === currentTeam.id && (
                                    <Check className="ml-auto size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            className="cursor-pointer gap-2 p-2"
                            onClick={() => router.visit('/teams/create')}
                        >
                            <div className="bg-background flex size-6 items-center justify-center rounded-md border">
                                <Plus className="size-4" />
                            </div>
                            <div className="text-muted-foreground font-medium">
                                Create team
                            </div>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

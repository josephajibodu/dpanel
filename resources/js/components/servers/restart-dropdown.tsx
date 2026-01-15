import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { restart } from '@/actions/App/Http/Controllers/ServerController';
import { type Server } from '@/types/server';
import { router } from '@inertiajs/react';
import { DatabaseIcon, LayersIcon, RefreshCwIcon, ServerIcon } from 'lucide-react';
import { useState } from 'react';

interface RestartDropdownProps {
    server: Server;
}

type ServiceType = 'nginx' | 'php' | 'mysql' | 'postgresql' | 'redis' | 'supervisor';

interface ServiceOption {
    value: ServiceType;
    label: string;
    icon: React.ReactNode;
    available: (server: Server) => boolean;
}

const services: ServiceOption[] = [
    {
        value: 'nginx',
        label: 'Nginx',
        icon: <ServerIcon className="mr-2 h-4 w-4" />,
        available: () => true,
    },
    {
        value: 'php',
        label: 'PHP-FPM',
        icon: <LayersIcon className="mr-2 h-4 w-4" />,
        available: () => true,
    },
    {
        value: 'mysql',
        label: 'MySQL',
        icon: <DatabaseIcon className="mr-2 h-4 w-4" />,
        available: (server) => server.database_type === 'mysql',
    },
    {
        value: 'postgresql',
        label: 'PostgreSQL',
        icon: <DatabaseIcon className="mr-2 h-4 w-4" />,
        available: (server) => server.database_type === 'postgresql',
    },
    {
        value: 'redis',
        label: 'Redis',
        icon: <DatabaseIcon className="mr-2 h-4 w-4" />,
        available: () => true,
    },
    {
        value: 'supervisor',
        label: 'Supervisor (All Workers)',
        icon: <LayersIcon className="mr-2 h-4 w-4" />,
        available: () => true,
    },
];

export function RestartDropdown({ server }: RestartDropdownProps) {
    const [isRestarting, setIsRestarting] = useState<ServiceType | null>(null);

    const handleRestart = (service: ServiceType) => {
        setIsRestarting(service);
        router.post(
            restart.url(server.id),
            { service },
            {
                preserveScroll: true,
                onFinish: () => setIsRestarting(null),
            },
        );
    };

    const availableServices = services.filter((s) => s.available(server));
    const isDisabled = server.status !== 'active';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" disabled={isDisabled || isRestarting !== null}>
                    <RefreshCwIcon className={`mr-2 h-4 w-4 ${isRestarting ? 'animate-spin' : ''}`} />
                    {isRestarting ? 'Restarting...' : 'Restart Service'}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>Select Service to Restart</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {availableServices.map((service) => (
                    <DropdownMenuItem
                        key={service.value}
                        onClick={() => handleRestart(service.value)}
                        disabled={isRestarting !== null}
                    >
                        {service.icon}
                        {service.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

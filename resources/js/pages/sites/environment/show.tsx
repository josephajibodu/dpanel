import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { getSiteSubNavItems } from '@/config/sub-nav-items';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type EnvironmentVariable, type Site } from '@/types/site';
import { Head, useForm } from '@inertiajs/react';
import { Loader2Icon, PlusIcon, XIcon } from 'lucide-react';
import { useState } from 'react';

interface Props {
    server: { data: { id: number; name: string } };
    site: {
        data: Site & {
            server?: { id: number; name: string; ip_address: string };
            environment_variables?: EnvironmentVariable[];
        };
    };
    has_workers: boolean;
}

export default function SiteEnvironmentShow({ server: serverProp, site: siteProp, has_workers = false }: Props) {
    const server = serverProp?.data ?? serverProp;
    const site = siteProp.data;
    const serverId = server?.id ?? site.server?.id;

    const initialVars = site.environment_variables?.length
        ? site.environment_variables
        : [{ key: '', value: '' }];
    const [variables, setVariables] = useState<EnvironmentVariable[]>(initialVars);

    const form = useForm({
        variables,
        clear_config_cache: false,
        restart_queue: false,
    });

    function addVariable() {
        const newVariables = [...variables, { key: '', value: '' }];
        setVariables(newVariables);
        form.setData('variables', newVariables);
    }

    function removeVariable(index: number) {
        const newVariables = variables.filter((_, i) => i !== index);
        const finalVariables = newVariables.length > 0 ? newVariables : [{ key: '', value: '' }];
        setVariables(finalVariables);
        form.setData('variables', finalVariables);
    }

    function updateVariable(index: number, field: 'key' | 'value', value: string) {
        const newVariables = [...variables];
        newVariables[index] = { ...newVariables[index], [field]: value };
        setVariables(newVariables);
        form.setData('variables', newVariables);
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const filteredVariables = variables.filter((v) => v.key.trim() !== '');
        form.setData('variables', filteredVariables);
        form.put(`/servers/${serverId}/sites/${site.id}/environment`);
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server?.name || site.server?.name || 'Server', href: `/servers/${serverId}` },
        { title: site.domain, href: `/servers/${serverId}/sites/${site.id}` },
        { title: 'Environment', href: `/servers/${serverId}/sites/${site.id}/environment` },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getSiteSubNavItems(String(serverId ?? ''), site.id)}
        >
            <Head title={`Environment - ${site.domain}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Environment Variables
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Manage environment variables for your application. These will be synced to the .env file on your server.
                    </p>
                </div>

                <Card>
                    <CardContent className="pt-6">
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="space-y-3">
                                {variables.map((variable, index) => (
                                    <div key={index} className="flex gap-3">
                                        <div className="flex-1">
                                            <Input
                                                placeholder="VARIABLE_NAME"
                                                value={variable.key}
                                                onChange={(e) => updateVariable(index, 'key', e.target.value.toUpperCase())}
                                                className="font-mono"
                                            />
                                        </div>
                                        <div className="flex-1">
                                            <Input
                                                placeholder="value"
                                                value={variable.value}
                                                onChange={(e) => updateVariable(index, 'value', e.target.value)}
                                            />
                                        </div>
                                        <Button type="button" variant="ghost" size="icon" onClick={() => removeVariable(index)}>
                                            <XIcon className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>

                            <Button type="button" variant="outline" size="sm" onClick={addVariable}>
                                <PlusIcon className="mr-2 h-4 w-4" />
                                Add Variable
                            </Button>

                            <div className="flex flex-col gap-2">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={form.data.clear_config_cache}
                                        onCheckedChange={(checked) =>
                                            form.setData('clear_config_cache', !!checked)
                                        }
                                    />
                                    Clear config cache
                                </label>
                                {has_workers && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.restart_queue}
                                            onCheckedChange={(checked) =>
                                                form.setData('restart_queue', !!checked)
                                            }
                                        />
                                        Restart queue workers
                                    </label>
                                )}
                            </div>

                            <Separator />

                            <div className="flex justify-end">
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing && <Loader2Icon className="mr-2 h-4 w-4 animate-spin" />}
                                    Save & Sync
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

import { Head, router, usePage } from '@inertiajs/react';
import {
    DatabaseIcon,
    EyeIcon,
    EyeOffIcon,
    MoreVerticalIcon,
    PencilIcon,
    PlusIcon,
    Trash2Icon,
    UsersIcon,
} from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CardDescription, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { AutoStatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { useServerDatabasesUpdates } from '@/hooks/use-server-databases-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { DatabaseUser, Server, ServerDatabase } from '@/types/server';

interface Props {
    server: { data: Server } | Server;
    serverIsReady: boolean;
    databases: { data: ServerDatabase[] };
    databaseUsers: { data: DatabaseUser[] };
}

export default function ServerDatabasesIndex({
    server: serverProp,
    serverIsReady,
    databases,
    databaseUsers,
}: Props) {
    const server =
        serverProp && 'data' in serverProp ? serverProp.data : serverProp;
    const dbList = databases?.data ?? [];
    const userList = databaseUsers?.data ?? [];
    const pageProps = usePage<SharedData>().props;
    const { errors } = pageProps as { errors?: Record<string, string> };
    const teamPath = useTeamPath();

    useServerDatabasesUpdates(server.id);

    const [createDbOpen, setCreateDbOpen] = useState(false);
    const [createUserOpen, setCreateUserOpen] = useState(false);
    const [editUserOpen, setEditUserOpen] = useState(false);
    const [userToEdit, setUserToEdit] = useState<DatabaseUser | null>(null);
    const [deleteDbOpen, setDeleteDbOpen] = useState(false);
    const [deleteUserOpen, setDeleteUserOpen] = useState(false);
    const [dbToDelete, setDbToDelete] = useState<ServerDatabase | null>(null);
    const [userToDelete, setUserToDelete] = useState<DatabaseUser | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [deletingDbIds, setDeletingDbIds] = useState<number[]>([]);
    const [deletingUserIds, setDeletingUserIds] = useState<number[]>([]);

    const [dbForm, setDbForm] = useState({
        name: '',
        charset: '',
        collation: '',
        db_user: '',
        db_password: '',
    });
    const [showDbPassword, setShowDbPassword] = useState(false);
    const [userForm, setUserForm] = useState({
        username: '',
        password: '',
        databases: [] as string[],
        permission: 'readwrite',
        host: 'localhost',
    });
    const [editUserForm, setEditUserForm] = useState({
        password: '',
        databases: [] as string[],
        permission: 'readwrite',
        host: 'localhost',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        {
            title: 'Databases',
            href: teamPath(`/servers/${server.id}/databases`),
        },
    ];

    const isServerReady = serverIsReady;

    const generatePassword = () => {
        const chars =
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        let password = '';
        const array = new Uint32Array(24);
        crypto.getRandomValues(array);
        for (let i = 0; i < 24; i++) {
            password += chars[array[i] % chars.length];
        }
        return password;
    };

    const handleCreateDatabase = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id) return;
        setIsSubmitting(true);
        router.post(
            teamPath(`/servers/${server.id}/databases`),
            {
                name: dbForm.name,
                charset: dbForm.charset || undefined,
                collation: dbForm.collation || undefined,
                db_user: dbForm.db_user || undefined,
                db_password: dbForm.db_password || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCreateDbOpen(false);
                    setDbForm({
                        name: '',
                        charset: '',
                        collation: '',
                        db_user: '',
                        db_password: '',
                    });
                    setShowDbPassword(false);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const handleCreateUser = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id || userForm.databases.length === 0) return;
        setIsSubmitting(true);
        router.post(
            teamPath(`/servers/${server.id}/database-users`),
            {
                username: userForm.username,
                password: userForm.password,
                databases: userForm.databases,
                permission: userForm.permission,
                host: userForm.host || 'localhost',
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCreateUserOpen(false);
                    setUserForm({
                        username: '',
                        password: '',
                        databases: [],
                        permission: 'readwrite',
                        host: 'localhost',
                    });
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const handleUpdateUser = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id || !userToEdit || editUserForm.databases.length === 0)
            return;
        setIsSubmitting(true);
        router.put(
            teamPath(`/servers/${server.id}/database-users/${userToEdit.id}`),
            {
                password: editUserForm.password || undefined,
                databases: editUserForm.databases,
                permission: editUserForm.permission,
                host: editUserForm.host || 'localhost',
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditUserOpen(false);
                    setUserToEdit(null);
                    setEditUserForm({
                        password: '',
                        databases: [],
                        permission: 'readwrite',
                        host: 'localhost',
                    });
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const toggleDbInList = (list: string[], name: string) => {
        if (list.includes(name)) return list.filter((d) => d !== name);
        return [...list, name];
    };

    const openEditUser = (user: DatabaseUser) => {
        setUserToEdit(user);
        setEditUserForm({
            password: '',
            databases: user.databases ?? [],
            permission: user.permission ?? 'readwrite',
            host: user.host ?? 'localhost',
        });
        setEditUserOpen(true);
    };

    const confirmDeleteDb = () => {
        if (!server?.id || !dbToDelete) return;
        const id = dbToDelete.id;
        setDeleteDbOpen(false);
        setDbToDelete(null);
        setDeletingDbIds((prev) => [...prev, id]);
        router.delete(teamPath(`/servers/${server.id}/databases/${id}`), {
            preserveScroll: true,
            onSuccess: () =>
                setDeletingDbIds((prev) => prev.filter((x) => x !== id)),
            onError: () =>
                setDeletingDbIds((prev) => prev.filter((x) => x !== id)),
        });
    };

    const confirmDeleteUser = () => {
        if (!server?.id || !userToDelete) return;
        const id = userToDelete.id;
        setDeleteUserOpen(false);
        setUserToDelete(null);
        setDeletingUserIds((prev) => [...prev, id]);
        router.delete(teamPath(`/servers/${server.id}/database-users/${id}`), {
            preserveScroll: true,
            onSuccess: () =>
                setDeletingUserIds((prev) => prev.filter((x) => x !== id)),
            onError: () =>
                setDeletingUserIds((prev) => prev.filter((x) => x !== id)),
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(
                pageProps.currentTeam?.slug ?? '',
                server.id,
            )}
        >
            <Head title={`Databases - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Databases
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Manage databases and database users on {server.name}
                            .
                        </p>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-1">
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>Databases</CardTitle>
                                <CardDescription>
                                    Databases on this server.
                                </CardDescription>
                            </div>
                            {isServerReady && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setCreateDbOpen(true)}
                                >
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    New database
                                </Button>
                            )}
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Charset</TableHead>
                                    <TableHead>Collation</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-[70px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {dbList.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={5}>
                                            <EmptyState
                                                icon={DatabaseIcon}
                                                title="No databases yet"
                                            />
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    dbList.map((db) => {
                                        const isDeleting =
                                            deletingDbIds.includes(db.id) ||
                                            db.status === 'deleting';
                                        return (
                                            <TableRow key={db.id}>
                                                <TableCell className="font-mono font-medium">
                                                    {db.name}
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">
                                                    {db.charset ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">
                                                    {db.collation ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <AutoStatusBadge
                                                        status={
                                                            isDeleting
                                                                ? 'deleting'
                                                                : db.status
                                                        }
                                                        label={
                                                            isDeleting
                                                                ? 'Deleting...'
                                                                : undefined
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        className="h-8 w-8"
                                                        disabled={isDeleting}
                                                        onClick={() => {
                                                            if (!isDeleting) {
                                                                setDbToDelete(
                                                                    db,
                                                                );
                                                                setDeleteDbOpen(
                                                                    true,
                                                                );
                                                            }
                                                        }}
                                                        aria-label="Delete database"
                                                    >
                                                        <Trash2Icon className="h-4 w-4" />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>Database users</CardTitle>
                                <CardDescription>
                                    Users with access to databases.
                                </CardDescription>
                            </div>
                            {isServerReady && dbList.length > 0 && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setCreateUserOpen(true)}
                                >
                                    <PlusIcon className="mr-2 h-4 w-4" />
                                    New user
                                </Button>
                            )}
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Username</TableHead>
                                    <TableHead>Host</TableHead>
                                    <TableHead>Databases</TableHead>
                                    <TableHead>Permission</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-[100px]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {userList.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6}>
                                            <EmptyState
                                                icon={UsersIcon}
                                                title="No database users yet"
                                            />
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    userList.map((user) => {
                                        const isDeleting =
                                            deletingUserIds.includes(user.id) ||
                                            user.status === 'deleting';
                                        return (
                                            <TableRow key={user.id}>
                                                <TableCell className="font-mono font-medium">
                                                    {user.username}
                                                </TableCell>
                                                <TableCell className="font-mono text-sm text-muted-foreground">
                                                    {user.host ?? 'localhost'}
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">
                                                    {(
                                                        user.databases ?? []
                                                    ).join(', ') || '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            user.permission ===
                                                            'readonly'
                                                                ? 'outline'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {user.permission ===
                                                        'readonly'
                                                            ? 'Read-only'
                                                            : 'Read-write'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <AutoStatusBadge
                                                        status={
                                                            isDeleting
                                                                ? 'deleting'
                                                                : user.status
                                                        }
                                                        label={
                                                            isDeleting
                                                                ? 'Deleting...'
                                                                : undefined
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger
                                                            asChild
                                                        >
                                                            <Button
                                                                variant="outline"
                                                                size="icon"
                                                                className="h-8 w-8"
                                                                disabled={
                                                                    isDeleting
                                                                }
                                                            >
                                                                <MoreVerticalIcon className="h-4 w-4" />
                                                                <span className="sr-only">
                                                                    Actions
                                                                </span>
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    openEditUser(
                                                                        user,
                                                                    )
                                                                }
                                                            >
                                                                <PencilIcon className="mr-2 h-4 w-4" />
                                                                Edit
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                className="text-destructive focus:text-destructive"
                                                                onClick={() => {
                                                                    setUserToDelete(
                                                                        user,
                                                                    );
                                                                    setDeleteUserOpen(
                                                                        true,
                                                                    );
                                                                }}
                                                            >
                                                                <Trash2Icon className="mr-2 h-4 w-4" />
                                                                Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>

            <Sheet open={createDbOpen} onOpenChange={setCreateDbOpen}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>Create database</SheetTitle>
                    </SheetHeader>
                    <form
                        onSubmit={handleCreateDatabase}
                        className="flex flex-1 flex-col gap-4 p-4 pt-4"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="db-name">Name</Label>
                            <Input
                                id="db-name"
                                value={dbForm.name}
                                onChange={(e) =>
                                    setDbForm((p) => ({
                                        ...p,
                                        name: e.target.value,
                                    }))
                                }
                                placeholder="mydb"
                                className="font-mono"
                                autoComplete="off"
                            />
                            {errors?.name && (
                                <p className="text-sm text-destructive">
                                    {errors.name}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="db-charset">
                                Charset (optional)
                            </Label>
                            <Input
                                id="db-charset"
                                value={dbForm.charset}
                                onChange={(e) =>
                                    setDbForm((p) => ({
                                        ...p,
                                        charset: e.target.value,
                                    }))
                                }
                                placeholder="utf8mb4"
                                className="font-mono"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="db-collation">
                                Collation (optional)
                            </Label>
                            <Input
                                id="db-collation"
                                value={dbForm.collation}
                                onChange={(e) =>
                                    setDbForm((p) => ({
                                        ...p,
                                        collation: e.target.value,
                                    }))
                                }
                                placeholder="utf8mb4_unicode_ci"
                                className="font-mono"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="db-user">
                                Database user (optional)
                            </Label>
                            <Input
                                id="db-user"
                                value={dbForm.db_user}
                                onChange={(e) =>
                                    setDbForm((p) => ({
                                        ...p,
                                        db_user: e.target.value,
                                    }))
                                }
                                placeholder="Leave blank for default user"
                                className="font-mono"
                                autoComplete="off"
                            />
                            {errors?.db_user && (
                                <p className="text-sm text-destructive">
                                    {errors.db_user}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="db-password">Password</Label>
                                <button
                                    type="button"
                                    className="text-sm font-medium text-primary hover:underline"
                                    onClick={() => {
                                        const pw = generatePassword();
                                        setDbForm((p) => ({
                                            ...p,
                                            db_password: pw,
                                        }));
                                        setShowDbPassword(true);
                                    }}
                                >
                                    Generate password
                                </button>
                            </div>
                            <div className="relative">
                                <Input
                                    id="db-password"
                                    type={showDbPassword ? 'text' : 'password'}
                                    value={dbForm.db_password}
                                    onChange={(e) =>
                                        setDbForm((p) => ({
                                            ...p,
                                            db_password: e.target.value,
                                        }))
                                    }
                                    placeholder="••••••••"
                                    autoComplete="new-password"
                                    className="pr-10"
                                />
                                <button
                                    type="button"
                                    className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                    onClick={() => setShowDbPassword((v) => !v)}
                                    tabIndex={-1}
                                >
                                    {showDbPassword ? (
                                        <EyeOffIcon className="h-4 w-4" />
                                    ) : (
                                        <EyeIcon className="h-4 w-4" />
                                    )}
                                </button>
                            </div>
                            {errors?.db_password && (
                                <p className="text-sm text-destructive">
                                    {errors.db_password}
                                </p>
                            )}
                        </div>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateDbOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? 'Creating…' : 'Create database'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <Sheet open={createUserOpen} onOpenChange={setCreateUserOpen}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>Create database user</SheetTitle>
                    </SheetHeader>
                    <form
                        onSubmit={handleCreateUser}
                        className="flex flex-1 flex-col gap-4 p-4 pt-4"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="user-username">Username</Label>
                            <Input
                                id="user-username"
                                value={userForm.username}
                                onChange={(e) =>
                                    setUserForm((p) => ({
                                        ...p,
                                        username: e.target.value,
                                    }))
                                }
                                placeholder="myuser"
                                className="font-mono"
                                autoComplete="off"
                            />
                            {errors?.username && (
                                <p className="text-sm text-destructive">
                                    {errors.username}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="user-password">Password</Label>
                            <Input
                                id="user-password"
                                type="password"
                                value={userForm.password}
                                onChange={(e) =>
                                    setUserForm((p) => ({
                                        ...p,
                                        password: e.target.value,
                                    }))
                                }
                                placeholder="••••••••"
                                autoComplete="new-password"
                            />
                            {errors?.password && (
                                <p className="text-sm text-destructive">
                                    {errors.password}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label>Databases</Label>
                            <div className="flex flex-col gap-2 rounded-md border p-3">
                                {dbList.map((db) => (
                                    <label
                                        key={db.id}
                                        className="flex cursor-pointer items-center gap-2"
                                    >
                                        <Checkbox
                                            checked={userForm.databases.includes(
                                                db.name,
                                            )}
                                            onCheckedChange={() =>
                                                setUserForm((p) => ({
                                                    ...p,
                                                    databases: toggleDbInList(
                                                        p.databases,
                                                        db.name,
                                                    ),
                                                }))
                                            }
                                        />
                                        <span className="font-mono text-sm">
                                            {db.name}
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {errors?.databases && (
                                <p className="text-sm text-destructive">
                                    {errors.databases}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="user-permission">Permission</Label>
                            <Select
                                value={userForm.permission}
                                onValueChange={(value) =>
                                    setUserForm((p) => ({
                                        ...p,
                                        permission: value,
                                    }))
                                }
                            >
                                <SelectTrigger id="user-permission">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="readwrite">
                                        Read-write
                                    </SelectItem>
                                    <SelectItem value="readonly">
                                        Read-only
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {errors?.permission && (
                                <p className="text-sm text-destructive">
                                    {errors.permission}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="user-host">Host (optional)</Label>
                            <Input
                                id="user-host"
                                value={userForm.host}
                                onChange={(e) =>
                                    setUserForm((p) => ({
                                        ...p,
                                        host: e.target.value,
                                    }))
                                }
                                placeholder="localhost"
                                className="font-mono"
                            />
                        </div>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateUserOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    isSubmitting ||
                                    userForm.databases.length === 0
                                }
                            >
                                {isSubmitting ? 'Creating…' : 'Create user'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <Sheet open={editUserOpen} onOpenChange={setEditUserOpen}>
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle>
                            Edit user {userToEdit?.username}
                        </SheetTitle>
                    </SheetHeader>
                    <form
                        onSubmit={handleUpdateUser}
                        className="flex flex-1 flex-col gap-4 p-4 pt-4"
                    >
                        <div className="space-y-2">
                            <Label>Databases</Label>
                            <div className="flex flex-col gap-2 rounded-md border p-3">
                                {dbList.map((db) => (
                                    <label
                                        key={db.id}
                                        className="flex cursor-pointer items-center gap-2"
                                    >
                                        <Checkbox
                                            checked={editUserForm.databases.includes(
                                                db.name,
                                            )}
                                            onCheckedChange={() =>
                                                setEditUserForm((p) => ({
                                                    ...p,
                                                    databases: toggleDbInList(
                                                        p.databases,
                                                        db.name,
                                                    ),
                                                }))
                                            }
                                        />
                                        <span className="font-mono text-sm">
                                            {db.name}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-user-permission">
                                Permission
                            </Label>
                            <Select
                                value={editUserForm.permission}
                                onValueChange={(value) =>
                                    setEditUserForm((p) => ({
                                        ...p,
                                        permission: value,
                                    }))
                                }
                            >
                                <SelectTrigger id="edit-user-permission">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="readwrite">
                                        Read-write
                                    </SelectItem>
                                    <SelectItem value="readonly">
                                        Read-only
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {errors?.permission && (
                                <p className="text-sm text-destructive">
                                    {errors.permission}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-user-password">
                                New password (optional)
                            </Label>
                            <Input
                                id="edit-user-password"
                                type="password"
                                value={editUserForm.password}
                                onChange={(e) =>
                                    setEditUserForm((p) => ({
                                        ...p,
                                        password: e.target.value,
                                    }))
                                }
                                placeholder="Leave blank to keep current"
                                autoComplete="new-password"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-user-host">Host</Label>
                            <Input
                                id="edit-user-host"
                                value={editUserForm.host}
                                onChange={(e) =>
                                    setEditUserForm((p) => ({
                                        ...p,
                                        host: e.target.value,
                                    }))
                                }
                                className="font-mono"
                            />
                        </div>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditUserOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    isSubmitting ||
                                    editUserForm.databases.length === 0
                                }
                            >
                                {isSubmitting ? 'Saving…' : 'Save'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <ConfirmDialog
                open={deleteDbOpen}
                onOpenChange={setDeleteDbOpen}
                title="Delete database"
                description={`Are you sure you want to delete the database "${dbToDelete?.name}"? This permanently drops it on the server — all data in it will be lost.`}
                confirmLabel="Delete"
                variant="destructive"
                onConfirm={confirmDeleteDb}
            />

            <ConfirmDialog
                open={deleteUserOpen}
                onOpenChange={setDeleteUserOpen}
                title="Delete database user"
                description={`Are you sure you want to remove the user "${userToDelete?.username}"? This only removes them from the app.`}
                confirmLabel="Delete"
                variant="destructive"
                onConfirm={confirmDeleteUser}
            />
        </AppLayout>
    );
}

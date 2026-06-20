import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { StatusBadge } from '@/components/ui/status-badge';
import { getServerSubNavItems } from '@/config/sub-nav-items';
import { useServerDatabasesUpdates } from '@/hooks/use-server-databases-updates';
import { useTeamPath } from '@/hooks/use-team-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type {
    DatabaseUser,
    Server,
    ServerDatabase,
} from '@/types/server';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    DatabaseIcon,
    EyeIcon,
    EyeOffIcon,
    PencilIcon,
    PlusIcon,
    Trash2Icon,
    UserPlusIcon,
} from 'lucide-react';
import { useState } from 'react';

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

    const [dbForm, setDbForm] = useState({ name: '', charset: '', collation: '', db_user: '', db_password: '' });
    const [showDbPassword, setShowDbPassword] = useState(false);
    const [userForm, setUserForm] = useState({
        username: '',
        password: '',
        databases: [] as string[],
        host: 'localhost',
    });
    const [editUserForm, setEditUserForm] = useState({
        password: '',
        databases: [] as string[],
        host: 'localhost',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: teamPath('/servers') },
        { title: server.name, href: teamPath(`/servers/${server.id}`) },
        { title: 'Databases', href: teamPath(`/servers/${server.id}/databases`) },
    ];

    const isServerReady = serverIsReady;

    const generatePassword = () => {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
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
        router.post(teamPath(`/servers/${server.id}/databases`), {
            name: dbForm.name,
            charset: dbForm.charset || undefined,
            collation: dbForm.collation || undefined,
            db_user: dbForm.db_user || undefined,
            db_password: dbForm.db_password || undefined,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setCreateDbOpen(false);
                setDbForm({ name: '', charset: '', collation: '', db_user: '', db_password: '' });
                setShowDbPassword(false);
            },
            onFinish: () => setIsSubmitting(false),
        });
    };

    const handleCreateUser = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id || userForm.databases.length === 0) return;
        setIsSubmitting(true);
        router.post(teamPath(`/servers/${server.id}/database-users`), {
            username: userForm.username,
            password: userForm.password,
            databases: userForm.databases,
            host: userForm.host || 'localhost',
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setCreateUserOpen(false);
                setUserForm({
                    username: '',
                    password: '',
                    databases: [],
                    host: 'localhost',
                });
            },
            onFinish: () => setIsSubmitting(false),
        });
    };

    const handleUpdateUser = (e: React.FormEvent) => {
        e.preventDefault();
        if (!server?.id || !userToEdit || editUserForm.databases.length === 0) return;
        setIsSubmitting(true);
        router.put(
            teamPath(`/servers/${server.id}/database-users/${userToEdit.id}`),
            {
                password: editUserForm.password || undefined,
                databases: editUserForm.databases,
                host: editUserForm.host || 'localhost',
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditUserOpen(false);
                    setUserToEdit(null);
                    setEditUserForm({ password: '', databases: [], host: 'localhost' });
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
            onSuccess: () => setDeletingDbIds((prev) => prev.filter((x) => x !== id)),
            onError: () => setDeletingDbIds((prev) => prev.filter((x) => x !== id)),
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
            onSuccess: () => setDeletingUserIds((prev) => prev.filter((x) => x !== id)),
            onError: () => setDeletingUserIds((prev) => prev.filter((x) => x !== id)),
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            subNavItems={getServerSubNavItems(pageProps.currentTeam?.slug ?? '', server.id)}
        >
            <Head title={`Databases - ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Databases
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Manage databases and database users on {server.name}.
                        </p>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-1">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <DatabaseIcon className="h-5 w-5" />
                                        Databases
                                    </CardTitle>
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
                        </CardHeader>
                        <CardContent>
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
                                            <TableCell
                                                colSpan={5}
                                                className="h-24 text-center text-muted-foreground text-sm"
                                            >
                                                No databases yet.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        dbList.map((db) => {
                                            const isDeleting =
                                                deletingDbIds.includes(db.id) ||
                                                db.status === 'deleting';
                                            return (
                                            <TableRow key={db.id}>
                                                <TableCell className="font-medium font-mono">
                                                    {db.name}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {db.charset ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {db.collation ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={isDeleting ? 'deleting' : db.status}
                                                        label={isDeleting ? 'Deleting...' : undefined}
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
                                                                setDbToDelete(db);
                                                                setDeleteDbOpen(true);
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
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <UserPlusIcon className="h-5 w-5" />
                                        Database users
                                    </CardTitle>
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
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Username</TableHead>
                                        <TableHead>Host</TableHead>
                                        <TableHead>Databases</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-[100px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {userList.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={5}
                                                className="h-24 text-center text-muted-foreground text-sm"
                                            >
                                                No database users yet.
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
                                                <TableCell className="text-muted-foreground text-sm font-mono">
                                                    {user.host ?? 'localhost'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {(user.databases ?? []).join(', ') || '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={isDeleting ? 'deleting' : user.status}
                                                        label={isDeleting ? 'Deleting...' : undefined}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            className="h-8 w-8"
                                                            disabled={isDeleting}
                                                            onClick={() => openEditUser(user)}
                                                            aria-label="Edit user"
                                                        >
                                                            <PencilIcon className="h-4 w-4" />
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            className="h-8 w-8"
                                                            disabled={isDeleting}
                                                            onClick={() => {
                                                                setUserToDelete(user);
                                                                setDeleteUserOpen(true);
                                                            }}
                                                            aria-label="Delete user"
                                                        >
                                                            <Trash2Icon className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                            );
                                        })
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
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
                                    setDbForm((p) => ({ ...p, name: e.target.value }))
                                }
                                placeholder="mydb"
                                className="font-mono"
                                autoComplete="off"
                            />
                            {errors?.name && (
                                <p className="text-destructive text-sm">
                                    {errors.name}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="db-charset">Charset (optional)</Label>
                            <Input
                                id="db-charset"
                                value={dbForm.charset}
                                onChange={(e) =>
                                    setDbForm((p) => ({ ...p, charset: e.target.value }))
                                }
                                placeholder="utf8mb4"
                                className="font-mono"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="db-collation">Collation (optional)</Label>
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
                            <Label htmlFor="db-user">Database user (optional)</Label>
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
                                <p className="text-destructive text-sm">
                                    {errors.db_user}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="db-password">Password</Label>
                                <button
                                    type="button"
                                    className="text-primary text-sm font-medium hover:underline"
                                    onClick={() => {
                                        const pw = generatePassword();
                                        setDbForm((p) => ({ ...p, db_password: pw }));
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
                                    className="text-muted-foreground hover:text-foreground absolute top-1/2 right-3 -translate-y-1/2"
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
                                <p className="text-destructive text-sm">
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
                                <p className="text-destructive text-sm">
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
                                <p className="text-destructive text-sm">
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
                                            checked={userForm.databases.includes(db.name)}
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
                                <p className="text-destructive text-sm">
                                    {errors.databases}
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="user-host">Host (optional)</Label>
                            <Input
                                id="user-host"
                                value={userForm.host}
                                onChange={(e) =>
                                    setUserForm((p) => ({ ...p, host: e.target.value }))
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
                description={`Are you sure you want to remove the database "${dbToDelete?.name}"? This only removes it from the app; the database on the server is not dropped.`}
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

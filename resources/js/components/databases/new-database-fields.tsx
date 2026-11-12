import { EyeIcon, EyeSlashIcon } from '@phosphor-icons/react';
import { useState } from 'react';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { generatePassword } from '@/lib/generate-password';
import type { Server } from '@/types/server';

export interface NewDatabaseValues {
    charset: string;
    collation: string;
    db_user: string;
    db_password: string;
}

interface NewDatabaseFieldsProps {
    idPrefix: string;
    values: NewDatabaseValues;
    onChange: (changes: Partial<NewDatabaseValues>) => void;
    databaseType: Server['database_type'];
    /** The user a blank "Database user" falls back to. */
    defaultUsername: string;
    /** Database users that already exist on the server. */
    existingUsernames: string[];
    errors?: Partial<Record<keyof NewDatabaseValues, string>>;
}

/**
 * The charset, collation, user and password inputs of a "Create database"
 * form. Charset and collation only mean something to MySQL and MariaDB. A user
 * that already exists keeps its password, so none is asked for.
 */
export function NewDatabaseFields({
    idPrefix,
    values,
    onChange,
    databaseType,
    defaultUsername,
    existingUsernames,
    errors,
}: NewDatabaseFieldsProps) {
    const [showPassword, setShowPassword] = useState(false);

    const usesCharsets = databaseType === 'mysql' || databaseType === 'mariadb';
    const username = values.db_user.trim() || defaultUsername;
    const userExists = existingUsernames.includes(username);

    return (
        <>
            {usesCharsets && (
                <>
                    <div className="space-y-2">
                        <Label htmlFor={`${idPrefix}-charset`}>
                            Charset (optional)
                        </Label>
                        <Input
                            id={`${idPrefix}-charset`}
                            value={values.charset}
                            onChange={(e) =>
                                onChange({ charset: e.target.value })
                            }
                            placeholder="utf8mb4"
                            className="font-mono"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor={`${idPrefix}-collation`}>
                            Collation (optional)
                        </Label>
                        <Input
                            id={`${idPrefix}-collation`}
                            value={values.collation}
                            onChange={(e) =>
                                onChange({ collation: e.target.value })
                            }
                            placeholder="utf8mb4_unicode_ci"
                            className="font-mono"
                        />
                    </div>
                </>
            )}
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}-user`}>
                    Database user (optional)
                </Label>
                <Input
                    id={`${idPrefix}-user`}
                    value={values.db_user}
                    onChange={(e) => onChange({ db_user: e.target.value })}
                    placeholder={`Leave blank to use ${defaultUsername}`}
                    className="font-mono"
                    autoComplete="off"
                />
                {errors?.db_user && (
                    <p className="text-sm text-destructive">{errors.db_user}</p>
                )}
            </div>
            {userExists ? (
                <p className="text-sm text-muted-foreground">
                    <span className="font-mono text-foreground">
                        {username}
                    </span>{' '}
                    already exists, so it keeps its current password and is
                    given access to this database.
                </p>
            ) : (
                <div className="space-y-2">
                    <div className="flex items-center justify-between">
                        <Label htmlFor={`${idPrefix}-password`}>Password</Label>
                        <button
                            type="button"
                            className="text-sm font-medium text-primary hover:underline"
                            onClick={() => {
                                onChange({ db_password: generatePassword() });
                                setShowPassword(true);
                            }}
                        >
                            Generate password
                        </button>
                    </div>
                    <div className="relative">
                        <Input
                            id={`${idPrefix}-password`}
                            type={showPassword ? 'text' : 'password'}
                            value={values.db_password}
                            onChange={(e) =>
                                onChange({ db_password: e.target.value })
                            }
                            placeholder="••••••••"
                            autoComplete="new-password"
                            className="pr-10"
                        />
                        <button
                            type="button"
                            className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            onClick={() => setShowPassword((v) => !v)}
                            tabIndex={-1}
                            aria-label={
                                showPassword ? 'Hide password' : 'Show password'
                            }
                        >
                            {showPassword ? (
                                <EyeSlashIcon className="h-4 w-4" />
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
            )}
        </>
    );
}

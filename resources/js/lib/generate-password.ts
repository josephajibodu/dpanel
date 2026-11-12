const PASSWORD_CHARACTERS =
    'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';

/** A random 24-character password for a database user. */
export function generatePassword(): string {
    const array = new Uint32Array(24);
    crypto.getRandomValues(array);

    return Array.from(
        array,
        (value) => PASSWORD_CHARACTERS[value % PASSWORD_CHARACTERS.length],
    ).join('');
}

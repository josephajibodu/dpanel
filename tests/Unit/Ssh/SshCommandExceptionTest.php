<?php

use App\Exceptions\SshCommandException;

it('keeps storage keys and database passwords out of the message', function () {
    $command = "bash -c 'set -o pipefail; sudo mysqldump --user=root --password='\\''rootsecret'\\'' -B '\\''app'\\'' | gzip -c | "
        ."AWS_ACCESS_KEY_ID='\\''AKIDSTORAGEKEY'\\'' AWS_SECRET_ACCESS_KEY='\\''STORAGESECRETVALUE'\\'' aws s3 cp - '\\''s3://bucket/app.sql.gz'\\'''";

    $exception = new SshCommandException(
        command: $command,
        exitCode: 1,
        output: '',
        stderr: 'upload failed: An error occurred (Unauthorized) when calling the PutObject operation: Unauthorized',
    );

    expect($exception->getMessage())
        ->toContain('AWS_SECRET_ACCESS_KEY=[redacted]')
        ->toContain('--password=[redacted]')
        ->toContain('An error occurred (Unauthorized)')
        ->not->toContain('rootsecret')
        ->not->toContain('AKIDSTORAGEKEY')
        ->not->toContain('STORAGESECRETVALUE')
        ->and($exception->command)->not->toContain('STORAGESECRETVALUE');
});

it('removes the given secrets wherever they appear', function () {
    $exception = new SshCommandException(
        command: 'echo "deploy:super-secret-sudo" | chpasswd',
        exitCode: 1,
        output: 'super-secret-sudo',
        stderr: 'chpasswd: super-secret-sudo rejected',
        secrets: ['super-secret-sudo'],
    );

    expect($exception->getMessage())->not->toContain('super-secret-sudo')
        ->and($exception->output)->toBe('[redacted]')
        ->and($exception->stderr)->toBe('chpasswd: [redacted] rejected');
});

it('redacts passwords set in SQL', function (string $sql) {
    expect(SshCommandException::redact($sql))
        ->not->toContain('hunter2')
        ->toContain("'[redacted]'");
})->with([
    'mysql' => ["ALTER USER 'root'@'localhost' IDENTIFIED BY 'hunter2';"],
    'postgres' => ["ALTER USER postgres WITH PASSWORD 'hunter2';"],
]);

it('leaves ordinary commands readable', function () {
    expect(SshCommandException::redact('sudo nginx -t && sudo systemctl reload nginx'))
        ->toBe('sudo nginx -t && sudo systemctl reload nginx');
});

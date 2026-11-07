<?php

use App\Exceptions\ProviderApiException;
use App\Services\Providers\AwsProvider;
use Aws\CommandInterface;
use Aws\Ec2\Exception\Ec2Exception;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;

/**
 * Build an AwsProvider whose EC2 calls are answered from $responses (keyed
 * by operation name) instead of AWS. Every call made is recorded in $calls
 * as ['name' => ..., 'region' => ..., 'args' => ...].
 *
 * A response is either the result array, or an EC2 error code prefixed with
 * "error:" (e.g. "error:AuthFailure") to make the call fail.
 *
 * @param  array<string, mixed>  $responses
 * @param  array<int, array{name: string, region: string, args: array<string, mixed>}>  $calls
 */
function fakeAws(array $responses, ?array &$calls = []): AwsProvider
{
    $calls = [];

    $handler = function (CommandInterface $command, RequestInterface $request) use ($responses, &$calls) {
        $name = $command->getName();
        preg_match('/ec2\.([a-z0-9-]+)\.amazonaws/', (string) $request->getUri(), $match);
        $calls[] = ['name' => $name, 'region' => $match[1] ?? '', 'args' => $command->toArray()];

        $response = $responses[$name] ?? [];

        if (is_string($response) && str_starts_with($response, 'error:')) {
            return Create::rejectionFor(new Ec2Exception('AWS error', $command, ['code' => substr($response, 6)]));
        }

        return Create::promiseFor(new Result($response));
    };

    $provider = new AwsProvider($handler);
    $provider->setCredentials(['access_key_id' => 'AKIATESTTESTTEST', 'secret_access_key' => 'secret-secret-secret']);

    return $provider;
}

/**
 * @param  array<int, array{name: string, region: string, args: array<string, mixed>}>  $calls
 * @return array<int, string>
 */
function callNames(array $calls): array
{
    return array_column($calls, 'name');
}

/**
 * @param  array<int, array{name: string, region: string, args: array<string, mixed>}>  $calls
 * @return array{name: string, region: string, args: array<string, mixed>}
 */
function firstCall(array $calls, string $name): array
{
    return collect($calls)->firstWhere('name', $name);
}

it('imports the SSH key into the server region and prefixes the ID with it', function () {
    $provider = fakeAws([], $calls);

    $keyId = $provider->createSshKey('flitops-web-1', 'ssh-ed25519 AAAAC3Nza flitops-worker', 'eu-west-2');

    expect($keyId)->toBe('eu-west-2:flitops-web-1');
    expect(firstCall($calls, 'ImportKeyPair'))
        ->region->toBe('eu-west-2')
        ->args->KeyName->toBe('flitops-web-1');
});

it('creates a server with the newest Ubuntu 24.04 image, a security group and root login enabled', function () {
    $provider = fakeAws([
        'DescribeImages' => ['Images' => [
            ['ImageId' => 'ami-old', 'CreationDate' => '2026-01-01T00:00:00.000Z'],
            ['ImageId' => 'ami-new', 'CreationDate' => '2026-10-01T00:00:00.000Z'],
        ]],
        'DescribeVpcs' => ['Vpcs' => [['VpcId' => 'vpc-123']]],
        'DescribeSecurityGroups' => ['SecurityGroups' => []],
        'CreateSecurityGroup' => ['GroupId' => 'sg-new'],
        'RunInstances' => ['Instances' => [['InstanceId' => 'i-abc', 'State' => ['Name' => 'pending']]]],
    ], $calls);

    $result = $provider->createServer('web-1', 't3.small', 'eu-west-2', 'eu-west-2:flitops-web-1');

    expect($result->id)->toBe('eu-west-2:i-abc');

    expect(firstCall($calls, 'DescribeImages')['args']['Filters'][0]['Values'])
        ->toBe(['ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*']);

    $run = firstCall($calls, 'RunInstances');
    expect($run['region'])->toBe('eu-west-2');
    expect($run['args'])
        ->ImageId->toBe('ami-new')
        ->InstanceType->toBe('t3.small')
        ->KeyName->toBe('flitops-web-1')
        ->SecurityGroupIds->toBe(['sg-new']);
    expect(base64_decode($run['args']['UserData']))->toContain('disable_root: false');

    $ports = collect(firstCall($calls, 'AuthorizeSecurityGroupIngress')['args']['IpPermissions'])
        ->map(fn ($p) => $p['IpProtocol'].'/'.$p['FromPort'])
        ->all();
    expect($ports)->toBe(['tcp/22', 'tcp/80', 'tcp/443', 'udp/443']);
});

it('reuses the existing security group', function () {
    $provider = fakeAws([
        'DescribeImages' => ['Images' => [['ImageId' => 'ami-1', 'CreationDate' => '2026-10-01T00:00:00.000Z']]],
        'DescribeVpcs' => ['Vpcs' => [['VpcId' => 'vpc-123']]],
        'DescribeSecurityGroups' => ['SecurityGroups' => [['GroupId' => 'sg-existing']]],
        'RunInstances' => ['Instances' => [['InstanceId' => 'i-abc', 'State' => ['Name' => 'pending']]]],
    ], $calls);

    $provider->createServer('web-1', 't3.small', 'eu-west-2', 'eu-west-2:flitops-web-1');

    expect(callNames($calls))->not->toContain('CreateSecurityGroup');
    expect(firstCall($calls, 'RunInstances')['args']['SecurityGroupIds'])->toBe(['sg-existing']);
});

it('explains how to fix a region without a default VPC', function () {
    $provider = fakeAws([
        'DescribeImages' => ['Images' => [['ImageId' => 'ami-1', 'CreationDate' => '2026-10-01T00:00:00.000Z']]],
        'DescribeVpcs' => ['Vpcs' => []],
    ], $calls);

    expect(fn () => $provider->createServer('web-1', 't3.small', 'eu-west-2', 'eu-west-2:flitops-web-1'))
        ->toThrow(ProviderApiException::class, 'no default VPC in eu-west-2');
    expect(callNames($calls))->not->toContain('RunInstances');
});

it('reports a pending server as not active without touching Elastic IPs', function () {
    $provider = fakeAws([
        'DescribeInstances' => ['Reservations' => [['Instances' => [['State' => ['Name' => 'pending']]]]]],
    ], $calls);

    $status = $provider->getServerStatus('eu-west-2:i-abc');

    expect($status->isActive)->toBeFalse();
    expect($status->ipAddress)->toBeNull();
    expect(callNames($calls))->toBe(['DescribeInstances']);
});

it('gives a running server an Elastic IP and reports it', function () {
    $provider = fakeAws([
        'DescribeInstances' => ['Reservations' => [['Instances' => [['State' => ['Name' => 'running'], 'PrivateIpAddress' => '172.31.0.5']]]]],
        'DescribeAddresses' => ['Addresses' => []],
        'AllocateAddress' => ['AllocationId' => 'eipalloc-1', 'PublicIp' => '3.8.1.1'],
    ], $calls);

    $status = $provider->getServerStatus('eu-west-2:i-abc');

    expect($status->isActive)->toBeTrue();
    expect($status->ipAddress)->toBe('3.8.1.1');
    expect($status->privateIpAddress)->toBe('172.31.0.5');
    expect(firstCall($calls, 'DescribeInstances')['region'])->toBe('eu-west-2');
    expect(firstCall($calls, 'AllocateAddress')['args']['TagSpecifications'][0]['Tags'])
        ->toContain(['Key' => 'flitops-instance', 'Value' => 'i-abc']);
    expect(firstCall($calls, 'AssociateAddress')['args'])
        ->AllocationId->toBe('eipalloc-1')
        ->InstanceId->toBe('i-abc');
});

it('does not allocate a second Elastic IP on later polls', function () {
    $provider = fakeAws([
        'DescribeInstances' => ['Reservations' => [['Instances' => [['State' => ['Name' => 'running']]]]]],
        'DescribeAddresses' => ['Addresses' => [['AllocationId' => 'eipalloc-1', 'PublicIp' => '3.8.1.1', 'InstanceId' => 'i-abc']]],
    ], $calls);

    $status = $provider->getServerStatus('eu-west-2:i-abc');

    expect($status->ipAddress)->toBe('3.8.1.1');
    expect(callNames($calls))->toBe(['DescribeInstances', 'DescribeAddresses']);
});

it('releases the Elastic IP and terminates the instance when deleting a server', function () {
    $provider = fakeAws([
        'DescribeAddresses' => ['Addresses' => [['AllocationId' => 'eipalloc-1', 'AssociationId' => 'eipassoc-1']]],
    ], $calls);

    $provider->deleteServer('eu-west-2:i-abc');

    expect(callNames($calls))->toBe(['DescribeAddresses', 'DisassociateAddress', 'ReleaseAddress', 'TerminateInstances']);
    expect(firstCall($calls, 'ReleaseAddress')['args']['AllocationId'])->toBe('eipalloc-1');
    expect(firstCall($calls, 'TerminateInstances')['args']['InstanceIds'])->toBe(['i-abc']);
});

it('treats an already terminated instance as deleted', function () {
    $provider = fakeAws([
        'DescribeAddresses' => ['Addresses' => []],
        'TerminateInstances' => 'error:InvalidInstanceID.NotFound',
    ]);

    $provider->deleteServer('eu-west-2:i-gone');
})->throwsNoExceptions();

it('deletes the SSH key in the region it was created in', function () {
    $provider = fakeAws([], $calls);

    $provider->deleteSshKey('ap-southeast-2:flitops-web-1');

    expect(firstCall($calls, 'DeleteKeyPair'))
        ->region->toBe('ap-southeast-2')
        ->args->KeyName->toBe('flitops-web-1');
});

it('reports invalid credentials', function () {
    $provider = fakeAws(['DescribeRegions' => 'error:AuthFailure']);

    expect($provider->validateCredentials())->toBeFalse();
});

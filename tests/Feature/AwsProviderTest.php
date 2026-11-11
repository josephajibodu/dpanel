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
 * A response is either the result array, an EC2 error code prefixed with
 * "error:" (e.g. "error:AuthFailure") to make the call fail, or a closure
 * that is given the call's arguments and returns one of those.
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

        if ($response instanceof Closure) {
            $response = $response($command->toArray());
        }

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

/**
 * One product as AWS's price list returns it: a JSON document holding the
 * product's attributes and its on-demand price.
 *
 * @param  array<string, string>  $attributes
 */
function awsPricedProduct(array $attributes, string $price): string
{
    return json_encode([
        'product' => ['attributes' => $attributes],
        'terms' => ['OnDemand' => ['SKU.TERM' => ['priceDimensions' => ['SKU.TERM.RATE' => ['pricePerUnit' => ['USD' => $price]]]]]],
    ]);
}

/**
 * Answer AWS price list calls with the given instance and disk products,
 * depending on which of the two the call asks for.
 *
 * @param  list<string>  $instances
 * @param  list<string>  $disks
 */
function awsPriceList(array $instances, array $disks = []): Closure
{
    return fn (array $args): array => [
        'PriceList' => collect($args['Filters'])->contains('Field', 'instanceType') ? $instances : $disks,
    ];
}

it('prices each size per region from the AWS price list and offers it only where AWS sells it', function () {
    $provider = fakeAws(['GetProducts' => awsPriceList([
        awsPricedProduct(['instanceType' => 't3.small', 'regionCode' => 'us-east-1'], '0.0208000000'),
        awsPricedProduct(['instanceType' => 't3.small', 'regionCode' => 'sa-east-1'], '0.0336000000'),
        awsPricedProduct(['instanceType' => 't4g.small', 'regionCode' => 'us-east-1'], '0.0168000000'),
    ])], $calls);

    $sizes = $provider->getSizes()->keyBy('slug');

    expect($sizes->keys()->all())->toBe(['t4g.small', 't3.small'])
        ->and($sizes['t3.small'])
        ->regions->toBe(['us-east-1', 'sa-east-1'])
        ->regionOverrides->toBe([
            'us-east-1' => ['price_monthly' => 15.18, 'price_hourly' => 0.0208],
            'sa-east-1' => ['price_monthly' => 24.53, 'price_hourly' => 0.0336],
        ])
        ->priceMonthly->toBe(15.18)
        ->priceIsEstimate->toBeFalse()
        ->and($sizes['t4g.small']->regions)->toBe(['us-east-1']);

    expect(collect(firstCall($calls, 'GetProducts')['args']['Filters'])->firstWhere('Field', 'instanceType'))
        ->Type->toBe('ANY_OF')
        ->Value->toContain('t3.small,');
});

it('describes the processor of x86 and Arm sizes', function () {
    $provider = fakeAws(['GetProducts' => 'error:AccessDeniedException']);

    $sizes = $provider->getSizes()->keyBy('slug');

    expect($sizes['m6i.large'])
        ->category->toBe('General purpose')
        ->architecture->toBe('x86')
        ->cpuVendor->toBe('Intel')
        ->and($sizes['m7g.large'])
        ->category->toBe('General purpose')
        ->architecture->toBe('arm')
        ->cpuVendor->toBe('AWS Graviton');
});

it('falls back to estimated us-east-1 prices when the account may not read the price list', function () {
    $provider = fakeAws([
        'GetProducts' => 'error:AccessDeniedException',
        'DescribeRegions' => ['Regions' => [['RegionName' => 'eu-west-2']]],
    ]);

    $size = $provider->getSizes()->firstWhere('slug', 't3.small');

    expect($size)
        ->priceMonthly->toBe(15.18)
        ->priceHourly->toBe(0.0208)
        ->regions->toBeNull()
        ->regionOverrides->toBe([])
        ->priceIsEstimate->toBeTrue()
        ->and($provider->getRegions()->sole()->extraCharges[0]['price_monthly'])->toBe(2.40);
});

it('lists the disk at its price in each region and the public IP as separate charges', function () {
    $provider = fakeAws([
        'DescribeRegions' => ['Regions' => [['RegionName' => 'sa-east-1'], ['RegionName' => 'us-east-1']]],
        'GetProducts' => awsPriceList(
            [awsPricedProduct(['instanceType' => 't3.small', 'regionCode' => 'us-east-1'], '0.0208000000')],
            [
                awsPricedProduct(['volumeApiName' => 'gp3', 'regionCode' => 'us-east-1'], '0.0800000000'),
                awsPricedProduct(['volumeApiName' => 'gp3', 'regionCode' => 'sa-east-1'], '0.1520000000'),
            ],
        ),
    ]);

    $regions = $provider->getRegions()->keyBy('slug');

    expect($regions['us-east-1']->extraCharges)->toBe([
        ['label' => '30 GB disk (gp3)', 'price_monthly' => 2.40],
        ['label' => 'Public IPv4 address', 'price_monthly' => 3.65],
    ])->and($regions['sa-east-1']->extraCharges[0]['price_monthly'])->toBe(4.56);
});

it('asks AWS for prices once and reuses the answer', function () {
    $provider = fakeAws(['GetProducts' => awsPriceList([
        awsPricedProduct(['instanceType' => 't3.small', 'regionCode' => 'us-east-1'], '0.0208000000'),
    ])], $calls);

    $provider->getSizes();
    $provider->getSizes();

    expect(array_count_values(callNames($calls))['GetProducts'])->toBe(2);
});

it('creates an Arm server from the Arm build of Ubuntu', function () {
    $provider = fakeAws([
        'DescribeImages' => ['Images' => [['ImageId' => 'ami-arm', 'CreationDate' => '2026-10-01T00:00:00.000Z']]],
        'DescribeVpcs' => ['Vpcs' => [['VpcId' => 'vpc-123']]],
        'DescribeSecurityGroups' => ['SecurityGroups' => [['GroupId' => 'sg-1']]],
        'RunInstances' => ['Instances' => [['InstanceId' => 'i-abc', 'State' => ['Name' => 'pending']]]],
    ], $calls);

    $provider->createServer('web-1', 't4g.small', 'eu-west-2', 'eu-west-2:flitops-web-1');

    expect(array_column(firstCall($calls, 'DescribeImages')['args']['Filters'], 'Values', 'Name'))
        ->name->toBe(['ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-arm64-server-*'])
        ->architecture->toBe(['arm64']);
});

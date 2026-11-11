<?php

namespace App\Services\Providers;

use App\Contracts\ProviderContract;
use App\Data\ProviderRegion;
use App\Data\ProviderServerResult;
use App\Data\ProviderServerStatus;
use App\Data\ProviderSize;
use App\Exceptions\ProviderApiException;
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use Illuminate\Support\Collection;

/**
 * Amazon EC2.
 *
 * Unlike the other providers, EC2 resources only exist inside one region, so
 * every server and SSH key ID this driver hands out is prefixed with its
 * region ("eu-west-2:i-0abc…") and parsed back on later calls.
 */
class AwsProvider implements ProviderContract
{
    /**
     * Canonical's AWS account, which publishes the official Ubuntu images.
     */
    private const string CANONICAL_OWNER_ID = '099720109477';

    private const string UBUNTU_IMAGE_NAME = 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*';

    private const string SECURITY_GROUP_NAME = 'flitops';

    private const int ROOT_VOLUME_GB = 30;

    /**
     * What AWS bills each month on top of the instance itself, at us-east-1
     * prices: the 30 GB gp3 root volume ($0.08/GB) and the Elastic IP
     * ($0.005/hour).
     *
     * @var list<array{label: string, price_monthly: float}>
     */
    private const array EXTRA_CHARGES = [
        ['label' => '30 GB disk (gp3)', 'price_monthly' => 2.40],
        ['label' => 'Public IPv4 address', 'price_monthly' => 3.65],
    ];

    /**
     * The hours AWS counts in a month when it quotes a monthly price.
     */
    private const int HOURS_PER_MONTH = 730;

    /**
     * Instance types offered when creating a server, with us-east-1 on-demand
     * Linux prices per month (730 hours). Prices in other regions are similar
     * but not identical, and AWS has no simple price API, so these are a guide.
     * All of them run on Intel x86 processors.
     *
     * @var array<string, array{vcpus: int, memory: int, price: float, family: string}>
     */
    private const array INSTANCE_TYPES = [
        't3.small' => ['vcpus' => 2, 'memory' => 2048, 'price' => 15.18, 'family' => 'General purpose, burstable'],
        't3.medium' => ['vcpus' => 2, 'memory' => 4096, 'price' => 30.37, 'family' => 'General purpose, burstable'],
        't3.large' => ['vcpus' => 2, 'memory' => 8192, 'price' => 60.74, 'family' => 'General purpose, burstable'],
        'c6i.large' => ['vcpus' => 2, 'memory' => 4096, 'price' => 62.05, 'family' => 'Compute optimized'],
        'm6i.large' => ['vcpus' => 2, 'memory' => 8192, 'price' => 70.08, 'family' => 'General purpose'],
        'r6i.large' => ['vcpus' => 2, 'memory' => 16384, 'price' => 91.98, 'family' => 'Memory optimized'],
        't3.xlarge' => ['vcpus' => 4, 'memory' => 16384, 'price' => 121.47, 'family' => 'General purpose, burstable'],
        'c6i.xlarge' => ['vcpus' => 4, 'memory' => 8192, 'price' => 124.10, 'family' => 'Compute optimized'],
        'm6i.xlarge' => ['vcpus' => 4, 'memory' => 16384, 'price' => 140.16, 'family' => 'General purpose'],
        't3.2xlarge' => ['vcpus' => 8, 'memory' => 32768, 'price' => 242.94, 'family' => 'General purpose, burstable'],
    ];

    /**
     * EC2's API only returns region codes, so friendly names are kept here.
     *
     * @var array<string, string>
     */
    private const array REGION_NAMES = [
        'us-east-1' => 'US East (N. Virginia)',
        'us-east-2' => 'US East (Ohio)',
        'us-west-1' => 'US West (N. California)',
        'us-west-2' => 'US West (Oregon)',
        'ca-central-1' => 'Canada (Central)',
        'sa-east-1' => 'South America (São Paulo)',
        'eu-west-1' => 'Europe (Ireland)',
        'eu-west-2' => 'Europe (London)',
        'eu-west-3' => 'Europe (Paris)',
        'eu-central-1' => 'Europe (Frankfurt)',
        'eu-north-1' => 'Europe (Stockholm)',
        'ap-south-1' => 'Asia Pacific (Mumbai)',
        'ap-northeast-1' => 'Asia Pacific (Tokyo)',
        'ap-northeast-2' => 'Asia Pacific (Seoul)',
        'ap-northeast-3' => 'Asia Pacific (Osaka)',
        'ap-southeast-1' => 'Asia Pacific (Singapore)',
        'ap-southeast-2' => 'Asia Pacific (Sydney)',
    ];

    /**
     * @var array{key: string, secret: string}
     */
    private array $credentials = ['key' => '', 'secret' => ''];

    /** @var array<string, Ec2Client> */
    private array $clients = [];

    /** @var callable|null */
    private $handler;

    /**
     * @param  callable|null  $handler  An AWS SDK handler, used by tests to fake API responses.
     */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler;
    }

    public function setCredentials(array $credentials): void
    {
        $this->credentials = [
            'key' => $credentials['access_key_id'] ?? '',
            'secret' => $credentials['secret_access_key'] ?? '',
        ];
        $this->clients = [];
    }

    public function validateCredentials(): bool
    {
        try {
            $this->client('us-east-1')->describeRegions();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function getRegions(): Collection
    {
        $result = $this->call('fetch regions', fn () => $this->client('us-east-1')->describeRegions());

        return collect($result['Regions'])
            ->map(fn ($r) => new ProviderRegion(
                slug: $r['RegionName'],
                name: (self::REGION_NAMES[$r['RegionName']] ?? $r['RegionName']).' ('.$r['RegionName'].')',
                extraCharges: self::EXTRA_CHARGES,
            ))
            ->sortBy('slug')
            ->values();
    }

    public function getSizes(): Collection
    {
        return collect(self::INSTANCE_TYPES)
            ->map(fn ($type, $slug) => new ProviderSize(
                slug: $slug,
                vcpus: $type['vcpus'],
                memory: $type['memory'],
                disk: self::ROOT_VOLUME_GB,
                priceMonthly: $type['price'],
                category: $type['family'],
                priceHourly: round($type['price'] / self::HOURS_PER_MONTH, 4),
                architecture: 'x86',
                cpuVendor: 'Intel',
                diskType: 'gp3 SSD',
            ))
            ->sortBy('priceMonthly')
            ->values();
    }

    public function createServer(
        string $name,
        string $size,
        string $region,
        string $sshKeyId,
    ): ProviderServerResult {
        [, $keyName] = $this->parseId($sshKeyId);
        $client = $this->client($region);

        $result = $this->call('create server', fn () => $client->runInstances([
            'ImageId' => $this->ubuntuImageId($client),
            'InstanceType' => $size,
            'MinCount' => 1,
            'MaxCount' => 1,
            'KeyName' => $keyName,
            'SecurityGroupIds' => [$this->securityGroupId($client, $region)],
            'UserData' => base64_encode($this->userData()),
            'BlockDeviceMappings' => [[
                'DeviceName' => '/dev/sda1',
                'Ebs' => [
                    'VolumeSize' => self::ROOT_VOLUME_GB,
                    'VolumeType' => 'gp3',
                    'DeleteOnTermination' => true,
                ],
            ]],
            'MetadataOptions' => ['HttpTokens' => 'required'],
            'TagSpecifications' => [[
                'ResourceType' => 'instance',
                'Tags' => [
                    ['Key' => 'Name', 'Value' => $name],
                    ['Key' => 'app', 'Value' => 'flitops'],
                ],
            ]],
        ]));

        $instance = $result['Instances'][0];

        return new ProviderServerResult(
            id: $region.':'.$instance['InstanceId'],
            name: $name,
            status: $instance['State']['Name'],
        );
    }

    /**
     * Once the instance is running, this also gives it an Elastic IP (a fixed
     * public IP), so the address DNS points at survives a stop/start. Until
     * that is attached no IP is reported, so callers keep polling.
     */
    public function getServerStatus(string $serverId): ProviderServerStatus
    {
        [$region, $instanceId] = $this->parseId($serverId);
        $client = $this->client($region);

        $result = $this->call('get server status', fn () => $client->describeInstances([
            'InstanceIds' => [$instanceId],
        ]));

        $instance = $result['Reservations'][0]['Instances'][0];
        $state = $instance['State']['Name'];
        $ipAddress = null;

        if ($state === 'running') {
            $ipAddress = $this->ensureElasticIp($client, $instanceId);
        }

        return new ProviderServerStatus(
            id: $serverId,
            status: $state,
            isActive: $state === 'running',
            ipAddress: $ipAddress,
            privateIpAddress: $instance['PrivateIpAddress'] ?? null,
        );
    }

    public function deleteServer(string $serverId): void
    {
        [$region, $instanceId] = $this->parseId($serverId);
        $client = $this->client($region);

        // Release the Elastic IP first: AWS keeps billing for it after the
        // instance is gone.
        $addresses = $this->call('find Elastic IP', fn () => $client->describeAddresses([
            'Filters' => [['Name' => 'tag:flitops-instance', 'Values' => [$instanceId]]],
        ]));

        foreach ($addresses['Addresses'] as $address) {
            if (isset($address['AssociationId'])) {
                $this->call('detach Elastic IP', fn () => $client->disassociateAddress([
                    'AssociationId' => $address['AssociationId'],
                ]));
            }

            $this->call('release Elastic IP', fn () => $client->releaseAddress([
                'AllocationId' => $address['AllocationId'],
            ]));
        }

        try {
            $client->terminateInstances(['InstanceIds' => [$instanceId]]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'InvalidInstanceID.NotFound') {
                throw $this->toProviderException('delete server', $e);
            }
        }
    }

    public function createSshKey(string $name, string $publicKey, string $region): string
    {
        $this->call('create SSH key', fn () => $this->client($region)->importKeyPair([
            'KeyName' => $name,
            'PublicKeyMaterial' => $publicKey,
            'TagSpecifications' => [[
                'ResourceType' => 'key-pair',
                'Tags' => [['Key' => 'app', 'Value' => 'flitops']],
            ]],
        ]));

        return $region.':'.$name;
    }

    public function deleteSshKey(string $keyId): void
    {
        [$region, $keyName] = $this->parseId($keyId);

        // EC2 reports success when the key is already gone.
        $this->call('delete SSH key', fn () => $this->client($region)->deleteKeyPair([
            'KeyName' => $keyName,
        ]));
    }

    /**
     * Ubuntu's images only allow logging in as "ubuntu". Flitops provisions
     * as root, so cloud-init is told to install the key for root as well.
     */
    private function userData(): string
    {
        return "#cloud-config\ndisable_root: false\n";
    }

    /**
     * Find the newest official Ubuntu 24.04 image in the client's region.
     */
    private function ubuntuImageId(Ec2Client $client): string
    {
        $result = $this->call('find Ubuntu image', fn () => $client->describeImages([
            'Owners' => [self::CANONICAL_OWNER_ID],
            'Filters' => [
                ['Name' => 'name', 'Values' => [self::UBUNTU_IMAGE_NAME]],
                ['Name' => 'architecture', 'Values' => ['x86_64']],
                ['Name' => 'state', 'Values' => ['available']],
            ],
        ]));

        $image = collect($result['Images'])->sortByDesc('CreationDate')->first();

        if (! $image) {
            throw new ProviderApiException('No Ubuntu 24.04 image found in this region.', 'aws');
        }

        return $image['ImageId'];
    }

    /**
     * Get (or create) the shared security group that opens SSH, HTTP and
     * HTTPS in the region's default VPC. EC2 blocks all inbound traffic
     * without one; the server's own ufw firewall still applies on top.
     */
    private function securityGroupId(Ec2Client $client, string $region): string
    {
        $vpcs = $this->call('find default VPC', fn () => $client->describeVpcs([
            'Filters' => [['Name' => 'isDefault', 'Values' => ['true']]],
        ]));

        $vpcId = $vpcs['Vpcs'][0]['VpcId'] ?? null;

        if (! $vpcId) {
            throw new ProviderApiException(
                "Your AWS account has no default VPC in {$region}. Create one in the AWS VPC console (Your VPCs → Actions → Create default VPC) and try again.",
                'aws',
            );
        }

        $groups = $this->call('find security group', fn () => $client->describeSecurityGroups([
            'Filters' => [
                ['Name' => 'group-name', 'Values' => [self::SECURITY_GROUP_NAME]],
                ['Name' => 'vpc-id', 'Values' => [$vpcId]],
            ],
        ]));

        if ($groupId = $groups['SecurityGroups'][0]['GroupId'] ?? null) {
            return $groupId;
        }

        $groupId = $this->call('create security group', fn () => $client->createSecurityGroup([
            'GroupName' => self::SECURITY_GROUP_NAME,
            'Description' => 'Servers managed by Flitops: SSH, HTTP and HTTPS',
            'VpcId' => $vpcId,
        ]))['GroupId'];

        $anywhere = [
            'IpRanges' => [['CidrIp' => '0.0.0.0/0']],
            'Ipv6Ranges' => [['CidrIpv6' => '::/0']],
        ];

        $this->call('open security group ports', fn () => $client->authorizeSecurityGroupIngress([
            'GroupId' => $groupId,
            'IpPermissions' => [
                ['IpProtocol' => 'tcp', 'FromPort' => 22, 'ToPort' => 22, ...$anywhere],
                ['IpProtocol' => 'tcp', 'FromPort' => 80, 'ToPort' => 80, ...$anywhere],
                ['IpProtocol' => 'tcp', 'FromPort' => 443, 'ToPort' => 443, ...$anywhere],
                ['IpProtocol' => 'udp', 'FromPort' => 443, 'ToPort' => 443, ...$anywhere],
            ],
        ]));

        return $groupId;
    }

    /**
     * Attach an Elastic IP to the instance, reusing the one already allocated
     * for it (tagged with its ID) so repeated polls never allocate twice.
     */
    private function ensureElasticIp(Ec2Client $client, string $instanceId): string
    {
        $addresses = $this->call('find Elastic IP', fn () => $client->describeAddresses([
            'Filters' => [['Name' => 'tag:flitops-instance', 'Values' => [$instanceId]]],
        ]));

        $address = $addresses['Addresses'][0] ?? null;

        if (! $address) {
            $address = $this->call('allocate Elastic IP', fn () => $client->allocateAddress([
                'Domain' => 'vpc',
                'TagSpecifications' => [[
                    'ResourceType' => 'elastic-ip',
                    'Tags' => [
                        ['Key' => 'flitops-instance', 'Value' => $instanceId],
                        ['Key' => 'app', 'Value' => 'flitops'],
                    ],
                ]],
            ]))->toArray();
        }

        if (($address['InstanceId'] ?? null) !== $instanceId) {
            $this->call('attach Elastic IP', fn () => $client->associateAddress([
                'AllocationId' => $address['AllocationId'],
                'InstanceId' => $instanceId,
            ]));
        }

        return $address['PublicIp'];
    }

    private function client(string $region): Ec2Client
    {
        return $this->clients[$region] ??= new Ec2Client([
            'region' => $region,
            'version' => 'latest',
            'credentials' => $this->credentials,
            'retries' => 3,
            'http' => ['timeout' => 30],
            ...($this->handler ? ['handler' => $this->handler] : []),
        ]);
    }

    /**
     * Split a region-prefixed ID ("eu-west-2:i-0abc…") into region and ID.
     *
     * @return array{0: string, 1: string}
     */
    private function parseId(string $id): array
    {
        $parts = explode(':', $id, 2);

        if (count($parts) !== 2) {
            throw new ProviderApiException("Invalid AWS resource ID: {$id}", 'aws');
        }

        return $parts;
    }

    /**
     * Run an EC2 call, turning SDK errors into ProviderApiException.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function call(string $action, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (AwsException $e) {
            throw $this->toProviderException($action, $e);
        }
    }

    private function toProviderException(string $action, AwsException $e): ProviderApiException
    {
        return new ProviderApiException(
            "Failed to {$action}: ".($e->getAwsErrorMessage() ?: $e->getMessage()),
            'aws',
            $e->getStatusCode(),
        );
    }
}

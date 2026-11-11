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
use Aws\Pricing\PricingClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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

    /**
     * The official Ubuntu 24.04 image for each processor architecture.
     *
     * @var array<string, array{name: string, architecture: string}>
     */
    private const array UBUNTU_IMAGES = [
        'x86' => ['name' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*', 'architecture' => 'x86_64'],
        'arm' => ['name' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-arm64-server-*', 'architecture' => 'arm64'],
    ];

    /**
     * Who makes the processors of each architecture's instance types below.
     */
    private const array CPU_VENDORS = ['x86' => 'Intel', 'arm' => 'AWS Graviton'];

    private const string SECURITY_GROUP_NAME = 'flitops';

    private const int ROOT_VOLUME_GB = 30;

    /**
     * The us-east-1 price of the gp3 root volume per GB a month, used when
     * AWS's real prices can't be read.
     */
    private const float ESTIMATED_DISK_PRICE_PER_GB = 0.08;

    /**
     * The monthly price of the Elastic IP ($0.005/hour), which AWS charges
     * the same in every region.
     */
    private const float PUBLIC_IP_PRICE_MONTHLY = 3.65;

    /**
     * The hours AWS counts in a month when it quotes a monthly price.
     */
    private const int HOURS_PER_MONTH = 730;

    /**
     * AWS only answers price questions from a few regions; the answer covers
     * every region.
     */
    private const string PRICING_API_REGION = 'us-east-1';

    private const string PRICES_CACHE_KEY = 'aws:ec2-prices:v1';

    /**
     * Instance types offered when creating a server. The price is the
     * us-east-1 on-demand Linux price per month (730 hours); it is only a
     * guide, shown when AWS's real per-region prices can't be read.
     *
     * @var array<string, array{vcpus: int, memory: int, price: float, family: string, architecture: string}>
     */
    private const array INSTANCE_TYPES = [
        't4g.small' => ['vcpus' => 2, 'memory' => 2048, 'price' => 12.26, 'family' => 'General purpose, burstable', 'architecture' => 'arm'],
        't3.small' => ['vcpus' => 2, 'memory' => 2048, 'price' => 15.18, 'family' => 'General purpose, burstable', 'architecture' => 'x86'],
        't4g.medium' => ['vcpus' => 2, 'memory' => 4096, 'price' => 24.53, 'family' => 'General purpose, burstable', 'architecture' => 'arm'],
        't3.medium' => ['vcpus' => 2, 'memory' => 4096, 'price' => 30.37, 'family' => 'General purpose, burstable', 'architecture' => 'x86'],
        't4g.large' => ['vcpus' => 2, 'memory' => 8192, 'price' => 49.06, 'family' => 'General purpose, burstable', 'architecture' => 'arm'],
        'c7g.large' => ['vcpus' => 2, 'memory' => 4096, 'price' => 52.93, 'family' => 'Compute optimized', 'architecture' => 'arm'],
        'm7g.large' => ['vcpus' => 2, 'memory' => 8192, 'price' => 59.57, 'family' => 'General purpose', 'architecture' => 'arm'],
        't3.large' => ['vcpus' => 2, 'memory' => 8192, 'price' => 60.74, 'family' => 'General purpose, burstable', 'architecture' => 'x86'],
        'c6i.large' => ['vcpus' => 2, 'memory' => 4096, 'price' => 62.05, 'family' => 'Compute optimized', 'architecture' => 'x86'],
        'm6i.large' => ['vcpus' => 2, 'memory' => 8192, 'price' => 70.08, 'family' => 'General purpose', 'architecture' => 'x86'],
        'r7g.large' => ['vcpus' => 2, 'memory' => 16384, 'price' => 78.18, 'family' => 'Memory optimized', 'architecture' => 'arm'],
        'r6i.large' => ['vcpus' => 2, 'memory' => 16384, 'price' => 91.98, 'family' => 'Memory optimized', 'architecture' => 'x86'],
        't4g.xlarge' => ['vcpus' => 4, 'memory' => 16384, 'price' => 98.11, 'family' => 'General purpose, burstable', 'architecture' => 'arm'],
        'c7g.xlarge' => ['vcpus' => 4, 'memory' => 8192, 'price' => 105.85, 'family' => 'Compute optimized', 'architecture' => 'arm'],
        'm7g.xlarge' => ['vcpus' => 4, 'memory' => 16384, 'price' => 119.14, 'family' => 'General purpose', 'architecture' => 'arm'],
        't3.xlarge' => ['vcpus' => 4, 'memory' => 16384, 'price' => 121.47, 'family' => 'General purpose, burstable', 'architecture' => 'x86'],
        'c6i.xlarge' => ['vcpus' => 4, 'memory' => 8192, 'price' => 124.10, 'family' => 'Compute optimized', 'architecture' => 'x86'],
        'm6i.xlarge' => ['vcpus' => 4, 'memory' => 16384, 'price' => 140.16, 'family' => 'General purpose', 'architecture' => 'x86'],
        't4g.2xlarge' => ['vcpus' => 8, 'memory' => 32768, 'price' => 196.22, 'family' => 'General purpose, burstable', 'architecture' => 'arm'],
        't3.2xlarge' => ['vcpus' => 8, 'memory' => 32768, 'price' => 242.94, 'family' => 'General purpose, burstable', 'architecture' => 'x86'],
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

    /**
     * Each region carries what AWS bills there on top of the instance: the
     * root volume, priced per region, and the fixed public IP.
     */
    public function getRegions(): Collection
    {
        $result = $this->call('fetch regions', fn () => $this->client('us-east-1')->describeRegions());

        $diskPrices = $this->prices()['disk'] ?? [];

        return collect($result['Regions'])
            ->map(fn ($r) => new ProviderRegion(
                slug: $r['RegionName'],
                name: (self::REGION_NAMES[$r['RegionName']] ?? $r['RegionName']).' ('.$r['RegionName'].')',
                extraCharges: [
                    [
                        'label' => self::ROOT_VOLUME_GB.' GB disk (gp3)',
                        'price_monthly' => round(self::ROOT_VOLUME_GB * ($diskPrices[$r['RegionName']] ?? self::ESTIMATED_DISK_PRICE_PER_GB), 2),
                    ],
                    ['label' => 'Public IPv4 address', 'price_monthly' => self::PUBLIC_IP_PRICE_MONTHLY],
                ],
            ))
            ->sortBy('slug')
            ->values();
    }

    /**
     * Each size carries the regions AWS sells it in and its real price in
     * each. When the account may not read AWS's prices, every size is offered
     * everywhere at its us-east-1 price and marked as an estimate.
     */
    public function getSizes(): Collection
    {
        $prices = $this->prices();

        return collect(self::INSTANCE_TYPES)
            ->map(function ($type, $slug) use ($prices) {
                $regionOverrides = collect($prices['instances'][$slug] ?? [])
                    ->map(fn (float $hourly) => [
                        'price_monthly' => round($hourly * self::HOURS_PER_MONTH, 2),
                        'price_hourly' => $hourly,
                    ]);

                $cheapest = $regionOverrides->sortBy('price_monthly')->first();

                return new ProviderSize(
                    slug: $slug,
                    vcpus: $type['vcpus'],
                    memory: $type['memory'],
                    disk: self::ROOT_VOLUME_GB,
                    priceMonthly: $cheapest['price_monthly'] ?? $type['price'],
                    category: $type['family'],
                    regions: $prices === null ? null : $regionOverrides->keys()->all(),
                    regionOverrides: $regionOverrides->all(),
                    priceHourly: $cheapest['price_hourly'] ?? round($type['price'] / self::HOURS_PER_MONTH, 4),
                    architecture: $type['architecture'],
                    cpuVendor: self::CPU_VENDORS[$type['architecture']],
                    diskType: 'gp3 SSD',
                    priceIsEstimate: $prices === null,
                );
            })
            ->filter(fn (ProviderSize $size) => $size->regions !== [])
            ->sortBy('priceMonthly')
            ->values();
    }

    /**
     * AWS's published on-demand prices: the hourly price of each instance
     * type and the monthly price per GB of a gp3 disk, both per region.
     *
     * The prices are the same for every AWS customer, so one answer is kept
     * for a day and shared by all accounts. Null means this account couldn't
     * read them (its IAM policy lacks pricing:GetProducts, or AWS failed);
     * that isn't kept, so the next request tries again.
     *
     * @return array{instances: array<string, array<string, float>>, disk: array<string, float>}|null
     */
    private function prices(): ?array
    {
        if ($prices = Cache::get(self::PRICES_CACHE_KEY)) {
            return $prices;
        }

        try {
            $prices = [
                'instances' => $this->instancePrices(),
                'disk' => $this->diskPrices(),
            ];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'AccessDeniedException') {
                report($e);
            }

            return null;
        }

        if ($prices['instances'] === []) {
            return null;
        }

        Cache::put(self::PRICES_CACHE_KEY, $prices, now()->addDay());

        return $prices;
    }

    /**
     * The on-demand hourly price of each offered instance type running
     * Linux, keyed by instance type and then region.
     *
     * @return array<string, array<string, float>>
     */
    private function instancePrices(): array
    {
        $products = $this->pricedProducts([
            'instanceType' => implode(',', array_keys(self::INSTANCE_TYPES)),
            'operatingSystem' => 'Linux',
            'tenancy' => 'Shared',
            'preInstalledSw' => 'NA',
            'capacitystatus' => 'Used',
            'locationType' => 'AWS Region',
        ]);

        $prices = [];

        foreach ($products as $product) {
            $prices[$product['attributes']['instanceType']][$product['attributes']['regionCode']] = $product['price'];
        }

        return $prices;
    }

    /**
     * The monthly price per GB of a gp3 disk, keyed by region.
     *
     * @return array<string, float>
     */
    private function diskPrices(): array
    {
        $products = $this->pricedProducts([
            'productFamily' => 'Storage',
            'volumeApiName' => 'gp3',
            'locationType' => 'AWS Region',
        ]);

        return array_column(
            array_map(fn (array $product) => ['region' => $product['attributes']['regionCode'], 'price' => $product['price']], $products),
            'price',
            'region',
        );
    }

    /**
     * Ask AWS's price list for the EC2 products matching every filter, with
     * the on-demand price of each. Products AWS lists without a price are
     * left out.
     *
     * @param  array<string, string>  $filters  Attribute name => value. Several values separated by commas match any of them.
     * @return list<array{attributes: array<string, string>, price: float}>
     */
    private function pricedProducts(array $filters): array
    {
        $pages = $this->pricingClient()->getPaginator('GetProducts', [
            'ServiceCode' => 'AmazonEC2',
            'MaxResults' => 100,
            'Filters' => collect($filters)
                ->map(fn (string $value, string $field) => [
                    'Type' => str_contains($value, ',') ? 'ANY_OF' : 'TERM_MATCH',
                    'Field' => $field,
                    'Value' => $value,
                ])
                ->values()
                ->all(),
        ]);

        $products = [];

        foreach ($pages as $page) {
            foreach ($page['PriceList'] ?? [] as $item) {
                // Each item arrives as a JSON document of its own.
                $item = is_string($item) ? json_decode($item, true) : $item;

                $offer = collect($item['terms']['OnDemand'] ?? [])->first();
                $price = collect($offer['priceDimensions'] ?? [])->first()['pricePerUnit']['USD'] ?? null;

                if ((float) $price > 0) {
                    $products[] = ['attributes' => $item['product']['attributes'], 'price' => (float) $price];
                }
            }
        }

        return $products;
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
            'ImageId' => $this->ubuntuImageId($client, self::INSTANCE_TYPES[$size]['architecture'] ?? 'x86'),
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
     * Find the newest official Ubuntu 24.04 image in the client's region for
     * the given processor architecture ("x86" or "arm").
     */
    private function ubuntuImageId(Ec2Client $client, string $architecture): string
    {
        $ubuntu = self::UBUNTU_IMAGES[$architecture];

        $result = $this->call('find Ubuntu image', fn () => $client->describeImages([
            'Owners' => [self::CANONICAL_OWNER_ID],
            'Filters' => [
                ['Name' => 'name', 'Values' => [$ubuntu['name']]],
                ['Name' => 'architecture', 'Values' => [$ubuntu['architecture']]],
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

    private function pricingClient(): PricingClient
    {
        return new PricingClient([
            'region' => self::PRICING_API_REGION,
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

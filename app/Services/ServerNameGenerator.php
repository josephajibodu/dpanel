<?php

namespace App\Services;

class ServerNameGenerator
{
    /**
     * @var list<string>
     */
    private array $adjectives = [
        'amber', 'ancient', 'autumn', 'azure', 'bold', 'brave', 'bright', 'calm',
        'clever', 'cobalt', 'coral', 'cosmic', 'crimson', 'dapper', 'dazzling', 'eager',
        'electric', 'emerald', 'fierce', 'frosty', 'gentle', 'gilded', 'golden', 'happy',
        'hidden', 'humble', 'icy', 'ivory', 'jolly', 'keen', 'lively', 'lucky',
        'lunar', 'mellow', 'mighty', 'misty', 'noble', 'polished', 'proud', 'quiet',
        'radiant', 'rapid', 'royal', 'rustic', 'sapphire', 'scarlet', 'serene', 'sharp',
        'silent', 'silver', 'sleek', 'smooth', 'solar', 'stellar', 'sturdy', 'swift',
        'tidal', 'vivid', 'wandering', 'whispering', 'wild', 'wise', 'zesty',
    ];

    /**
     * @var list<string>
     */
    private array $nouns = [
        'aurora', 'badger', 'bay', 'beacon', 'birch', 'bison', 'blossom', 'breeze',
        'brook', 'canyon', 'cascade', 'cedar', 'cliff', 'cloud', 'comet', 'cove',
        'crater', 'creek', 'delta', 'dune', 'ember', 'falcon', 'fjord', 'forest',
        'fox', 'galaxy', 'glacier', 'glade', 'grove', 'harbor', 'hawk', 'heron',
        'horizon', 'island', 'juniper', 'lagoon', 'lake', 'lynx', 'maple', 'meadow',
        'mesa', 'meteor', 'mountain', 'nebula', 'oak', 'ocean', 'orchard', 'otter',
        'panther', 'peak', 'phoenix', 'pine', 'prairie', 'quartz', 'rapids', 'raven',
        'reef', 'ridge', 'river', 'savanna', 'sequoia', 'sparrow', 'spruce', 'stone',
        'summit', 'thunder', 'tundra', 'valley', 'willow', 'zenith',
    ];

    /**
     * Generate a random, hostname-safe server name (e.g. "bold-cloud").
     */
    public function generate(): string
    {
        $adjective = $this->adjectives[array_rand($this->adjectives)];
        $noun = $this->nouns[array_rand($this->nouns)];

        return "{$adjective}-{$noun}";
    }
}

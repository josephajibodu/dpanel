<?php

namespace App\Services\Ssh;

use App\Data\KeyPair;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

class KeyGenerator
{
    /**
     * Generate an Ed25519 keypair for server authentication.
     * Ed25519 is preferred for:
     * - Smaller key size (256-bit vs 4096-bit RSA)
     * - Faster operations
     * - Better security properties
     */
    public function generate(): KeyPair
    {
        $privateKey = EC::createKey('Ed25519');

        return new KeyPair(
            privateKey: $privateKey->toString('OpenSSH'),
            publicKey: $privateKey->getPublicKey()->toString('OpenSSH'),
        );
    }

    /**
     * Generate RSA keypair for legacy compatibility.
     */
    public function generateRsa(int $bits = 4096): KeyPair
    {
        $privateKey = RSA::createKey($bits);

        return new KeyPair(
            privateKey: $privateKey->toString('OpenSSH'),
            publicKey: $privateKey->getPublicKey()->toString('OpenSSH'),
        );
    }
}

<?php

use App\Services\Ssh\KeyGenerator;

describe('KeyGenerator', function () {
    it('generates an Ed25519 keypair', function () {
        $generator = new KeyGenerator;
        $keyPair = $generator->generate();

        expect($keyPair->privateKey)->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----');
        expect($keyPair->publicKey)->toStartWith('ssh-ed25519 ');
    });

    it('generates unique keypairs each time', function () {
        $generator = new KeyGenerator;

        $keyPair1 = $generator->generate();
        $keyPair2 = $generator->generate();

        expect($keyPair1->privateKey)->not->toBe($keyPair2->privateKey);
        expect($keyPair1->publicKey)->not->toBe($keyPair2->publicKey);
    });

    it('generates an RSA keypair', function () {
        $generator = new KeyGenerator;
        $keyPair = $generator->generateRsa(2048);

        expect($keyPair->privateKey)->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----');
        expect($keyPair->publicKey)->toStartWith('ssh-rsa ');
    });
});

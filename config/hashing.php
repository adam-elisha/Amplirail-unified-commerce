<?php

declare(strict_types=1);

// SECURITY.md: passwords hashed with Argon2id.
return [
    'driver' => 'argon2id',
    'bcrypt' => ['rounds' => (int) env('BCRYPT_ROUNDS', 12), 'verify' => true],
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],
    'rehash_on_login' => true,
];

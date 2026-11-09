<?php

namespace App\Services;

class TemporaryPasswordService
{
    /**
     * @var list<string>
     */
    private const FRUITS = [
        'apel',
        'durian',
        'jambu',
        'jeruk',
        'kelapa',
        'mangga',
        'melon',
        'pepaya',
        'pisang',
        'salak',
        'semangka',
        'strawberi',
    ];

    public function generate(): string
    {
        $fruit = self::FRUITS[random_int(0, count(self::FRUITS) - 1)];

        return ucfirst($fruit).random_int(1000, 9999);
    }
}

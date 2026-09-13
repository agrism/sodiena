<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

if (!function_exists('mb_split')) {
    function mb_split(string $pattern, string $string, int $limit = -1): array|false {
        return preg_split('/' . $pattern . '/u', $string, $limit);
    }
}

abstract class TestCase extends BaseTestCase
{
    //
}


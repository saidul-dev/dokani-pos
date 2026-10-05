<?php

namespace App\Support;

/**
 * Amount formatting for the Daily Book screens. Shop owners deal almost
 * entirely in whole taka, so "52,955.00" is shown as "52,955" — decimals
 * appear only when the amount actually has paisa (e.g. "120.50").
 */
class Money
{
    public static function format(float|int|string|null $amount): string
    {
        $amount = round((float) $amount, 2);

        return number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2);
    }
}

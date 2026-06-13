<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;

enum PlanInterval: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    public function interval(int $count = 1): CarbonInterval
    {
        return match ($this) {
            self::Monthly => CarbonInterval::months($count),
            self::Quarterly => CarbonInterval::months(3 * $count),
            self::Annual => CarbonInterval::years($count),
        };
    }

    public function advance(CarbonImmutable $start, int $count = 1): CarbonImmutable
    {
        return $start->add($this->interval($count));
    }
}

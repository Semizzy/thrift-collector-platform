<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Country\CountryConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CountryConfigurationTest extends TestCase
{
    public function test_nigeria_has_explicit_ngn_configuration(): void
    {
        $country = CountryConfiguration::forCode('ng');

        self::assertSame('NG', $country->code);
        self::assertSame('NGN', $country->currency);
        self::assertSame('Africa/Lagos', $country->timezone);
        self::assertSame(2, $country->minorUnitDigits);
    }

    public function test_ghana_has_explicit_ghs_configuration(): void
    {
        $country = CountryConfiguration::forCode('GH');

        self::assertSame('GH', $country->code);
        self::assertSame('GHS', $country->currency);
        self::assertSame('Africa/Accra', $country->timezone);
    }

    public function test_only_initial_release_countries_are_enabled(): void
    {
        self::assertSame(['NG', 'GH'], array_map(
            static fn (CountryConfiguration $country): string => $country->code,
            CountryConfiguration::supported(),
        ));
    }

    public function test_unknown_country_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CountryConfiguration::forCode('US');
    }
}

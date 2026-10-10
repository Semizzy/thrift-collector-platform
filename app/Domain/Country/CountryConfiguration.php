<?php

declare(strict_types=1);

namespace App\Domain\Country;

use InvalidArgumentException;

final readonly class CountryConfiguration
{
    private function __construct(
        public string $code,
        public string $name,
        public string $currency,
        public int $minorUnitDigits,
        public string $locale,
        public string $timezone,
    ) {
    }

    public static function forCode(string $code): self
    {
        return match (strtoupper(trim($code))) {
            'NG' => new self('NG', 'Nigeria', 'NGN', 2, 'en_NG', 'Africa/Lagos'),
            'GH' => new self('GH', 'Ghana', 'GHS', 2, 'en_GH', 'Africa/Accra'),
            default => throw new InvalidArgumentException('Country is not enabled for the initial release.'),
        };
    }

    /** @return list<self> */
    public static function supported(): array
    {
        return [self::forCode('NG'), self::forCode('GH')];
    }

    /** @return array{code:string,name:string,currency:string,minor_unit_digits:int,locale:string,timezone:string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'currency' => $this->currency,
            'minor_unit_digits' => $this->minorUnitDigits,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
        ];
    }
}

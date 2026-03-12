<?php

declare(strict_types=1);

class BrandConfig
{
    public const BRAND_HYUNDAI = 'Hyundai';
    public const BRAND_KIA = 'Kia';

    private const CONFIGS = [
        self::BRAND_HYUNDAI => [
            'baseUrl'    => 'https://prd.eu-ccapi.hyundai.com:8080',
            'clientId'   => '6d477c38-3ca4-4cf3-9557-2a1929a94654',
            'appId'      => '014d2225-8495-4735-812d-2616334fd15d',
            'cfbKey'     => 'RFtoRq/vDXJmRndoZaZQyfOot7OrIqGVFj96iY2WL3yyH5Z/pUvlUhqmCxD2t+D65SQ=',
            'basicToken' => 'Basic NmQ0NzdjMzgtM2NhNC00Y2YzLTk1NTctMmExOTI5YTk0NjU0OktVeTQ5WHhQekxwTHVvSzB4aEJDNzdXNlZYaG10UVI5aVFobUlGampvWTRJcHhzVg==',
            'pushType'   => 'GCM',
            'host'       => 'prd.eu-ccapi.hyundai.com:8080',
        ],
        self::BRAND_KIA => [
            'baseUrl'    => 'https://prd.eu-ccapi.kia.com:8080',
            'clientId'   => 'fdc85c00-0a2f-4c64-bcb4-2cfb1500730a',
            'appId'      => 'a2b8469b-30a3-4361-8e13-6fceea8fbe74',
            'cfbKey'     => 'wLTVxwidmH8CfJYBWSnHD6E0huk0ozdiuygB4hLkM5XCgzAL1Dk5sE36d/bx5PFMbZs=',
            'basicToken' => 'Basic ZmRjODVjMDAtMGEyZi00YzY0LWJjYjQtMmNmYjE1MDA3MzBhOnNlY3JldA==',
            'pushType'   => 'APNS',
            'host'       => 'prd.eu-ccapi.kia.com:8080',
        ],
    ];

    public static function get(string $brand): array
    {
        return self::CONFIGS[$brand] ?? self::CONFIGS[self::BRAND_HYUNDAI];
    }

    public static function getBrands(): array
    {
        return [self::BRAND_HYUNDAI, self::BRAND_KIA];
    }
}

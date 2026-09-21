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
            'oauthRedirectUri'        => 'https://prd.eu-ccapi.hyundai.com:8080/api/v1/user/oauth2/token',
            'loginFormHost'           => 'https://idpconnect-eu.hyundai.com',
            'oneAppClientId'          => '4f4953b5-02e1-4dbc-8599-87e983ee1be5',
            'oneAppRedirectUri'       => 'https://oneapp.hyundai.com/redirect',
            'cciApiUrl'               => 'https://cci-api-eu.hyundai.com',
            'cciPackageId'            => 'com.hyundai.oneapp.eu',
            'cciClientName'           => 'hyundai',
            'cciClientOsVersion'      => '18.7',
            'cciNotificationProvider' => 'APNS',
        ],
        self::BRAND_KIA => [
            'baseUrl'    => 'https://prd.eu-ccapi.kia.com:8080',
            'clientId'   => 'fdc85c00-0a2f-4c64-bcb4-2cfb1500730a',
            'appId'      => 'a2b8469b-30a3-4361-8e13-6fceea8fbe74',
            'cfbKey'     => 'wLTVxwidmH8CfJYBWSnHD6E0huk0ozdiuygB4hLkM5XCgzAL1Dk5sE36d/bx5PFMbZs=',
            'basicToken' => 'Basic ZmRjODVjMDAtMGEyZi00YzY0LWJjYjQtMmNmYjE1MDA3MzBhOnNlY3JldA==',
            'pushType'   => 'APNS',
            'host'       => 'prd.eu-ccapi.kia.com:8080',
            'oauthRedirectUri'        => 'https://prd.eu-ccapi.kia.com:8080/api/v1/user/oauth2/redirect',
            'loginFormHost'           => 'https://idpconnect-eu.kia.com',
            'oneAppClientId'          => '01b36c86-79e8-486c-8009-15f2ad88d670',
            'oneAppRedirectUri'       => 'https://oneapp.kia.com/redirect',
            'cciApiUrl'               => 'https://cci-api-eu.kia.com',
            'cciPackageId'            => 'com.kia.oneapp.eu',
            'cciClientName'           => 'kia',
            'cciClientOsVersion'      => '27',
            'cciNotificationProvider' => 'IOS_APPSTORE',
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

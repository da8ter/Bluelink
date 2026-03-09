<?php

declare(strict_types=1);

class BluelinkStampService
{
    // Hyundai EU CFB key (from hyundai_kia_connect_api Python project)
    private const HYUNDAI_CFB_B64 = 'RFtoRq/vDXJmRndoZaZQyfOot7OrIqGVFj96iY2WL3yyH5Z/pUvlUhqmCxD2t+D65SQ=';
    private const APP_ID = '014d2225-8495-4735-812d-2616334fd15d';

    private string $cfb;

    public function __construct(string $stampUrl = '')
    {
        $this->cfb = base64_decode(self::HYUNDAI_CFB_B64);
    }

    /**
     * Generate a unique stamp per request using local CFB XOR.
     * Algorithm: base64(XOR(appId:timestampSeconds, cfbKey))
     * Uses seconds (not ms) and min-length XOR like Python's zip().
     */
    public function getStamp(): string
    {
        $timestamp = (string) time();
        $rawData = self::APP_ID . ':' . $timestamp;

        $len = min(strlen($rawData), strlen($this->cfb));
        $result = '';
        for ($i = 0; $i < $len; $i++) {
            $result .= chr(ord($rawData[$i]) ^ ord($this->cfb[$i]));
        }

        return base64_encode($result);
    }

    // Keep interface for backwards compatibility (no-ops)
    public function loadFromCache(string $json): void {}
    public function getCacheData(): string { return '{}'; }
}

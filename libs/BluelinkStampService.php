<?php

declare(strict_types=1);

class BluelinkStampService
{
    private string $cfb;
    private string $appId;

    public function __construct(string $cfbKeyBase64 = '', string $appId = '')
    {
        $defaultCfb = 'RFtoRq/vDXJmRndoZaZQyfOot7OrIqGVFj96iY2WL3yyH5Z/pUvlUhqmCxD2t+D65SQ=';
        $defaultAppId = '014d2225-8495-4735-812d-2616334fd15d';
        $this->cfb = base64_decode(!empty($cfbKeyBase64) ? $cfbKeyBase64 : $defaultCfb);
        $this->appId = !empty($appId) ? $appId : $defaultAppId;
    }

    /**
     * Generate a unique stamp per request using local CFB XOR.
     * Algorithm: base64(XOR(appId:timestampSeconds, cfbKey))
     * Uses seconds (not ms) and min-length XOR like Python's zip().
     */
    public function getStamp(): string
    {
        $timestamp = (string) time();
        $rawData = $this->appId . ':' . $timestamp;

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

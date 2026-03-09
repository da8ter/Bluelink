<?php

declare(strict_types=1);

class CommandService
{
    private const COMMAND_TIMEOUT = 120; // seconds
    private const POLL_INTERVAL = 5; // seconds between status checks
    private const MAX_POLLS = 24; // 120s / 5s

    private BluelinkApiClient $apiClient;

    // Active command tracking
    private string $activeCommandId = '';
    private string $activeCommandType = '';
    private int $commandStartTime = 0;
    private string $commandStatus = ''; // pending, success, fail, timeout

    public function __construct(BluelinkApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * Execute a remote command and return immediately with command info
     */
    public function executeCommand(string $vehicleId, string $commandType, callable $apiCall): array
    {
        if ($this->isCommandActive()) {
            return [
                'success' => false,
                'error'   => 'Another command is still active: ' . $this->activeCommandType,
                'status'  => 'blocked',
            ];
        }

        try {
            $result = $apiCall();
            $commandId = $result['transactionId'] ?? $result['msgId'] ?? '';

            $this->activeCommandId = $commandId;
            $this->activeCommandType = $commandType;
            $this->commandStartTime = time();
            $this->commandStatus = 'pending';

            return [
                'success'   => true,
                'commandId' => $commandId,
                'type'      => $commandType,
                'status'    => 'pending',
                'startTime' => $this->commandStartTime,
            ];
        } catch (Exception $e) {
            $this->clearCommand();
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'type'    => $commandType,
                'status'  => 'fail',
            ];
        }
    }

    /**
     * Check status of active command
     */
    public function pollCommandStatus(string $vehicleId): array
    {
        if (!$this->isCommandActive()) {
            return [
                'active' => false,
                'status' => $this->commandStatus,
                'type'   => $this->activeCommandType,
            ];
        }

        // Check timeout
        if ((time() - $this->commandStartTime) > self::COMMAND_TIMEOUT) {
            $this->commandStatus = 'timeout';
            $type = $this->activeCommandType;
            $this->clearCommand();
            return [
                'active' => false,
                'status' => 'timeout',
                'type'   => $type,
            ];
        }

        try {
            $result = $this->apiClient->getCommandStatus($vehicleId, $this->activeCommandId);
            $status = strtolower($result['status'] ?? $result['result'] ?? 'pending');

            if (in_array($status, ['success', 'completed', 'complete'])) {
                $this->commandStatus = 'success';
                $type = $this->activeCommandType;
                $this->clearCommand();
                return [
                    'active' => false,
                    'status' => 'success',
                    'type'   => $type,
                ];
            }

            if (in_array($status, ['fail', 'failed', 'error'])) {
                $this->commandStatus = 'fail';
                $type = $this->activeCommandType;
                $errorMsg = $result['message'] ?? 'Command failed';
                $this->clearCommand();
                return [
                    'active' => false,
                    'status' => 'fail',
                    'type'   => $type,
                    'error'  => $errorMsg,
                ];
            }

            return [
                'active'    => true,
                'status'    => 'pending',
                'type'      => $this->activeCommandType,
                'elapsed'   => time() - $this->commandStartTime,
                'commandId' => $this->activeCommandId,
            ];
        } catch (Exception $e) {
            return [
                'active'    => true,
                'status'    => 'pending',
                'type'      => $this->activeCommandType,
                'elapsed'   => time() - $this->commandStartTime,
                'pollError' => $e->getMessage(),
            ];
        }
    }

    public function isCommandActive(): bool
    {
        if (empty($this->activeCommandId)) {
            return false;
        }
        if ((time() - $this->commandStartTime) > self::COMMAND_TIMEOUT) {
            $this->commandStatus = 'timeout';
            $this->clearCommand();
            return false;
        }
        return true;
    }

    public function getLastCommandStatus(): string
    {
        return $this->commandStatus;
    }

    public function getLastCommandType(): string
    {
        return $this->activeCommandType;
    }

    public function loadFromCache(string $json): void
    {
        if (empty($json)) {
            return;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return;
        }
        $this->activeCommandId = $data['commandId'] ?? '';
        $this->activeCommandType = $data['commandType'] ?? '';
        $this->commandStartTime = $data['startTime'] ?? 0;
        $this->commandStatus = $data['status'] ?? '';
    }

    public function getCacheData(): string
    {
        return json_encode([
            'commandId'   => $this->activeCommandId,
            'commandType' => $this->activeCommandType,
            'startTime'   => $this->commandStartTime,
            'status'      => $this->commandStatus,
        ]);
    }

    private function clearCommand(): void
    {
        $this->activeCommandId = '';
        $this->commandStartTime = 0;
    }
}

<?php

namespace App\Plugins\ProofaSyncBundle\Command;

use App\Plugins\ProofaSyncBundle\Service\TenantAwareSyncDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sync:consume', description: 'Consume sync events from Redis Stream')]
class SyncConsumeCommand extends Command
{
    private const STREAM = 'affine-sync-events';
    private const GROUP = 'kimai-sync-workers';
    private const CONSUMER = 'worker-1';
    private const BLOCK_MS = 5000;

    public function __construct(
        private readonly \Predis\ClientInterface $redis,
        private readonly TenantAwareSyncDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Sync consumer started', ['stream' => self::STREAM, 'group' => self::GROUP]);

        // Ensure group exists
        try {
            $this->redis->executeRaw(['XGROUP', 'CREATE', self::STREAM, self::GROUP, '$', 'MKSTREAM']);
        } catch (\Exception $e) {
            // Group might already exist, ignore
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                $this->logger->error('Failed to create consumer group', ['error' => $e->getMessage()]);
            }
        }

        while (true) {
            try {
                $messages = $this->redis->executeRaw([
                    'XREADGROUP', 'GROUP', self::GROUP, self::CONSUMER,
                    'COUNT', '10',
                    'BLOCK', (string) self::BLOCK_MS,
                    'STREAMS', self::STREAM, '>'
                ]);

                if ($messages) {
                    $this->logger->debug('Received messages', ['count' => count($messages)]);
                }

                if (!$messages) {
                    continue;
                }

                foreach ($messages as $streamData) {
                    if (!is_array($streamData) || count($streamData) < 2) {
                        continue;
                    }
                    
                    $streamMessages = $streamData[1];

                    foreach ($streamMessages as $messageData) {
                        $messageId = $messageData[0];
                        $fields = $this->parseFields($messageData[1]);

                        $eventJson = $fields['event'] ?? null;
                        if (!$eventJson) {
                            $this->logger->warning('Empty event in stream', ['message_id' => $messageId]);
                            $this->redis->executeRaw(['XACK', self::STREAM, self::GROUP, $messageId]);
                            continue;
                        }

                        $eventData = json_decode($eventJson, true);
                        $correlationId = $eventData['correlation_id'] ?? 'unknown';

                        try {
                            $this->dispatcher->dispatch($eventData);
                            $this->redis->executeRaw(['XACK', self::STREAM, self::GROUP, $messageId]);
                        } catch (\Throwable $e) {
                            $this->logger->error('Failed to process sync event', [
                                'message_id' => $messageId,
                                'correlation_id' => $correlationId,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                            // Do not ACK - event will go to PEL for retry
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Redis connection error', ['error' => $e->getMessage()]);
                sleep(5); // backoff before reconnecting
            }
        }

        return Command::SUCCESS;
    }

    private function parseFields(array $rawFields): array
    {
        // Predis returns fields as a flat array: ['event', '{"..."}', 'key2', 'val2']
        $fields = [];
        for ($i = 0; $i < count($rawFields); $i += 2) {
            $fields[$rawFields[$i]] = $rawFields[$i + 1] ?? null;
        }
        return $fields;
    }
}

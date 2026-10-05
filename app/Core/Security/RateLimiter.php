<?php

declare(strict_types=1);

namespace IEdify\Core\Security;

use IEdify\Core\Clock\Clock;
use IEdify\Core\Database\Transaction;
use PDO;

final readonly class RateLimiter
{
    public function __construct(private PDO $pdo, private Clock $clock, private string $key)
    {
    }

    public function consume(string $scope, string $subject, int $limit, int $seconds): bool
    {
        if ($limit < 1 || $seconds < 1) {
            throw new \InvalidArgumentException('Rate limits must be positive.');
        }
        $bucket = hash_hmac('sha256', $scope . ':' . $subject, $this->key);
        $window = intdiv($this->clock->now()->getTimestamp(), $seconds) * $seconds;
        return (new Transaction($this->pdo))->run(function () use ($bucket, $window, $limit, $seconds): bool {
            $statement = $this->pdo->prepare('INSERT INTO rate_limit_buckets (bucket_key, window_start, attempts, expires_at) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE attempts = attempts + 1');
            $statement->execute([$bucket, $window, gmdate('Y-m-d H:i:s', $window + 2 * $seconds)]);
            $statement = $this->pdo->prepare('SELECT attempts FROM rate_limit_buckets WHERE bucket_key = ? AND window_start = ?');
            $statement->execute([$bucket, $window]);
            return (int) $statement->fetchColumn() <= $limit;
        });
    }
}

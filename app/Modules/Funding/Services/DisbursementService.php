<?php

declare(strict_types=1);

namespace IEdify\Modules\Funding\Services;

use IEdify\Core\Audit\AuditLog;
use IEdify\Core\Database\Transaction;
use IEdify\Core\Http\HttpError;
use IEdify\Core\Security\Actor;
use IEdify\Core\Security\Policy;
use PDO;

/**
 * Disbursement scheduling, authorization and recording. These are records
 * of money moved outside the system (bank/MoMo happen elsewhere); a future
 * payment adapter can sit behind record(). Totals can never exceed the
 * award: the award row is locked and running totals are re-checked on
 * schedule, authorize and record inside one transaction. The unique
 * reference column blocks double-entering a transfer reference.
 */
final readonly class DisbursementService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function schedule(Actor $actor, int $awardId, string $reference, string $scheduledOn, string $amount, ?string $note): int
    {
        $this->permit($actor, 'disbursement.record');
        if (trim($reference) === '' || !is_numeric($amount) || (float) $amount <= 0 || strtotime($scheduledOn) === false) {
            throw new \InvalidArgumentException('A disbursement needs a reference, a positive amount and a date.');
        }
        return (new Transaction($this->pdo))->run(function () use ($actor, $awardId, $reference, $scheduledOn, $amount, $note): int {
            $award = $this->lockedAward($awardId);
            if ($award['status'] !== 'active') {
                throw new HttpError(409, 'Only active awards can receive disbursements.');
            }
            $this->assertWithinAward($awardId, (string) $award['amount'], $amount);
            try {
                $this->execute('INSERT INTO disbursements (award_id, reference, scheduled_on, amount, currency, status, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$awardId, mb_substr($reference, 0, 120), gmdate('Y-m-d', strtotime($scheduledOn)), $amount, $award['currency'], 'planned', $note !== null ? mb_substr($note, 0, 1000) : null]);
            } catch (\PDOException $error) {
                if (str_contains($error->getMessage(), 'Duplicate')) {
                    throw new HttpError(409, 'This transfer reference already exists.');
                }
                throw $error;
            }
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'disbursement.scheduled', $id);
            return $id;
        });
    }

    public function authorize(Actor $actor, int $disbursementId): void
    {
        $this->permit($actor, 'disbursement.authorize');
        (new Transaction($this->pdo))->run(function () use ($actor, $disbursementId): void {
            $record = $this->locked($disbursementId);
            if ($record['status'] !== 'planned') {
                throw new HttpError(409, 'Only planned disbursements can be authorized.');
            }
            $award = $this->lockedAward((int) $record['award_id']);
            if ($actor->id === (int) $award['awarded_by']) {
                // Separate authority: award authorizer may not authorize the transfer too.
                throw new HttpError(403, 'The award authorizer cannot authorize its disbursements.');
            }
            $this->assertWithinAward((int) $record['award_id'], (string) $award['amount'], '0');
            $this->execute("UPDATE disbursements SET status = 'authorized', authorized_by = ?, authorized_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$actor->id, $disbursementId]);
            $this->audit($actor, 'disbursement.authorized', $disbursementId);
        });
    }

    public function record(Actor $actor, int $disbursementId, ?int $evidenceMediaId, ?string $note): void
    {
        $this->permit($actor, 'disbursement.record');
        (new Transaction($this->pdo))->run(function () use ($actor, $disbursementId, $evidenceMediaId, $note): void {
            $record = $this->locked($disbursementId);
            if ($record['status'] !== 'authorized') {
                throw new HttpError(409, 'Only authorized disbursements can be recorded.');
            }
            if ((int) $record['authorized_by'] === $actor->id) {
                throw new HttpError(403, 'The authorizer cannot record the same disbursement.');
            }
            $award = $this->lockedAward((int) $record['award_id']);
            $this->assertWithinAward((int) $record['award_id'], (string) $award['amount'], '0');
            $this->execute("UPDATE disbursements SET status = 'recorded', recorded_by = ?, recorded_at = UTC_TIMESTAMP(6), evidence_media_id = ?, note = COALESCE(?, note), updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$actor->id, $evidenceMediaId, $note !== null ? mb_substr($note, 0, 1000) : null, $disbursementId]);
            $this->audit($actor, 'disbursement.recorded', $disbursementId);
        });
    }

    public function cancel(Actor $actor, int $disbursementId): void
    {
        $this->permit($actor, 'disbursement.authorize');
        (new Transaction($this->pdo))->run(function () use ($actor, $disbursementId): void {
            $record = $this->locked($disbursementId);
            if ($record['status'] === 'recorded') {
                throw new HttpError(409, 'Recorded disbursements need a correcting entry, not a deletion.');
            }
            $this->execute("UPDATE disbursements SET status = 'cancelled', updated_at = UTC_TIMESTAMP(6) WHERE id = ?", [$disbursementId]);
            $this->audit($actor, 'disbursement.cancelled', $disbursementId);
        });
    }

    /** Total of authorized + recorded must stay within the award; planned counts too at schedule time. */
    private function assertWithinAward(int $awardId, string $awardAmount, string $extra): void
    {
        $statement = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE award_id = ? AND status IN ('planned', 'authorized', 'recorded')");
        $statement->execute([$awardId]);
        $total = bcadd((string) $statement->fetchColumn(), $extra, 2);
        if (bccomp($total, $awardAmount, 2) === 1) {
            throw new HttpError(422, 'Disbursements would exceed the awarded amount.');
        }
    }

    private function lockedAward(int $awardId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM awards WHERE id = ? FOR UPDATE');
        $statement->execute([$awardId]);
        $award = $statement->fetch();
        if ($award === false) {
            throw new HttpError(404, 'Award was not found.');
        }
        return $award;
    }

    private function locked(int $disbursementId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM disbursements WHERE id = ? FOR UPDATE');
        $statement->execute([$disbursementId]);
        $record = $statement->fetch();
        if ($record === false) {
            throw new HttpError(404, 'Disbursement was not found.');
        }
        return $record;
    }

    private function permit(Actor $actor, string $permission): void
    {
        if (!(new Policy())->allows($actor, $permission)) {
            throw new HttpError(403, 'You do not have permission to perform this action.');
        }
    }

    private function audit(Actor $actor, string $action, int $id): void
    {
        (new AuditLog($this->pdo))->record($actor->id, $action, 'disbursement', (string) $id);
    }

    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

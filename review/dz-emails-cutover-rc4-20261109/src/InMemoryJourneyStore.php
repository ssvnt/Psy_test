<?php

declare(strict_types=1);
namespace DobryZhuk\EmailRC3;

/** Synthetic-only event store for deterministic offline contract testing. */
final class InMemoryJourneyStore implements JourneyEventStore
{
    public array $rows=[];
    public function enqueueOnce(array $intent): bool
    {
        $key = $intent['event_key'];
        if (isset($this->rows[$key])) return false;
        $this->rows[$key]=$intent+['status'=>'pending','attempts'=>0];
        return true;
    }
    public function claimDue(int $now): ?array
    {
        foreach ($this->rows as $key=>$row) {
            if ($row['status']==='pending' && $row['scheduled_unix'] <= $now) {
                $this->rows[$key]['status']='sending';
                $this->rows[$key]['attempts']++;
                return $this->rows[$key];
            }
        }
        return null;
    }
    public function mark(string $key,string $status,?int $nextAt=null): void
    {
        if (!isset($this->rows[$key])||$this->rows[$key]['status']!=='sending') throw new \LogicException('NON_CLAIMED_EVENT');
        if (!in_array($status,['accepted_by_mailer','delivery_unknown','suppressed','hold','retry_local','owner_attention'],true)) throw new \LogicException('INVALID_STATUS');
        if ($status==='retry_local') {
            if ($nextAt===null) throw new \LogicException('RETRY_DATE_REQUIRED');
            $this->rows[$key]['status']='pending';
            $this->rows[$key]['scheduled_unix']=$nextAt;
        } else {
            $this->rows[$key]['status']=$status;
        }
    }
}

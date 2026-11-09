<?php

declare(strict_types=1);
namespace App\Console\Commands;

use App\Services\MailJourneys\LaravelJourneyCoordinatorFactory;
use Illuminate\Console\Command;

/** Explicit opt-in. Not auto-registered in production scheduler by this package. */
final class DzMailJourneyDrain extends Command
{
    protected $signature='dz-mail:drain {--send : Explicitly allow mail transport} {--limit=25 : Maximum events in this run}';
    protected $description='Review or drain the new journey outbox after an authorized rollout';
    public function handle(LaravelJourneyCoordinatorFactory $factory): int
    {
        if(!$this->option('send')) {
            $this->info('DRY_RUN_ONLY_NO_EMAILS_SENT');return self::SUCCESS;
        }
        if(config('dz_mail_journeys.enabled')!==true || config('dz_mail_journeys.send_enabled')!==true) {
            $this->error('MAIL_JOURNEY_DISABLED');return self::FAILURE;
        }
        $limit=(int)$this->option('limit');
        if($limit<1 || $limit>100){$this->error('LIMIT_INVALID');return self::FAILURE;}
        $coordinator=$factory->make();
        $counts=[];
        for($i=0;$i<$limit;$i++) {
            $status=$coordinator->dispatchOne(time());
            if($status==='EMPTY')break;
            $counts[$status]=($counts[$status]??0)+1;
        }
        foreach($counts as $status=>$count)$this->line($status.'='. $count);
        return self::SUCCESS;
    }
}

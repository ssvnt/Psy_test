<?php

declare(strict_types=1);
namespace App\Services\MailJourneys;

use App\Services\MailJourneys\Core\MailJourneyCoordinator;

/** Feature flags default OFF. Do not automatically bind legacy handlers twice. */
final class LaravelJourneyCoordinatorFactory
{
    public function make(): MailJourneyCoordinator
    {
        $root=resource_path('mail-journeys/copy-v1.proposed.json');
        if(!is_file($root))throw new \RuntimeException('JOURNEY_COPY_NOT_INSTALLED');
        $copy=json_decode((string)file_get_contents($root),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($copy))throw new \RuntimeException('JOURNEY_COPY_INVALID');
        $enabled=[];
        if(config('dz_mail_journeys.enabled')===true) {
            foreach(['gardner','teen'] as $product){
                $marketing=config('dz_mail_journeys.marketing_'.$product)===true;
                $service=config('dz_mail_journeys.service_'.$product)===true;
                $enabled[$product]=[
                    'test_completed_offer'=>$marketing,
                    'unpaid_12h'=>$marketing && config('dz_mail_journeys.followup_12h')===true,
                    'payment_confirmed'=>$service,
                    'report_ready'=>$service,
                ];
            }
        }
        $host=config('dz_mail_journeys.allowed_mail_host');
        if(!is_string($host)||$host==='') $host='disabled.invalid';
        return new MailJourneyCoordinator(
            app(LaravelJourneyContextProvider::class),
            app(LaravelJourneyEventStore::class),
            app(LaravelJourneyMailer::class),$copy,$host,$enabled
        );
    }
}

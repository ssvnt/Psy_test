<?php
return [
    'enabled'=>filter_var(env('DZ_MAIL_RC4_ENABLED',false),FILTER_VALIDATE_BOOLEAN),
    'send_enabled'=>filter_var(env('DZ_MAIL_RC4_SEND_ENABLED',false),FILTER_VALIDATE_BOOLEAN),
    'rollout_from'=>env('DZ_MAIL_RC4_ROLLOUT_FROM') ?: null,
    'marketing_gardner'=>filter_var(env('DZ_MAIL_RC4_MARKETING_GARDNER',false),FILTER_VALIDATE_BOOLEAN),
    'marketing_teen'=>filter_var(env('DZ_MAIL_RC4_MARKETING_TEEN',false),FILTER_VALIDATE_BOOLEAN),
    'service_gardner'=>filter_var(env('DZ_MAIL_RC4_SERVICE_GARDNER',false),FILTER_VALIDATE_BOOLEAN),
    'service_teen'=>filter_var(env('DZ_MAIL_RC4_SERVICE_TEEN',false),FILTER_VALIDATE_BOOLEAN),
    'followup_12h'=>filter_var(env('DZ_MAIL_RC4_FOLLOWUP_12H',false),FILTER_VALIDATE_BOOLEAN),
    'owner_sla_24h_approved'=>filter_var(env('DZ_MAIL_RC4_SLA_24H_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
    // Must be the validated canonical HTTPS application host for both templates and links.
    'allowed_mail_host'=>env('DZ_MAIL_RC4_ALLOWED_HOST') ?: null,
    // Do not guess teen routes; require verification and explicit configuration.
    'teen_account_base'=>env('DZ_MAIL_RC4_TEEN_ACCOUNT_BASE') ?: null,
];

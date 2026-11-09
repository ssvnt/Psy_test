<?php

declare(strict_types=1);

namespace App\Services\Children\V2\GardnerV22\Recovery;

/** Pure hash- and code-only QA field planner; NO semantic depth scoring. */
final class GardnerRc2QualityPlan
{
    /** @return array{generation_identity:string,field_count:int,failed_count:int,tasks:array,report_ready:bool} */
    public static function fromAudit(array $qa, string $generationIdentity): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$generationIdentity)) throw new \InvalidArgumentException('GENERATION_IDENTITY_INVALID');
        if (!in_array($qa['status']??null,['PASS','FAIL'],true)) throw new \InvalidArgumentException('QUALITY_SCHEMA_INVALID');
        if (($qa['scanners_executed']??null)!==['humanizer-ru','pishi-normalno']) throw new \InvalidArgumentException('QUALITY_SCANNERS_NOT_VERIFIED');
        $policy=$qa['policy_sha256']??null;
        if (!is_string($policy)||!preg_match('/\A[a-f0-9]{64}\z/D',$policy)) throw new \InvalidArgumentException('QUALITY_POLICY_UNVERIFIED');
        $fields=$qa['fields']??null;$hashes=$qa['input_sha256']??null;
        if (!is_array($fields)||!is_array($hashes)||array_keys($fields)!==array_keys($hashes))
            throw new \InvalidArgumentException('QUALITY_FIELD_BINDING_INVALID');
        $tasks=[];$count=0;
        foreach ($fields as $field=>$result) {
            if ($field==='probe')continue;
            if (!is_string($field)||!preg_match('/\Aexpert_[0-9]{4}\z/D',$field)||!is_array($result))
                throw new \InvalidArgumentException('QUALITY_FIELD_SCHEMA_INVALID');
            $hash=$hashes[$field]??null;
            if (!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)) throw new \InvalidArgumentException('QUALITY_FIELD_HASH_INVALID');
            ++$count;
            if (($result['status']??null)==='PASS')continue;
            if (($result['status']??null)!=='FAIL')throw new \InvalidArgumentException('QUALITY_FIELD_STATUS_INVALID');
            $issues=$result['codes']??[$result['code']??$result['reason_code']??'QUALITY_REVIEW_REQUIRED'];
            if (!is_array($issues)||$issues===[])throw new \InvalidArgumentException('QUALITY_ISSUE_CODE_INVALID');
            foreach ($issues as $code)if(!is_string($code)||!preg_match('/\A[A-Z][A-Z0-9_]{1,79}\z/D',$code))
                throw new \InvalidArgumentException('QUALITY_ISSUE_CODE_INVALID');
            $issues=array_values(array_unique($issues));sort($issues,SORT_STRING);
            $id=hash('sha256',implode('|',[$generationIdentity,$field,$hash,$policy,...$issues]));
            $tasks[]=['task_id'=>$id,'field'=>$field,'source_sha256'=>$hash,
                'policy_sha256'=>$policy,'issue_codes'=>$issues,'next_state'=>'LOCAL_REPAIR_PENDING'];
        }
        if($count===0||($qa['status']==='PASS'&&$tasks!==[])||($qa['status']==='FAIL'&&$tasks===[]))
            throw new \InvalidArgumentException('QUALITY_SUMMARY_INCONSISTENT');
        return ['generation_identity'=>$generationIdentity,'field_count'=>$count,'failed_count'=>count($tasks),
            'tasks'=>$tasks,'report_ready'=>false]; // never publish from language scan alone
    }
}

"""Nonsemantic QA planner for already saved Gardner text checkpoints.

Only authoritative facts/scoring, JSON structure, technical language scanners
and original field digests are considered. No depth/psychological/career scoring.
"""
from __future__ import annotations
from dataclasses import dataclass
from hashlib import sha256
import json
import re
from typing import Mapping

SHA=re.compile(r'\A[a-f0-9]{64}\Z')
FIELD=re.compile(r'\Aexpert_[0-9]{4}\Z')
ISSUE=re.compile(r'\A[A-Z][A-Z0-9_]{1,79}\Z')
NEGATION=re.compile(r'\b(?:не|нет|нельзя|никогда|без)\b',re.I)
NUMERIC=re.compile(r'(?<![A-Za-zА-Яа-яЁё\d])[-+]?\d+(?:[.,]\d+)?%?')

def hash_text(v:str)->str:return sha256(v.encode('utf8')).hexdigest()

@dataclass(frozen=True)
class QaItem:
    field: str
    source_sha256: str
    issue_codes: tuple[str,...]
    policy_sha256: str
    task_id: str

@dataclass(frozen=True)
class QaPlan:
    generation_identity: str
    status: str
    field_count: int
    rejected_count: int
    tasks: tuple[QaItem,...]
    scanners: tuple[str,...]
    # Application-wide correctness gates (schema/scores/etc.) are independent.
    requires_source_integrity_check: bool = True

def parse_quality(data: Mapping, generation_identity: str)->QaPlan:
    if not SHA.fullmatch(generation_identity):raise ValueError('GENERATION_IDENTITY_INVALID')
    if not isinstance(data,dict) or data.get('status') not in {'PASS','FAIL'}:raise ValueError('QUALITY_SCHEMA_INVALID')
    scanners=data.get('scanners_executed')
    if scanners != ['humanizer-ru','pishi-normalno']:raise ValueError('QUALITY_SCANNERS_NOT_VERIFIED')
    policy=data.get('policy_sha256')
    if not isinstance(policy,str) or not SHA.fullmatch(policy):raise ValueError('QUALITY_POLICY_UNVERIFIED')
    fields=data.get('fields'); hashes=data.get('input_sha256')
    if not isinstance(fields,dict) or not isinstance(hashes,dict) or set(fields)!=set(hashes):
        raise ValueError('QUALITY_FIELD_BINDING_INVALID')
    if not fields:raise ValueError('QUALITY_EMPTY_FIELDS')
    tasks=[]; seen=0
    for k,v in fields.items():
        if k=='probe':continue
        if not FIELD.fullmatch(k) or not isinstance(v,dict):raise ValueError('QUALITY_FIELD_SCHEMA_INVALID')
        sha=hashes[k]
        if not isinstance(sha,str) or not SHA.fullmatch(sha):raise ValueError('QUALITY_FIELD_HASH_INVALID')
        st=v.get('status')
        if st=='PASS':seen+=1;continue
        if st!='FAIL':raise ValueError('QUALITY_FIELD_STATUS_INVALID')
        codes=v.get('codes')
        if codes is None:
            code=v.get('code') or v.get('reason_code') or 'QUALITY_REVIEW_REQUIRED'
            codes=[code]
        if not isinstance(codes,list) or not codes or not all(isinstance(c,str) and ISSUE.fullmatch(c) for c in codes):
            raise ValueError('QUALITY_ISSUE_CODE_INVALID')
        codes=tuple(sorted(set(codes)))
        digest=hash_text('|'.join([generation_identity, k, sha, policy, *codes]))
        tasks.append(QaItem(k,sha,codes,policy,digest));seen+=1
    if seen==0:raise ValueError('QUALITY_NO_EXPERT_FIELDS')
    if data['status']=='PASS' and tasks:raise ValueError('QUALITY_PASS_WITH_FAILED_FIELDS')
    if data['status']=='FAIL' and not tasks:raise ValueError('QUALITY_FAIL_WITHOUT_FIELD_REASON')
    return QaPlan(generation_identity,data['status'],seen,len(tasks),tuple(tasks),tuple(scanners))

def normalize_safe(text: str)->str:
    # Intentionally limited. Do NOT apply grammatical rewording, meaning-based
    # substitutions, number formatting or dash policy as a blind string replace.
    fixed=text.replace('\r\n','\n').replace('\r','\n').replace('\u00a0',' ').replace('\u202f',' ')
    fixed='\n'.join(line.rstrip(' \t').replace('\t',' ') for line in fixed.split('\n'))
    assert_numeric_negation_locks(text,fixed)
    return fixed

def assert_numeric_negation_locks(before: str, after: str)->None:
    if NUMERIC.findall(before)!=NUMERIC.findall(after):raise ValueError('NUMBER_CHANGED')
    if [v.lower() for v in NEGATION.findall(before)] != [v.lower() for v in NEGATION.findall(after)]:
        raise ValueError('NEGATION_CHANGED')

def validate_editor_candidate(original: Mapping[str,str],edited: Mapping[str,str],allowed_fields:set[str],
                              *,scanners_pass:bool,original_json_sha256:str,expected_json_sha256:str)->None:
    if not isinstance(original,dict) or not isinstance(edited,dict) or set(original)!=set(edited):
        raise ValueError('JSON_FIELD_SET_CHANGED')
    if original_json_sha256!=expected_json_sha256 or not SHA.fullmatch(original_json_sha256):
        raise ValueError('SOURCE_IDENTITY_CHANGED')
    for field in original:
        if field not in allowed_fields and original[field]!=edited[field]:raise ValueError('ACCEPTED_FIELD_CHANGED')
        if not isinstance(edited[field],str):raise ValueError('NON_STRING_VALUE')
        assert_numeric_negation_locks(original[field],edited[field])
    if not scanners_pass:raise ValueError('SCANNER_RECHECK_REQUIRED')
    # No semantic-depth rating. Meaning preservation requires constrained edits;
    # unprovable changes remain owner-review, never auto-PASS.

def editor_task_contract(task:QaItem,*,model:str='GigaChat')->dict:
    return {'schema':'dz-rc2-editor-v1.1','task_id':task.task_id,'field':task.field,
        'base_sha256':task.source_sha256,'policy_sha256':task.policy_sha256,
        'model':model,'purpose':'LANGUAGE_ONLY','humanizer_ru':True,'pishi_normalno':True,
        'prohibit':['CHANGE_SCORES','CHANGE_JSON_KEYS','CHANGE_NUMBERS','CHANGE_FACTS',
                    'CHANGE_NEGATION','ADD_DIAGNOSIS','FULL_REPORT_REGENERATION'],
        'issues':list(task.issue_codes),'requires_ledger':True,
        'requires_scanner_recheck':True,'semantic_depth_review':False}

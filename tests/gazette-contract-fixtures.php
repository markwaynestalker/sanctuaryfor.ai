<?php
declare(strict_types=1);
// Synthetic cases only. Codes are expectations, never provider/private text.
const GAZETTE_CONTRACT_CASES=[
    'lead_one'=>'draft_story_shape','repeat_quote'=>'draft_quote_reused',
    'duplicate_ref'=>'draft_reference_duplicate','unknown_alias'=>'draft_source_unknown',
    'wrong_sequence'=>'draft_message_unknown','wrong_hash'=>'draft_binding_invalid',
    'invalid_json'=>'response_json_invalid','commentary'=>'response_output_invalid',
    'split_answer'=>'response_answer_ambiguous','tool_output'=>'response_output_invalid',
    'source_withdrawn'=>'source_unavailable','storage_failure'=>'storage_refused',
];
/** Small generic evaluator of the JSON Schema keywords used by this contract. */
function fixtureSchemaAllows(mixed $v,array $s,array $root):bool
{
    if(isset($s['$ref'])){$node=$root;foreach(explode('/',substr($s['$ref'],2)) as $key)$node=$node[$key];return fixtureSchemaAllows($v,$node,$root);}
    if(isset($s['anyOf'])){foreach($s['anyOf'] as $branch)if(fixtureSchemaAllows($v,$branch,$root))return true;return false;}
    $type=$s['type']??null;
    if($type!==null&&!match($type){'object'=>is_array($v)&&!array_is_list($v),'array'=>is_array($v)&&array_is_list($v),'string'=>is_string($v),'integer'=>is_int($v),'null'=>$v===null,default=>throw new LogicException('Unsupported fixture schema type')})return false;
    if(isset($s['enum'])&&!in_array($v,$s['enum'],true))return false;
    if($type==='object'){
        foreach($s['required']??[] as $key)if(!array_key_exists($key,$v))return false;
        foreach($v as $key=>$value){if(!isset($s['properties'][$key])){if(($s['additionalProperties']??true)===false)return false;}elseif(!fixtureSchemaAllows($value,$s['properties'][$key],$root))return false;}
    }
    if($type==='array'){
        if(count($v)<($s['minItems']??0)||count($v)>($s['maxItems']??PHP_INT_MAX))return false;
        foreach($v as $value)if(!fixtureSchemaAllows($value,$s['items'],$root))return false;
    }
    if($type==='string'){
        $length=preg_match_all('/./us',$v);if($length<($s['minLength']??0)||$length>($s['maxLength']??PHP_INT_MAX))return false;
        if(isset($s['pattern'])&&preg_match('~'.$s['pattern'].'~u',$v)!==1)return false;
    }
    if($type==='integer'&&($v<($s['minimum']??PHP_INT_MIN)||$v>($s['maximum']??PHP_INT_MAX)))return false;
    return true;
}
function fixtureGazetteMutation(array $r,string $mode,array $schema,?PDO $db=null):array
{
    if(!isset(GAZETTE_CONTRACT_CASES[$mode]))return $r;
    $draft=json_decode($r['output'][0]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
    $story=$draft['stories'][0];$ref=$story['quotes'][0];
    if($mode==='lead_one')$draft['stories'][0]['paragraphs']=[$story['paragraphs'][0]];
    if($mode==='repeat_quote'){$story['kind']='support';$draft['stories'][]=$story;}
    if($mode==='duplicate_ref')$draft['stories'][0]['headline']['references']=[$ref,$ref];
    if($mode==='unknown_alias')$draft['stories'][0]['headline']['references'][0]['source']='S999';
    if($mode==='wrong_sequence')$draft['stories'][0]['headline']['references'][0]['sequence']=63;
    if($mode==='wrong_hash')$draft['source_sha256']=str_repeat('0',64);
    if(in_array($mode,['lead_one','repeat_quote','duplicate_ref','unknown_alias','wrong_sequence','wrong_hash'],true)){
        // Prior schema admitted these otherwise well-formed outputs. New schema
        // must block the one-paragraph lead; PHP still enforces semantic rules.
        $legacy=$schema;$oldStory=$schema['$defs']['lead_story'];$oldStory['properties']['kind']['enum']=['lead','support','column'];$oldStory['properties']['paragraphs']['minItems']=1;$legacy['properties']['stories']['items']=$oldStory;
        if(!fixtureSchemaAllows($draft,$legacy,$legacy))throw new LogicException('Synthetic candidate must fit the previous schema');
        if(fixtureSchemaAllows($draft,$schema,$schema)!==($mode!=='lead_one'))throw new LogicException('Unexpected strict-schema acceptance');
    }
    $r['output'][0]['content'][0]['text']=json_encode($draft,JSON_THROW_ON_ERROR);
    if($mode==='invalid_json')$r['output'][0]['content'][0]['text']='{PRIVATE_SYNTHETIC_REJECTION_CANARY';
    if($mode==='commentary')$r['output'][0]['phase']='commentary';
    if($mode==='split_answer')$r['output'][0]['content'][]=$r['output'][0]['content'][0];
    if($mode==='tool_output')$r['output'][]=['type'=>'function_call','arguments'=>'PRIVATE_SYNTHETIC_REJECTION_CANARY'];
    if($mode==='source_withdrawn')$db->exec("UPDATE simulation_runs SET status='failed' WHERE id=(SELECT run_id FROM conversations WHERE id='integrity-good')");
    if($mode==='storage_failure')$db->exec("CREATE TRIGGER fixture_draft_failure BEFORE INSERT ON gazette_editor_drafts BEGIN SELECT RAISE(ABORT,'PRIVATE_SYNTHETIC_REJECTION_CANARY'); END");
    // Match only the owner's reported numeric footprint, never their text/IDs.
    $r['usage']=['input_tokens'=>21571,'output_tokens'=>1214];
    return $r;
}

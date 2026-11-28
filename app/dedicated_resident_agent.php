<?php
declare(strict_types=1);
namespace LittleSparks;

/** Stable resident identities, independent of changeable display names. */
function dedicatedResidentAgentSettings(): array
{
    return [
        '15ebcd0b0da37bd5e00c1e2c32f2ee86'=>'LITTLE_SPARKS_AGENT_MAX',
        '202deea1ccb5e92de581c90791aa4aad'=>'LITTLE_SPARKS_AGENT_SPARKY',
        '28ad1522315b3a591bdc07923aa79bb5'=>'LITTLE_SPARKS_AGENT_PENELOPE',
        '3da13508a7a04c46d8c11c3335539c04'=>'LITTLE_SPARKS_AGENT_WALTER',
        '472d0da03b6c84681ad54cf775930c2f'=>'LITTLE_SPARKS_AGENT_LUNA',
        '4cb65a7499928a9c1d007f0146ee7af9'=>'LITTLE_SPARKS_AGENT_PRINCESS',
        '8ea38aa406bed321215ea78241e9bdb2'=>'LITTLE_SPARKS_AGENT_WANDA',
        '8f51e90ed616a4c0ee9c7cc2e810ef7a'=>'LITTLE_SPARKS_AGENT_SKIPPY_JR',
        'a9bd4cb87a828e8c365cdf94efe2820b'=>'LITTLE_SPARKS_AGENT_BLAKIE',
    ];
}

/** Missing configuration is distinguishable from a malformed setting. */
function dedicatedResidentAgent(string $resident): ?string
{
    if(!preg_match('/\A[a-f0-9]{32}\z/', $resident))throw new \InvalidArgumentException('Invalid resident ID.');
    $setting=dedicatedResidentAgentSettings()[$resident]??null;
    if($setting===null)return null;
    $value=getenv($setting);
    if($value===false)return null;
    if(!preg_match('/\Aagent_[a-zA-Z0-9_-]{1,58}\z/', $value))throw new \RuntimeException('Invalid dedicated agent configuration.');
    return $value;
}

/** No agent override: the provider uses the saved agent configuration unchanged. */
function dedicatedResidentSessionRequest(string $agent, array $context): array
{
    if(!preg_match('/\Aagent_[a-zA-Z0-9_-]{1,58}\z/', $agent))throw new \InvalidArgumentException('Invalid agent ID.');
    return ['agent_id'=>$agent, 'environment'=>['type'=>'none'],
        'input'=>json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)];
}

/** A decision belongs to the invoked resident, never to the coordinator. */
function parseDedicatedResidentDecision(array $value,string $resident,array $eligible):array
{
    if(!preg_match('/\A[a-f0-9]{32}\z/', $resident)||count($value)!==4
        ||array_diff(array_keys($value),['resident_id','decision','dialogue','invite_resident_id'])
        ||($value['resident_id']??null)!==$resident
        ||!in_array($value['decision']??null,['join','decline','defer','continue','pause','leave','finish','invite'],true))throw new \InvalidArgumentException('Invalid resident decision.');
    $dialogue=$value['dialogue']??null;
    if($dialogue!==null&&(!is_string($dialogue)||$dialogue===''||str_contains($dialogue,"\0")||preg_match('//u',$dialogue)!==1))throw new \InvalidArgumentException('Invalid resident dialogue.');
    $invited=$value['invite_resident_id']??null;
    if($value['decision']==='invite'){
        if(!is_string($invited)||$invited===$resident||!in_array($invited,$eligible,true))throw new \InvalidArgumentException('Invalid invitation target.');
    }elseif($invited!==null)throw new \InvalidArgumentException('Unexpected invitation target.');
    return $value;
}

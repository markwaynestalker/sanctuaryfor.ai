<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function directorConfiguration():array
{
    $flag=getenv('LITTLE_SPARKS_DIRECTOR_ENABLED');if($flag!==false&&!in_array($flag,['0','1'],true))throw new AiGuard('configuration_invalid');
    $runtime=getenv('LITTLE_SPARKS_DIRECTOR_RUNTIME');$runtime=$runtime===false?'agents':$runtime;
    if(!in_array($runtime,['responses','agents'],true))throw new AiGuard('configuration_invalid');
    return ['enabled'=>$flag==='1','runtime'=>$runtime,'agent_id'=>(string)getenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID'),
        'fingerprint'=>(string)getenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256'),
        'description_context'=>boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_DESCRIPTION_CONTEXT',800,1,2000),
        'instructions_bytes'=>boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_INSTRUCTIONS_BYTES',4096,1,8192)];
}
function validateDirectorExport(array $export):array
{
    $id=$export['id']??null;$instructions=$export['instructions']??'';$model=$export['model']??null;
    if(!is_string($id)||!preg_match('/\Aagent_[a-zA-Z0-9_-]{1,58}\z/',$id)||!in_array($model,['gpt-6-luna','gpt-6.1-sol'],true)
        ||!is_string($instructions)||(directorConfiguration()['runtime']==='responses'&&$instructions==='')||!preg_match('//u',$instructions)||strlen($instructions)>directorConfiguration()['instructions_bytes']
        ||($export['tools']??null)!==[]||($export['multi_agent']['enabled']??null)!==false||($export['service_tier']??null)!=='default'
        ||preg_match('/\bsk-[a-zA-Z0-9_-]{8,}|Bearer\s+[a-zA-Z0-9_-]{8,}/',json_encode($export,JSON_THROW_ON_ERROR)))throw new AiGuard('configuration_invalid');
    $reasoning=$export['reasoning']??[];
    $effort=$model==='gpt-6-luna'?'none':'low';
    if(($reasoning['effort']??null)!==$effort)throw new AiGuard('configuration_invalid');
    // Only reviewed structural configuration is retained; never external secrets,
    // vault material, metadata, connections, environments or tool credentials.
    return ['id'=>$id,'model'=>$model,'instructions'=>$instructions,'tools'=>[],'multi_agent'=>['enabled'=>false],
        'service_tier'=>'default','reasoning'=>['effort'=>$effort]];
}
function readDirectorExport(string $path):array
{
    $path=databasePath($path);if(filesize($path)>32768)throw new AiGuard('configuration_invalid');
    $data=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new AiGuard('configuration_invalid');return validateDirectorExport($data);
}
function registerDirector(PDO $db,array $export):array
{
    if(!directorEnabled($db))throw new Problem(503,'Explicit V6 migration required.');
    $config=validateDirectorExport($export);$encoded=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$fingerprint=hash('sha256',$encoded);
    $time=gmdate('Y-m-d\TH:i:s\Z');$db->exec('BEGIN IMMEDIATE');
    try{
        assertSimulationLease($db);if($db->query("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetchColumn())throw new Problem(409,'Drain pending opportunities before registration.');
        $db->prepare('INSERT OR IGNORE INTO director_registry VALUES (?,?,?,?,?,?)')->execute([$fingerprint,$config['id'],$config['model'],$config['instructions'],$encoded,$time]);
        $db->exec('COMMIT');return ['agent_id'=>$config['id'],'model'=>$config['model'],'fingerprint'=>$fingerprint];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function registeredDirector(PDO $db,array $cfg):array
{
    if(!preg_match('/\Aagent_[a-zA-Z0-9_-]{1,58}\z/',$cfg['agent_id'])||!preg_match('/\A[a-f0-9]{64}\z/',$cfg['fingerprint']))throw new AiGuard('configuration_invalid');
    $q=$db->prepare('SELECT * FROM director_registry WHERE fingerprint=? AND agent_id=?');$q->execute([$cfg['fingerprint'],$cfg['agent_id']]);$row=$q->fetch();
    if(!$row)throw new AiGuard('configuration_invalid');
    $checked=validateDirectorExport(json_decode($row['configuration'],true,16,JSON_THROW_ON_ERROR));
    if(hash('sha256',json_encode($checked,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))!==$cfg['fingerprint'])throw new AiGuard('configuration_invalid');return $row;
}
function directorSchema(array $choices):array
{
    $schema=json_decode((string)file_get_contents(__DIR__.'/../contracts/director-v1.schema.json'),true,32,JSON_THROW_ON_ERROR);
    $schema['properties']['world_action']['enum']=array_keys($choices);return $schema;
}
function managedDirectorPrototype(array $external,array $context,array $choices):array
{
    $config=validateDirectorExport($external);
    // Saved Director owns the instructions. Callers enforce configured budgets
    // and optional experimental permits; managed billing remains provisional.
    return ['agent_id'=>$config['id'],'environment'=>['type'=>'none'],
        'agent'=>['model'=>$config['model'],'reasoning'=>$config['reasoning'],'tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default',
            'text'=>['format'=>['type'=>'json_schema','schema'=>directorSchema($choices)]]],
        'input'=>[['role'=>'user','content'=>[['type'=>'input_text','text'=>json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]]]],'stream'=>false];
}

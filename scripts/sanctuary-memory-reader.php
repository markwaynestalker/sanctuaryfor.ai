<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/public_memory_reader.php';
try{
    $options=[];
    foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/\A--(database|query|resident-id|limit)=(.*)\z/s',$arg,$m)||isset($options[$m[1]]))throw new LittleSparks\Problem(422,'Use unique --database, --query, --resident-id and --limit values.');
        $options[$m[1]]=$m[2];
    }
    $args=[];if(isset($options['query']))$args['query']=$options['query'];if(isset($options['resident-id']))$args['resident_id']=$options['resident-id'];
    if(isset($options['limit'])){if(!preg_match('/\A(?:[1-9]|1[0-9]|20)\z/',$options['limit']))throw new LittleSparks\Problem(422,'Use an integer limit from 1 to 20.');$args['limit']=(int)$options['limit'];}
    echo json_encode(LittleSparks\searchPublicVillageConversations($options['database']??(string)getenv('LITTLE_SPARKS_DB'),$args),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,json_encode(['error'=>$e instanceof LittleSparks\Problem?$e->getMessage():'Public conversation history is unavailable.'],JSON_THROW_ON_ERROR)."\n");exit(1);}

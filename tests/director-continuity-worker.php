<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';
require __DIR__.'/../app/activity.php';
require __DIR__.'/../app/director.php';
try{
    $db=LittleSparks\connect($argv[1]);$claim=LittleSparks\claimActivity($db,$argv[2],time());
    echo json_encode(['claimed'=>true,'pair'=>array_column($claim['pair'],'id'),'continuation'=>$claim['continuation']??null],JSON_THROW_ON_ERROR);
}catch(LittleSparks\Problem $e){
    if($e->status!==409)throw $e;
    echo json_encode(['claimed'=>false,'status'=>409],JSON_THROW_ON_ERROR);
}

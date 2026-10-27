<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/bulletin.php';$transaction=false;
try{
    $o=getopt('',['database:','notice:','confirm:']);$id=$o['notice']??'';
    if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/',$id)||($o['confirm']??null)!==$id)throw new RuntimeException('Confirmation required.');
    $db=LittleSparks\connect((string)($o['database']??''));if(!LittleSparks\bulletinInstalled($db))throw new RuntimeException('Migration required.');
    $db->exec('BEGIN IMMEDIATE');$transaction=true;$q=$db->prepare('SELECT 1 FROM bulletin_notices WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())throw new RuntimeException('Unknown notice.');
    $db->prepare("INSERT OR IGNORE INTO bulletin_removals VALUES (?,?,'operator_removed')")->execute([$id,gmdate('Y-m-d\TH:i:s\Z')]);$db->exec('COMMIT');echo "Notice withdrawn from public views; original history preserved.\n";
}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Removal refused. Use the private database, existing notice ID and matching --confirm ID.\n");exit(1);}

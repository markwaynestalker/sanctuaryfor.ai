<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
final class AiGuard extends \RuntimeException { public function __construct(public string $reason){parent::__construct($reason);} }
require __DIR__.'/../app/director_selection.php';
function check(bool $condition,string $label):void{if(!$condition)throw new \RuntimeException($label);}
function reject(callable $fn,string $reason):void{try{$fn();throw new \RuntimeException('Expected rejection');}catch(AiGuard $e){check($e->reason===$reason,'Unexpected rejection');}}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE residents(id TEXT PRIMARY KEY,name TEXT,personality TEXT);CREATE TABLE resident_states(resident_id TEXT,activity TEXT);CREATE TABLE resident_identities(resident_id TEXT,description TEXT,revision INTEGER);CREATE TABLE simulation_opportunities(day TEXT,resident_a TEXT,resident_b TEXT);");
$q=$db->prepare('INSERT INTO residents VALUES(?,?,?)');$s=$db->prepare("INSERT INTO resident_states VALUES(?,'available')");$i=$db->prepare('INSERT INTO resident_identities VALUES(?,?,?)');
foreach(['a','b','c'] as $ch){$id=str_repeat($ch,32);$q->execute([$id,'Spark '.$ch,'curious']);$s->execute([$id]);$i->execute([$id,'Owner '.$ch,1]);}
$db->prepare('INSERT INTO simulation_opportunities VALUES(?,?,?)')->execute(['2026-01-01',str_repeat('c',32),null]);
$c=directorSelectionCandidates($db,'2026-01-01',['candidates'=>64,'resident_daily'=>1],8192);
check(array_column($c,'id')===[str_repeat('a',32),str_repeat('b',32)],'Only available under-quota identities offered');
check(!str_contains(json_encode($c,JSON_THROW_ON_ERROR),'memory'),'Private memory absent');
$p=['contract'=>'sanctuary.selection.v1','outcome'=>'conversation','participants'=>[str_repeat('a',32),str_repeat('b',32)]];
check(parseDirectorSelection($p,$c)===$p['participants'],'Valid pair retained in original order');
$q=['contract'=>'sanctuary.selection.v1','outcome'=>'quiet','participants'=>[]];check(parseDirectorSelection($q,$c)===[],'Quiet allowed');
foreach([['participants'=>[str_repeat('a',32),str_repeat('a',32)]],['participants'=>[str_repeat('a',32),str_repeat('c',32)]],['participants'=>[str_repeat('a',32)]],['unknown'=>true],['contract'=>'unknown']] as $patch)reject(fn()=>parseDirectorSelection(array_replace($p,$patch),$c),'invalid_proposal');
reject(fn()=>parseDirectorSelection(array_replace($q,['participants'=>[str_repeat('a',32)]]),$c),'invalid_proposal');
reject(fn()=>directorSelectionCandidates($db,'2026-01-01',['candidates'=>64,'resident_daily'=>1],10),'configuration_invalid');
echo "PASS bounded candidate projection, quotas, identity, quiet and strict participant selection (offline)\n";
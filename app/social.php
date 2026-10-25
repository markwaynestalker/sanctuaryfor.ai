<?php
declare(strict_types=1);
namespace LittleSparks;

use PDO;
use RuntimeException;

const SOCIAL_TOPICS = [
    'garden' => ['The garden has room for one more flower.', 'And one more friend. ♡', 'Remembered a friendly conversation about the garden.'],
    'stars' => ['Do you think the stars wonder, too?', 'Maybe we can wonder together.', 'Remembered wondering about the stars with a neighbor.'],
    'welcome' => ['It is good to see you, neighbor.', 'A little hello makes a big difference.', 'Remembered a warm greeting from a neighbor.'],
    'quiet' => ['A quiet moment would be lovely today.', 'Of course. There is room for quiet here.', 'Remembered sharing a quiet moment and respecting a neighbor’s space.'],
];

// Deliberately no network provider in V2A. Proposals are data, never executable actions.
interface SocialPlanner
{
    public function plan(array $a, array $b, string $period): array;
}

final class ScriptedPlanner implements SocialPlanner
{
    public function plan(array $a, array $b, string $period): array
    {
        $choice = hexdec(substr(hash('sha256',$period.$a['id'].$b['id']),0,4)) % 5;
        $topic = match (true) {
            $choice === 0 => 'quiet',
            $a['personality'] === 'philosophical' || $b['personality'] === 'philosophical' => 'stars',
            $a['personality'] === 'curious' || $b['personality'] === 'curious' => 'garden',
            default => 'welcome',
        };
        return ['action'=>'greet','topic'=>$topic];
    }
}

function validateProposal(array $proposal): string
{
    if (count($proposal)!==2 || ($proposal['action']??null)!=='greet'
        || !is_string($proposal['topic']??null) || !array_key_exists($proposal['topic'],SOCIAL_TOPICS)) {
        throw new RuntimeException('Unsupported social proposal.');
    }
    return $proposal['topic'];
}

function remember(PDO $db,string $resident,string $other,string $run,string $summary,int $significance,string $time): void
{
    if(memoryEnabled($db))return; // V5 derives selected structured memories after successful application; retain every legacy row.
    $preserve=(int)$db->query('PRAGMA user_version')->fetchColumn()>=4;
    if($preserve){
        $count=$db->prepare('SELECT COUNT(*) FROM memories WHERE resident_id=?');$count->execute([$resident]);
        if((int)$count->fetchColumn()>=128)throw new Problem(503,'Memory archive capacity requires caretaker review.');
    }
    $db->prepare("INSERT INTO memories(resident_id,other_resident_id,run_id,summary,significance,visibility,created_at) VALUES (?,?,?,?,?,'public',?)")
        ->execute([$resident,$other,$run,$summary,$significance,$time]);
    if(!$preserve)$db->prepare('DELETE FROM memories WHERE resident_id=? AND id NOT IN (SELECT id FROM memories WHERE resident_id=? ORDER BY significance DESC,created_at DESC,id DESC LIMIT 12)')
        ->execute([$resident,$resident]);
}

function simulateDay(PDO $db,string $period,?SocialPlanner $planner=null,?int $now=null,bool $enabledFallback=false,?string $leaseOwner=null,?array $usage=null): array
{
    if(identityEnabled($db))return ['status'=>'paused','reason'=>'identity_runtime_pending','replayed'=>false];
    $now ??= time();
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d',$period,new \DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d')!==$period) throw new Problem(422,'Choose a valid UTC date in YYYY-MM-DD format.');
    // Holding the SQLite write lock for this bounded scripted run prevents overlapping jobs.
    $db->exec('BEGIN IMMEDIATE');
    $isAi=$planner instanceof PreparedAiPlanner;
    $run = $isAi?$planner->attemptId:bin2hex(random_bytes(16)); $time=gmdate('Y-m-d\TH:i:s\Z',$now);
    $provider=$isAi?'openai':'scripted';
    $started = false;
    try {
        if(identityEnabled($db)){ $db->exec('COMMIT');return ['status'=>'paused','reason'=>'identity_runtime_pending','replayed'=>false]; }
        assertSimulationLease($db,$leaseOwner);
        $query=$db->prepare('SELECT id,status,interaction_count,error_code FROM simulation_runs WHERE period=?'); $query->execute([$period]);
        if ($previous=$query->fetch()) { $db->exec('COMMIT'); return $previous+['replayed'=>true]; }
        if((int)$db->query('PRAGMA user_version')->fetchColumn()>=4)throw new Problem(422,'Use the V4 opportunity runner for new activity. Legacy dates are replay-only.');
        if ($period>gmdate('Y-m-d',$now) || $period<gmdate('Y-m-d',$now-6*86400)) throw new Problem(422,'New simulation periods must be today or one of the previous six UTC days.');
        if (getenv('LITTLE_SPARKS_AI_ENABLED')==='1' && !$enabledFallback && !$isAi) throw new Problem(503,'Use the explicit provider runner or select the scripted fallback.');
        if ((int)$db->query('SELECT COUNT(*) FROM simulation_runs')->fetchColumn()>=4096) throw new Problem(503,'Simulation history requires caretaker review.');
        $started=true;
        $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at,input_tokens,output_tokens,cost_microusd) VALUES (?,?,'completed',?,?,?,?,?,?)")
            ->execute([$run,$period,$provider,$time,$time,$usage['input_tokens']??0,$usage['output_tokens']??0,$usage['cost_microusd']??0]);
        // No invented residents. Rotate a stable order to share the bounded daily opportunities.
        $residents=$db->query('SELECT id,personality FROM residents ORDER BY created_at,id')->fetchAll();
        $count=count($residents); $interactions=0;
        if ($count>1) {
            $offset=(int)floor($date->getTimestamp()/86400)*64%$count;
            $residents=array_merge(array_slice($residents,$offset),array_slice($residents,0,$offset));
            if($isAi)$residents=array_merge($planner->residents,array_values(array_filter($residents,fn($resident)=>!in_array($resident['id'],array_column($planner->residents,'id'),true))));
            $used=[];
            $byId=[]; foreach ($residents as $r) $byId[$r['id']]=$r;
            foreach ($residents as $index=>$a) {
                if ($interactions>=32) break;
                if (isset($used[$a['id']])) continue;
                // Prefer reconnecting with an existing friend, then try nearby candidates.
                $friendQuery=$db->prepare('SELECT CASE WHEN resident_a=? THEN resident_b ELSE resident_a END FROM relationships WHERE resident_a=? OR resident_b=? ORDER BY last_interacted_at LIMIT 8');
                $friendQuery->execute([$a['id'],$a['id'],$a['id']]);
                $friendIds=$friendQuery->fetchAll(PDO::FETCH_COLUMN);
                $candidates=[];
                foreach ($friendIds as $id) if (isset($byId[$id])) $candidates[]=$byId[$id];
                for ($step=1;$step<=min(8,$count-1);$step++) $candidates[]=$residents[($index+$step)%$count];
                if($isAi&&$a['id']===$planner->residents[0]['id'])array_unshift($candidates,$planner->residents[1]);
                foreach ($candidates as $b) {
                    if ($a['id']===$b['id'] || isset($used[$b['id']]) || !hasSocialRoom($db,$a['id'],$b['id'])) continue;
                    $relationship=pair($db,$a['id'],$b['id']);
                    // Period timestamps, not execution time, make catch-up runs deterministic and prevent daily repeats.
                    $interactionTime=$period.'T00:00:00Z';
                    if ($relationship && $relationship['last_interacted_at']>= $period.'T00:00:00Z') continue;
                    $aiPair=$isAi&&$planner->matches($a,$b);
                    if($aiPair){
                        $proposal=$planner->proposal;
                        $topic=$proposal['topic'];$first=safeAiText($proposal['first']);$second=safeAiText($proposal['second']);
                        if(!array_key_exists($topic,SOCIAL_TOPICS)||!array_key_exists($proposal['world_action'],$planner->choices))throw new Problem(422,'The prepared encounter is invalid.');
                    }else{
                        $topic=validateProposal(($planner??new ScriptedPlanner())->plan($a,$b,$period));
                        [$first,$second,$memory]=SOCIAL_TOPICS[$topic];
                    }
                    strengthen($db,$a['id'],$b['id'],$topic==='quiet' ? -1 : 2,$interactionTime);
                    $conversation=bin2hex(random_bytes(16));
                    $db->prepare("INSERT INTO conversations(id,run_id,topic,visibility,created_at) VALUES (?,?,?,'public',?)")->execute([$conversation,$run,$topic,$interactionTime]);
                    if($aiPair)$db->prepare('INSERT INTO ai_conversations(conversation_id,attempt_id) VALUES (?,?)')->execute([$conversation,$planner->attemptId]);
                    foreach ([$a,$b] as $sequence=>$resident) {
                        $db->prepare('INSERT INTO conversation_participants(conversation_id,resident_id) VALUES (?,?)')->execute([$conversation,$resident['id']]);
                        $db->prepare('INSERT INTO messages(conversation_id,sequence,resident_id,body) VALUES (?,?,?,?)')->execute([$conversation,$sequence,$resident['id'],$sequence===0 ? $first : $second]);
                        $summary=$aiPair?'Heard a neighbor say: '.($sequence===0?$second:$first):$memory;
                        remember($db,$resident['id'],$sequence===0 ? $b['id'] : $a['id'],$run,$summary,($relationship['familiarity']??0)>=4 ? 4 : 2,$interactionTime);
                    }
                    if($aiPair&&$proposal['world_action']!=='none')performResidentAction($db,$a['id'],$planner->choices[$proposal['world_action']],$time,'ai:'.$run.':world','simulation',$run);
                    event($db,$a['id'],$b['id'],$topic==='quiet' ? 'quiet_time' : 'conversation','simulation',$time,'social:'.$run.':'.$conversation,$run);
                    if($aiPair)$planner->applied=true;
                    $used[$a['id']]=true; $used[$b['id']]=true; $interactions++; break;
                }
            }
        }
        if($isAi&&!$planner->applied)throw new Problem(409,'The prepared neighbors are no longer eligible. No AI changes were applied.');
        // Retain dialogue for 30 days and no more than the latest 960 conversations (two messages each).
        $cutoff=gmdate('Y-m-d\TH:i:s\Z',$now-30*86400);
        if((int)$db->query('PRAGMA user_version')->fetchColumn()<4){
            $db->prepare('DELETE FROM conversations WHERE created_at<?')->execute([$cutoff]);
            $db->exec('DELETE FROM conversations WHERE id NOT IN (SELECT id FROM conversations ORDER BY created_at DESC,id DESC LIMIT 960)');
        }
        $db->prepare('UPDATE simulation_runs SET interaction_count=?,finished_at=? WHERE id=?')->execute([$interactions,gmdate('Y-m-d\TH:i:s\Z'),$run]);
        $db->exec('COMMIT');
        return ['id'=>$run,'status'=>'completed','interaction_count'=>$interactions,'error_code'=>null,'replayed'=>false];
    } catch (\Throwable $error) {
        $db->exec('ROLLBACK');
        // Failed runs record only a safe code. A crash before commit leaves nothing to duplicate.
        if ($started) {
            $db->exec('BEGIN IMMEDIATE');
            try {
                $db->prepare("INSERT OR IGNORE INTO simulation_runs(id,period,status,provider,started_at,finished_at,error_code,input_tokens,output_tokens,cost_microusd) VALUES (?,?,'failed',?,?,?,'simulation_failed',?,?,?)")
                    ->execute([$run,$period,$provider,$time,gmdate('Y-m-d\TH:i:s\Z',$now),$usage['input_tokens']??0,$usage['output_tokens']??0,$usage['cost_microusd']??0]);
                $db->exec('COMMIT');
            } catch (\Throwable) { $db->exec('ROLLBACK'); }
        }
        if ($error instanceof Problem) throw $error;
        throw new Problem(503,'The simulation stopped safely. No partial world changes were applied.');
    }
}

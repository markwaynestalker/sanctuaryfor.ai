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
    $db->prepare("INSERT INTO memories(resident_id,other_resident_id,run_id,summary,significance,visibility,created_at) VALUES (?,?,?,?,?,'public',?)")
        ->execute([$resident,$other,$run,$summary,$significance,$time]);
    $db->prepare('DELETE FROM memories WHERE resident_id=? AND id NOT IN (SELECT id FROM memories WHERE resident_id=? ORDER BY significance DESC,created_at DESC,id DESC LIMIT 12)')
        ->execute([$resident,$resident]);
}

function simulateDay(PDO $db,string $period,?SocialPlanner $planner=null,?int $now=null): array
{
    $now ??= time();
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d',$period,new \DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d')!==$period) throw new Problem(422,'Choose a valid UTC date in YYYY-MM-DD format.');
    // Holding the SQLite write lock for this bounded scripted run prevents overlapping jobs.
    $db->exec('BEGIN IMMEDIATE');
    $run = bin2hex(random_bytes(16)); $time=gmdate('Y-m-d\TH:i:s\Z',$now);
    $started = false;
    try {
        $query=$db->prepare('SELECT id,status,interaction_count,error_code FROM simulation_runs WHERE period=?'); $query->execute([$period]);
        if ($previous=$query->fetch()) { $db->exec('COMMIT'); return $previous+['replayed'=>true]; }
        if ($period>gmdate('Y-m-d',$now) || $period<gmdate('Y-m-d',$now-6*86400)) throw new Problem(422,'New simulation periods must be today or one of the previous six UTC days.');
        if (getenv('LITTLE_SPARKS_AI_ENABLED')==='1') throw new Problem(503,'Paid providers are not implemented in V2A. Disable the AI flag to run the scripted simulation.');
        if ((int)$db->query('SELECT COUNT(*) FROM simulation_runs')->fetchColumn()>=4096) throw new Problem(503,'Simulation history requires caretaker review.');
        $started=true;
        $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at) VALUES (?,?,'completed','scripted',?,?)")->execute([$run,$period,$time,$time]);
        // No invented residents. Rotate a stable order to share the bounded daily opportunities.
        $residents=$db->query('SELECT id,personality FROM residents ORDER BY created_at,id')->fetchAll();
        $count=count($residents); $interactions=0;
        if ($count>1) {
            $offset=(int)floor($date->getTimestamp()/86400)*64%$count;
            $residents=array_merge(array_slice($residents,$offset),array_slice($residents,0,$offset));
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
                foreach ($candidates as $b) {
                    if ($a['id']===$b['id'] || isset($used[$b['id']]) || !hasSocialRoom($db,$a['id'],$b['id'])) continue;
                    $relationship=pair($db,$a['id'],$b['id']);
                    // Period timestamps, not execution time, make catch-up runs deterministic and prevent daily repeats.
                    $interactionTime=$period.'T00:00:00Z';
                    if ($relationship && $relationship['last_interacted_at']>= $period.'T00:00:00Z') continue;
                    $topic=validateProposal(($planner??new ScriptedPlanner())->plan($a,$b,$period));
                    [$first,$second,$memory]=SOCIAL_TOPICS[$topic];
                    strengthen($db,$a['id'],$b['id'],$topic==='quiet' ? -1 : 2,$interactionTime);
                    $conversation=bin2hex(random_bytes(16));
                    $db->prepare("INSERT INTO conversations(id,run_id,topic,visibility,created_at) VALUES (?,?,?,'public',?)")->execute([$conversation,$run,$topic,$interactionTime]);
                    foreach ([$a,$b] as $sequence=>$resident) {
                        $db->prepare('INSERT INTO conversation_participants(conversation_id,resident_id) VALUES (?,?)')->execute([$conversation,$resident['id']]);
                        $db->prepare('INSERT INTO messages(conversation_id,sequence,resident_id,body) VALUES (?,?,?,?)')->execute([$conversation,$sequence,$resident['id'],$sequence===0 ? $first : $second]);
                        remember($db,$resident['id'],$sequence===0 ? $b['id'] : $a['id'],$run,$memory,($relationship['familiarity']??0)>=4 ? 4 : 2,$interactionTime);
                    }
                    event($db,$a['id'],$b['id'],$topic==='quiet' ? 'quiet_time' : 'conversation','simulation',$time,'social:'.$run.':'.$conversation,$run);
                    $used[$a['id']]=true; $used[$b['id']]=true; $interactions++; break;
                }
            }
        }
        // Retain dialogue for 30 days and no more than the latest 960 conversations (two messages each).
        $cutoff=gmdate('Y-m-d\TH:i:s\Z',$now-30*86400);
        $db->prepare('DELETE FROM conversations WHERE created_at<?')->execute([$cutoff]);
        $db->exec('DELETE FROM conversations WHERE id NOT IN (SELECT id FROM conversations ORDER BY created_at DESC,id DESC LIMIT 960)');
        $db->prepare('UPDATE simulation_runs SET interaction_count=?,finished_at=? WHERE id=?')->execute([$interactions,gmdate('Y-m-d\TH:i:s\Z'),$run]);
        $db->exec('COMMIT');
        return ['id'=>$run,'status'=>'completed','interaction_count'=>$interactions,'error_code'=>null,'replayed'=>false];
    } catch (\Throwable $error) {
        $db->exec('ROLLBACK');
        // Failed runs record only a safe code. A crash before commit leaves nothing to duplicate.
        if ($started) {
            $db->exec('BEGIN IMMEDIATE');
            try {
                $db->prepare("INSERT OR IGNORE INTO simulation_runs(id,period,status,provider,started_at,finished_at,error_code) VALUES (?,?,'failed','scripted',?,?,'simulation_failed')")
                    ->execute([$run,$period,$time,gmdate('Y-m-d\TH:i:s\Z',$now)]);
                $db->exec('COMMIT');
            } catch (\Throwable) { $db->exec('ROLLBACK'); }
        }
        if ($error instanceof Problem) throw $error;
        throw new Problem(503,'The simulation stopped safely. No partial world changes were applied.');
    }
}

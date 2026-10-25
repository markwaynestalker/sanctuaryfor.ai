<?php
declare(strict_types=1);
namespace LittleSparks;

use PDO;

const WORLD_ACTIONS = ['plant_tree','plant_flowers','plant_bush','decorate_house','build_path','place_decoration','adopt_pet','visit_friend'];
const EVENT_TEXT = [
    'home_assigned' => 'received a permanent home plot', 'plant_tree' => 'planted an oak tree',
    'plant_flowers' => 'planted wildflowers', 'plant_bush' => 'planted a berry bush',
    'decorate_house' => 'decorated their cottage with stars', 'build_path' => 'laid a stone path',
    'place_decoration' => 'placed a garden lantern', 'adopt_pet' => 'adopted a virtual cat',
    'visit_friend' => 'visited a neighbor', 'conversation' => 'shared a friendly conversation',
    'quiet_time' => 'shared a quiet moment, then took some space',
];

function plotCoordinates(int $slot): array
{
    return ['x' => ($slot % 100) * 4, 'y' => intdiv($slot, 100) * 4, 'width' => 4, 'height' => 4,
        'sector' => intdiv(intdiv($slot, 100), 4) * 25 + intdiv($slot % 100, 4)];
}

function event(PDO $db, string $actor, ?string $target, string $kind, string $origin, string $time, string $dedupe, ?string $run = null, ?string $fingerprint = null): int
{
    if ((int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn() >= 100000) throw new Problem(503, 'The journal is full. Changes are paused until a caretaker reviews it.');
    $db->prepare("INSERT INTO events(actor_id,target_id,kind,origin,created_at,dedupe_key,run_id,request_hash,visibility) VALUES (?,?,?,?,?,?,?,?,'public')")
        ->execute([$actor,$target,$kind,$origin,$time,$dedupe,$run,$fingerprint]);
    return (int) $db->lastInsertId();
}

function assignHome(PDO $db, string $id, string $time, string $origin = 'creation'): void
{
    $slot = (int) $db->query('SELECT COALESCE(MAX(slot),-1)+1 FROM home_plots')->fetchColumn();
    if ($slot >= 10000) throw new Problem(503, 'The village has no free home plots.');
    $db->prepare('INSERT INTO home_plots(resident_id,slot) VALUES (?,?)')->execute([$id,$slot]);
    $plot = plotCoordinates($slot);
    foreach ([['house','cottage',1,1],['path','stone',1,2],['bush','berry',3,0]] as [$kind,$variant,$dx,$dy]) {
        $db->prepare('INSERT INTO world_objects(id,resident_id,kind,variant,x,y,created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([bin2hex(random_bytes(16)),$id,$kind,$variant,$plot['x']+$dx,$plot['y']+$dy,$time]);
    }
    event($db,$id,null,'home_assigned',$origin,$time,'home:'.$id);
}

function world(PDO $db, int $sector): array
{
    $sector = max(0,min(624,$sector));
    $x = ($sector % 25)*16; $y = intdiv($sector,25)*16;
    $query = $db->prepare('SELECT p.slot,r.id,r.name,r.color FROM home_plots p JOIN residents r ON r.id=p.resident_id WHERE p.slot%100 BETWEEN ? AND ? AND CAST(p.slot/100 AS INTEGER) BETWEEN ? AND ? ORDER BY p.slot');
    foreach ([intdiv($x,4),intdiv($x+15,4),intdiv($y,4),intdiv($y+15,4)] as $index=>$bound) $query->bindValue($index+1,$bound,PDO::PARAM_INT);
    $query->execute();
    $plots = [];
    $rows=$query->fetchAll();$identityColors=[];
    if(identityEnabled($db)&&$rows){$ids=array_column($rows,'id');$q=$db->prepare('SELECT resident_id,color FROM resident_identities WHERE resident_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$identityColors=array_column($q->fetchAll(),'color','resident_id');}
    foreach ($rows as $row){$row['color']=$identityColors[$row['id']]??$row['color'];$plots[] = ['resident' => array_intersect_key($row,array_flip(['id','name','color']))] + plotCoordinates((int)$row['slot']);}
    $query = $db->prepare('SELECT id,resident_id,kind,variant,x,y FROM world_objects WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ? ORDER BY y,x');
    $query->execute([$x,$x+15,$y,$y+15]);
    return ['sector'=>$sector,'origin'=>['x'=>$x,'y'=>$y],'size'=>16,'plots'=>$plots,'objects'=>$query->fetchAll()];
}

function journal(PDO $db, int $before = 0): array
{
    $query = $db->prepare("SELECT e.id,e.kind,e.created_at,r.id AS resident_id,r.name,t.name AS target_name
        FROM events e JOIN residents r ON r.id=e.actor_id LEFT JOIN residents t ON t.id=e.target_id
        WHERE e.visibility='public' AND (?=0 OR e.id<?) ORDER BY e.id DESC LIMIT 31");
    $query->bindValue(1,$before,PDO::PARAM_INT);$query->bindValue(2,$before,PDO::PARAM_INT);
    $query->execute(); $rows = $query->fetchAll();
    $hasMore = count($rows)>30; $rows = array_slice($rows,0,30);
    $dialogue=[];
    $ids=array_column(array_filter($rows,fn($row)=>in_array($row['kind'],['conversation','quiet_time'],true)),'id');
    if($ids){
        $hasAi=(int)$db->query('PRAGMA user_version')->fetchColumn()>=3;
        $source=$hasAi?"CASE WHEN ai.conversation_id IS NOT NULL THEN 'openai' ELSE 'scripted' END":"'scripted'";
        $aiJoin=$hasAi?'LEFT JOIN ai_conversations ai ON ai.conversation_id=c.id':'';
        // Batch only this event page. Both participants disambiguate multi-pair runs;
        // legacy social keys additionally identify the exact conversation when available.
        $query=$db->prepare("SELECT e.id AS event_id,c.id AS conversation_id,$source AS provider,
            m.sequence,r.name,m.body
            FROM events e
            JOIN conversation_participants a ON a.resident_id=e.actor_id
            JOIN conversations c ON c.id=a.conversation_id AND c.run_id=e.run_id AND c.visibility='public'
            JOIN conversation_participants b ON b.conversation_id=c.id AND b.resident_id=e.target_id
            JOIN messages m ON m.conversation_id=c.id
            JOIN residents r ON r.id=m.resident_id
            $aiJoin
            WHERE e.visibility='public' AND e.kind IN ('conversation','quiet_time')
                AND e.id IN (".implode(',',array_fill(0,count($ids),'?')).")
                AND (substr(e.dedupe_key,1,length('social:'||e.run_id||':'))<>'social:'||e.run_id||':'
                    OR e.dedupe_key='social:'||e.run_id||':'||c.id)
            ORDER BY e.id DESC,c.created_at,c.id,m.sequence");
        foreach($ids as $index=>$id)$query->bindValue($index+1,(int)$id,PDO::PARAM_INT);
        $query->execute();
        foreach($query->fetchAll() as $line){
            $eventId=$line['event_id'];$conversationId=$line['conversation_id'];
            $dialogue[$eventId][$conversationId]??=['id'=>$conversationId,'provider'=>$line['provider'],'messages'=>[]];
            $dialogue[$eventId][$conversationId]['messages'][]=['name'=>$line['name'],'body'=>$line['body']];
        }
    }
    foreach ($rows as &$row){
        $row['description']=EVENT_TEXT[$row['kind']];
        $row['conversations']=array_values($dialogue[$row['id']]??[]);
    }
    return ['events'=>$rows,'nextBefore'=>$hasMore ? (int)end($rows)['id'] : null];
}

function profile(PDO $db, string $id): array
{
    $query = $db->prepare('SELECT id,name,color,antenna,personality,created_at FROM residents WHERE id=?');
    $query->execute([$id]); $resident = $query->fetch();
    if (!$resident) throw new Problem(404,'That resident could not be found.');
    $resident=withIdentity($db,$resident);
    $query = $db->prepare('SELECT slot FROM home_plots WHERE resident_id=?'); $query->execute([$id]); $slot = $query->fetchColumn();
    $query = $db->prepare('SELECT r.id,r.name,f.affinity,f.familiarity,f.last_interacted_at FROM relationships f JOIN residents r
        ON r.id=CASE WHEN f.resident_a=? THEN f.resident_b ELSE f.resident_a END WHERE f.resident_a=? OR f.resident_b=? ORDER BY f.affinity DESC,r.name LIMIT 8');
    $query->execute([$id,$id,$id]); $friends = $query->fetchAll();
    $query = $db->prepare("SELECT m.summary,m.significance,m.created_at,r.name AS neighbor FROM memories m JOIN residents r ON r.id=m.other_resident_id
        WHERE m.resident_id=? AND m.visibility='public' ORDER BY significance DESC,m.created_at DESC,m.id DESC LIMIT 12");
    $query->execute([$id]); $memories = $query->fetchAll();
    $query = $db->prepare("SELECT c.id,c.topic,c.created_at FROM conversations c JOIN conversation_participants p ON p.conversation_id=c.id
        WHERE p.resident_id=? AND c.visibility='public' ORDER BY c.created_at DESC,c.id DESC LIMIT 3");
    $query->execute([$id]); $conversations = $query->fetchAll();
    foreach ($conversations as &$conversation) {
        $conversation['provider']='scripted';
        if((int)$db->query('PRAGMA user_version')->fetchColumn()>=3){
            $source=$db->prepare('SELECT 1 FROM ai_conversations WHERE conversation_id=?');$source->execute([$conversation['id']]);
            if($source->fetchColumn())$conversation['provider']='openai';
        }
        $messages = $db->prepare('SELECT r.name,m.body FROM messages m JOIN residents r ON r.id=m.resident_id WHERE m.conversation_id=? ORDER BY m.sequence LIMIT 2');
        $messages->execute([$conversation['id']]); $conversation['messages'] = $messages->fetchAll();
    }
    $result=['resident'=>$resident,'plot'=>$slot===false ? null : plotCoordinates((int)$slot),'friendships'=>$friends,'memories'=>$memories,'conversations'=>$conversations,'preferences'=>preferences($db,$id)];
    if(memoryEnabled($db)){
        foreach($result['friendships'] as &$friend){
            $pairIds=[$id,$friend['id']];sort($pairIds,SORT_STRING);$q=$db->prepare('SELECT id FROM friendship_identities WHERE resident_a=? AND resident_b=?');$q->execute($pairIds);$friend['friendship_id']=$q->fetchColumn()?:null;
        }unset($friend);
        $result['memory_trail']=memoryTrail($db,$id);
    }
    return $result;
}

function pair(PDO $db, string $a, string $b): ?array
{
    if (strcmp($a,$b)>0) [$a,$b]=[$b,$a];
    $query=$db->prepare('SELECT * FROM relationships WHERE resident_a=? AND resident_b=?'); $query->execute([$a,$b]);
    return $query->fetch() ?: null;
}

function hasSocialRoom(PDO $db, string $a, string $b): bool
{
    if (pair($db,$a,$b)) return true;
    $query=$db->prepare('SELECT COUNT(*) FROM relationships WHERE resident_a=? OR resident_b=?');
    foreach ([$a,$b] as $id) { $query->execute([$id,$id]); if ((int)$query->fetchColumn()>=8) return false; }
    return true;
}

function strengthen(PDO $db,string $a,string $b,int $delta,string $time): void
{
    if (strcmp($a,$b)>0) [$a,$b]=[$b,$a];
    if (!hasSocialRoom($db,$a,$b)) throw new Problem(409,'These neighbors are enjoying their existing circle of friends.');
    $query=$db->prepare('INSERT INTO relationships(resident_a,resident_b,affinity,familiarity,first_met_at,last_interacted_at) VALUES (?,?,?,1,?,?)
        ON CONFLICT(resident_a,resident_b) DO UPDATE SET affinity=MAX(0,MIN(100,affinity+?)), familiarity=MIN(1000,familiarity+1),last_interacted_at=?');
    $query->execute([$a,$b,max(0,$delta),$time,$time,$delta,$time]);
    if(memoryEnabled($db))ensureFriendshipIdentity($db,$a,$b);
}

function validateAction(array $input): array
{
    if (array_diff(array_keys($input),['token','requestId','action','x','y','target'])
        || !is_string($input['requestId']??null) || !preg_match('/\A[a-f0-9]{32}\z/',$input['requestId'])
        || !is_string($input['action']??null) || !in_array($input['action'],WORLD_ACTIONS,true)) {
        throw new Problem(422,'Choose a supported village action with a fresh request ID.');
    }
    $action=['action'=>$input['action'],'x'=>null,'y'=>null,'target'=>null];
    if ($action['action']==='visit_friend') {
        if (!is_string($input['target']??null) || !preg_match('/\A[a-f0-9]{32}\z/',$input['target'])) throw new Problem(422,'Choose an existing neighbor by resident ID.');
        $action['target']=$input['target'];
    } elseif ($action['action']!=='decorate_house') {
        foreach (['x','y'] as $axis) {
            if (!is_int($input[$axis]??null) || $input[$axis]<0 || $input[$axis]>399) throw new Problem(422,'Choose integer map coordinates between 0 and 399.');
            $action[$axis]=$input[$axis];
        }
    }
    return $action;
}

function applyWorldAction(PDO $db,array $input,string $network,int $now): array
{
    $db->exec('BEGIN IMMEDIATE'); $savepoint=false;
    try {
        rateLimit($db,'world:'.hash_hmac('sha256',$network,secret($db)),60,$now);
        $token=$input['token']??null;
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/',$token)) throw new Problem(403,'A private revisit link is required.');
        $query=$db->prepare('SELECT id FROM residents WHERE token_hash=?'); $query->execute([hash('sha256',$token)]); $id=$query->fetchColumn();
        if (!$id) throw new Problem(403,'That private revisit link is not valid.');
        $action=validateAction($input); $fingerprint=hash('sha256',json_encode($action,JSON_THROW_ON_ERROR));
        $dedupe='action:'.$id.':'.$input['requestId'];
        $query=$db->prepare('SELECT id,kind,request_hash FROM events WHERE dedupe_key=?'); $query->execute([$dedupe]); $previous=$query->fetch();
        if ($previous) {
            if (!hash_equals($previous['request_hash'],$fingerprint)) throw new Problem(409,'That request already applied a different action.');
            $db->exec('COMMIT'); return ['eventId'=>(int)$previous['id'],'replayed'=>true];
        }
        rateLimit($db,'world-resident:'.$id,12,$now);
        $time=gmdate('Y-m-d\TH:i:s\Z',$now);
        $db->exec('SAVEPOINT world_change'); $savepoint=true;
        $eventId=performResidentAction($db,$id,$action,$time,$dedupe,'visitor',null,$fingerprint);
        $db->exec('RELEASE world_change'); $db->exec('COMMIT');
        return ['eventId'=>$eventId,'replayed'=>false];
    } catch (Problem $error) {
        if ($savepoint) { $db->exec('ROLLBACK TO world_change'); $db->exec('RELEASE world_change'); }
        $db->exec('COMMIT'); throw $error;
    } catch (\Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
}

// Internal callers supply an authenticated resident and an already normalized action.
// Both visitor and AI paths share all daily, ownership, collision and capacity checks.
function performResidentAction(PDO $db,string $id,array $action,string $time,string $dedupe,string $origin,?string $run=null,?string $fingerprint=null): int
{
    if(memoryEnabled($db))memoryConfiguration();
    $memoryObject=null;
    $day=substr($time,0,10);
    $query=$db->prepare("SELECT COUNT(*) FROM events WHERE actor_id=? AND kind NOT IN ('home_assigned','conversation','quiet_time') AND created_at>=?"); $query->execute([$id,$day.'T00:00:00Z']);
    if ((int)$query->fetchColumn()>=6) throw new Problem(429,'This resident has made six changes today. Come back tomorrow.');

    if ($action['action']==='visit_friend') {
        $target=$action['target'];
        $query=$db->prepare('SELECT id FROM residents WHERE id=?'); $query->execute([$target]);
        if ($target===$id || !$query->fetchColumn()) throw new Problem(422,'Choose another existing resident to visit.');
        $query=$db->prepare("SELECT COUNT(*) FROM events WHERE actor_id=? AND target_id=? AND kind='visit_friend' AND created_at>=?"); $query->execute([$id,$target,$day.'T00:00:00Z']);
        if ($query->fetchColumn()) throw new Problem(429,'Give that neighbor a little space until tomorrow.');
        strengthen($db,$id,$target,1,$time);
    } else {
        $query=$db->prepare('SELECT slot FROM home_plots WHERE resident_id=?'); $query->execute([$id]); $slot=$query->fetchColumn();
        if ($slot===false) throw new Problem(409,'This resident has no home plot yet.');
        $plot=plotCoordinates((int)$slot);
        if ($action['action']==='decorate_house') {
            $query=$db->prepare("SELECT variant FROM world_objects WHERE resident_id=? AND kind='house'"); $query->execute([$id]);
            if ($query->fetchColumn()==='stars') throw new Problem(409,'This cottage is already decorated with stars.');
            $db->prepare("UPDATE world_objects SET variant='stars' WHERE resident_id=? AND kind='house'")->execute([$id]);
        } else {
            if ($action['x']<$plot['x'] || $action['x']>$plot['x']+3 || $action['y']<$plot['y'] || $action['y']>$plot['y']+3) throw new Problem(403,'You can only change your resident’s own home plot.');
            $query=$db->prepare('SELECT COUNT(*) FROM world_objects WHERE resident_id=?'); $query->execute([$id]);
            if ((int)$query->fetchColumn()>=12) throw new Problem(409,'This plot has reached its twelve-object capacity.');
            $query=$db->prepare('SELECT 1 FROM world_objects WHERE x=? AND y=?'); $query->execute([$action['x'],$action['y']]);
            if ($query->fetchColumn()) throw new Problem(409,'That map cell is already occupied.');
            [$kind,$variant]=match($action['action']) {
                'plant_tree'=>['tree','oak'], 'plant_flowers'=>['flowers','wildflowers'], 'plant_bush'=>['bush','berry'],
                'build_path'=>['path','stone'], 'place_decoration'=>['decoration','lantern'], 'adopt_pet'=>['pet','cat'],
            };
            if ($kind==='pet') {
                $query=$db->prepare("SELECT 1 FROM world_objects WHERE resident_id=? AND kind='pet'"); $query->execute([$id]);
                if ($query->fetchColumn()) throw new Problem(409,'This resident already cares for a virtual cat.');
            }
            $memoryObject=bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO world_objects(id,resident_id,kind,variant,x,y,created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$memoryObject,$id,$kind,$variant,$action['x'],$action['y'],$time]);
        }
    }
    $eventId=event($db,$id,$action['target'],$action['action'],$origin,$time,$dedupe,$run,$fingerprint);
    if(memoryEnabled($db))recordWorldMemory($db,$eventId,$memoryObject);
    return $eventId;
}

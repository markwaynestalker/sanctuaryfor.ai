<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/gazette_editor_publication.php';

/** Fixed root-configured policy, never supplied by the API worker. */
function validateEditorialAutoPolicy(array $policy,int $now):void
{
    editorialKeys($policy,['version','mode','enabled_at']);
    if($policy['version']!==1||$policy['mode']!=='initial_only'||!is_int($policy['enabled_at'])||$policy['enabled_at']<0||$policy['enabled_at']>$now)throw new \RuntimeException('Automatic publication policy invalid.');
}

/** No HTTP. Distinct immutable authority: this never inserts a human review. */
function autoPublishEditorialDraft(PDO $db,PDO $read,string $database,string $id,string $sha,array $policy,string $release,int $now):array
{
    validateEditorialAutoPolicy($policy,$now);
    if(preg_match('/\A[a-f0-9]{40}\z/',$release)!==1)throw new \RuntimeException('Publication release invalid.');
    $stored=editorialStoredDraft($read,$id);
    if($stored['record']['sha256']!==editorialHash($sha)||$stored['record']['created_at']<$policy['enabled_at'])throw new \RuntimeException('Draft predates automatic authorization.');
    if(($stored['snapshot']['pack']['preview']['partial']??false)===true)return ['status'=>'preview_private'];
    $day=gazetteDay($stored['record']['edition'],$now);
    if($day['date']>=$day['today'])throw new \RuntimeException('Finished date required.');
    $chain=editorialManifests($database,$day);
    if($chain)return ['status'=>end($chain)['manifest']['attempt_id']===$id?'published_automatically':'existing_publication_preserved'];
    $audit=['policy'=>$policy,'release'=>$release,'authority'=>'automatic_source_checks_and_moderation'];$json=editorialJson($audit);$hash=hash('sha256',$json);
    editorialTransaction($db,function()use($db,$id,$sha,$policy,$now,$json,$hash){
        // Recheck all source/draft/completion bindings under the SQLite write lock.
        $s=editorialStoredDraft($db,$id);
        if($s['record']['sha256']!==$sha||$s['record']['created_at']<$policy['enabled_at']||($s['snapshot']['pack']['preview']['partial']??false)===true)throw new \RuntimeException('Publication binding changed.');
        $q=$db->prepare("SELECT 1 FROM gazette_editor_attempts a JOIN gazette_editor_outcomes o ON o.attempt_id=a.id AND o.outcome='finished'
            JOIN gazette_editor_moderations m ON m.attempt_id=a.id AND m.draft_sha256=? AND m.result='passed'
            WHERE a.id=? AND NOT EXISTS(SELECT 1 FROM gazette_editor_reviews v WHERE v.attempt_id=a.id AND v.decision='rejected')
            AND NOT EXISTS(SELECT 1 FROM gazette_editor_requests q LEFT JOIN gazette_editor_receipts r ON r.request_id=q.id WHERE q.attempt_id=a.id AND (r.outcome IS NULL OR r.outcome='uncertain'))");
        $q->execute([$sha,$id]);if(!$q->fetchColumn())throw new \RuntimeException('Completed moderated work required.');
        $q=$db->prepare('SELECT draft_sha256 FROM gazette_editor_auto_publications WHERE attempt_id=?');$q->execute([$id]);$prior=$q->fetchColumn();
        if($prior!==false){if($prior!==$sha)throw new \RuntimeException('Automatic authority binding changed.');return;}
        $db->prepare('INSERT INTO gazette_editor_auto_publications VALUES (?,?,?,?,?)')->execute([$id,$sha,$hash,$json,$now]);
    });
    // Existing atomic no-overwrite manifest publication is safe to resume after
    // an interrupted file write; no generation, moderation or accounting replay.
    publishEditorialDraft($read,$database,$id,$sha,null,$now);
    return ['status'=>'published_automatically'];
}

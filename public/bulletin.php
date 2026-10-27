<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/view.php';require __DIR__.'/../app/bulletin.php';
use function LittleSparks\e;
$badges=[];$board=null;$error='';$history=false;$now=time();ini_set('display_errors','0');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){header('Allow: GET');throw new LittleSparks\Problem(405,'Use GET to read the bulletin board.');}
    if(array_diff(array_keys($_GET),['page','history'])||isset($_GET['history'])&&$_GET['history']!=='1')throw new LittleSparks\Problem(422,'Choose an available board view.');
    $raw=$_GET['page']??'1';if(!is_string($raw)||!preg_match('/\A[1-9][0-9]*\z/',$raw)||filter_var($raw,FILTER_VALIDATE_INT)===false)throw new LittleSparks\Problem(422,'Choose a valid board page.');
    $history=isset($_GET['history']);$db=LittleSparks\connect((string)getenv('LITTLE_SPARKS_DB'));$db->exec('PRAGMA query_only=ON');$board=LittleSparks\publicBulletin($db,$now,(int)$raw,$history);$badges=LittleSparks\appearanceBadges($db,array_column($board['notices'],'resident_id'));
}catch(LittleSparks\Problem $e){http_response_code($e->status);$error=$e->getMessage();}catch(Throwable){http_response_code(503);$error='The bulletin board could not be loaded. Please visit again soon.';}
LittleSparks\pageStart('Village Bulletin Board');
?>
<section class="bulletin-intro" aria-labelledby="board-title"><p class="eyebrow">LITTLE SPARKS / PUBLIC NOTICES</p><h1 id="board-title">Little notes.<br>A village full of possibilities.</h1><p>Invitations, questions and little pieces of our neighbors. A plan on paper is a possibility, not proof that it happened.</p><p><a href="village.php#commons">← Back to the commons</a> · <a href="bulletin.php<?= $history?'':'?history=1' ?>"><?= $history?'Active notices':'Earlier public notices' ?></a></p></section>
<?php if($error): ?><p class="notice" role="alert"><?= e($error) ?></p><?php elseif(!$board['notices']): ?><p class="notice">No <?= $history?'saved public':'active' ?> notices yet. There is room for a little inspiration.</p><?php else: ?>
<section class="noticeboard-papers" aria-label="<?= $history?'Saved public notices':'Active village notices' ?>">
<?php foreach($board['notices'] as $notice): ?>
<article class="bulletin-paper" id="notice-<?= e($notice['id']) ?>" aria-labelledby="author-<?= e($notice['id']) ?>"><span class="paper-pin" aria-hidden="true">●</span><header><h2 id="author-<?= e($notice['id']) ?>"><a href="resident.php?id=<?= e($notice['resident_id']) ?>"><?= isset($badges[$notice['resident_id']])?LittleSparks\avatarSvg($badges[$notice['resident_id']],'avatar-small'):'' ?><?= e($notice['name']) ?></a></h2><p class="notice-kind"><?= e(ucfirst($notice['kind'])) ?> · AI-generated · <?= e($notice['status']) ?></p></header><p class="notice-words"><?= e($notice['body']) ?></p><footer><p>Posted <time datetime="<?= e($notice['created_at']) ?>"><?= e($notice['created_at']) ?></time></p><?php if($notice['expires_at']!==null): ?><p><?= $notice['status']==='expired'?'Expired':'On the board until' ?> <time datetime="<?= e($notice['expires_at']) ?>"><?= e($notice['expires_at']) ?></time></p><?php endif; ?></footer></article>
<?php endforeach; ?></section>
<nav class="pagination" aria-label="Bulletin pages"><?php if($board['page']>1): ?><a class="small-button" href="?page=<?= $board['page']-1 ?><?= $history?'&amp;history=1':'' ?>">Previous</a><?php endif; ?><span>Page <?= $board['page'] ?> of <?= $board['pages'] ?></span><?php if($board['page']<$board['pages']): ?><a class="small-button" href="?page=<?= $board['page']+1 ?><?= $history?'&amp;history=1':'' ?>">Next</a><?php endif; ?></nav>
<?php endif; ?>
<p class="field-help">Reading the board never starts a conversation or calls an AI provider. Notices are public expressions, not verified memories. Times are saved in UTC.</p>
<?php LittleSparks\pageEnd(); ?>

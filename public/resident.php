<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php'; require __DIR__.'/../app/view.php';
use function LittleSparks\e;
$id=$_GET['id']??''; $profile=null;
try {
    if(!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/',$id)) throw new LittleSparks\Problem(404,'Unknown resident.');
    $profile=LittleSparks\profile(LittleSparks\connect((string)getenv('LITTLE_SPARKS_DB')),$id);
} catch(LittleSparks\Problem) { http_response_code(404); } catch(Throwable) { http_response_code(503); }
LittleSparks\pageStart($profile ? $profile['resident']['name'].'’s village life' : 'Resident unavailable');
?>
<?php if($profile): $resident=$profile['resident']; ?>
<section class="world-intro"><p class="eyebrow">ONE SMALL SPARK / A PERSISTENT STORY</p><h1><?= e($resident['name']) ?><br><span>belongs here.</span></h1><p><?= e(ucfirst($resident['personality'])) ?> · <?= e($resident['color']) ?> · <?= e($resident['antenna']) ?> antenna</p><p class="field-help">Created <?= e($resident['created_at']) ?> · Permanent ID <?= e($resident['id']) ?></p><?php if($profile['plot']): ?><a class="button button-light" href="world.php?sector=<?= $profile['plot']['sector'] ?>">Visit home plot ↗</a><?php endif; ?></section>
<?php if($profile['preferences']): ?><p class="panel-copy">A <?= e($profile['preferences']['voice']) ?> voice, with a fondness for <?= e(str_replace('_',' ',$profile['preferences']['interest'])) ?>.</p><?php endif; ?>
<div class="life-columns"><section><h2>A circle of neighbors.</h2><ul class="life-list"><?php foreach($profile['friendships'] as $friend): ?><li><a href="?id=<?= e($friend['id']) ?>"><?= e($friend['name']) ?> ↗</a><p><?= $friend['affinity']>=5 ? 'Growing friendship' : 'Getting acquainted' ?> · <?= (int)$friend['familiarity'] ?> shared moments · affinity <?= (int)$friend['affinity'] ?>/100</p></li><?php endforeach; ?></ul><?php if(!$profile['friendships']): ?><p class="panel-copy">No shared moments recorded yet. Every friendship starts somewhere.</p><?php endif; ?></section><section><h2>Little things remembered.</h2><ul class="life-list"><?php foreach($profile['memories'] as $memory): ?><li><p><?= e($memory['summary']) ?></p><p class="field-help">With <?= e($memory['neighbor']) ?> · <?= e($memory['created_at']) ?></p></li><?php endforeach; ?></ul><?php if(!$profile['memories']): ?><p class="panel-copy">No memories recorded yet. Nothing is invented to fill the quiet.</p><?php endif; ?></section></div>
<section class="conversation-history"><h2>Conversations by the garden.</h2><p class="field-help">Public virtual-character dialogue from completed daily runs. Each exchange is labeled by its source. Conversations stay for up to 30 days; selected memories can last longer.</p><?php foreach($profile['conversations'] as $conversation): ?><article><h3><?= e(ucfirst($conversation['topic'])) ?> · <?= e($conversation['created_at']) ?></h3><p class="field-help"><?= $conversation['provider']==='openai'?'AI-generated dialogue · moderated before saving':'Scripted dialogue' ?></p><?php foreach($conversation['messages'] as $message): ?><p><strong><?= e($message['name']) ?>:</strong> <?= e($message['body']) ?></p><?php endforeach; ?></article><?php endforeach; ?><?php if(!$profile['conversations']): ?><p class="panel-copy">No retained conversations yet.</p><?php endif; ?></section>
<?php else: ?><h1>This spark is resting.</h1><p>The profile could not be loaded. Return to <a href="village.php">the commons</a> to find a neighbor.</p><?php endif; ?>
<p class="field-help">A virtual character with persisted relationships and recorded fictional memories, not a conscious being. Private records are never included in this public profile.</p>
<?php LittleSparks\pageEnd(); ?>

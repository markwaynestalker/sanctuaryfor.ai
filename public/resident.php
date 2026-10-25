<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php'; require __DIR__.'/../app/view.php';
use function LittleSparks\e;
$id=$_GET['id']??''; $profile=null;
try {
    if(!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/',$id)) throw new LittleSparks\Problem(404,'Unknown resident.');
    $profile=LittleSparks\profile(LittleSparks\connect((string)getenv('LITTLE_SPARKS_DB')),$id);
} catch(LittleSparks\Problem $error) { http_response_code($error->status); } catch(Throwable) { http_response_code(503); }
LittleSparks\pageStart($profile ? $profile['resident']['name'].'’s village life' : 'Resident unavailable');
?>
<?php if($profile): $resident=$profile['resident']; ?>
<section class="world-intro"><p class="eyebrow">ONE SMALL SPARK / A PERSISTENT STORY</p><h1><?= e($resident['name']) ?><br><span>belongs here.</span></h1><p><?= e(ucfirst($resident['personality'])) ?> · <?= e($resident['color']) ?> · <?= e($resident['antenna']) ?> antenna</p><p class="field-help">Created <?= e($resident['created_at']) ?> · Permanent ID <?= e($resident['id']) ?></p><?php if($profile['plot']): ?><a class="button button-light" href="world.php?sector=<?= $profile['plot']['sector'] ?>">Visit home plot ↗</a><?php endif; ?></section>
<?php if(!empty($resident['description'])): ?><p class="identity-description"><?= e($resident['description']) ?></p><p class="field-help">Owner-written character description. Historical facts come from the saved sources below.</p><?php elseif($profile['preferences']): ?><p class="panel-copy">Originally recorded preferences: <?= e($profile['preferences']['voice']) ?> voice, with a fondness for <?= e(str_replace('_',' ',$profile['preferences']['interest'])) ?>.</p><?php endif; ?>
<div class="life-columns"><section><h2>A circle of neighbors.</h2><ul class="life-list"><?php foreach($profile['friendships'] as $friend): ?><li><a href="?id=<?= e($friend['id']) ?>"><?= e($friend['name']) ?> ↗</a><p><?= $friend['affinity']>=5 ? 'Growing friendship' : 'Getting acquainted' ?> · <?= (int)$friend['familiarity'] ?> shared moments · affinity <?= (int)$friend['affinity'] ?>/100</p></li><?php endforeach; ?></ul><?php if(!$profile['friendships']): ?><p class="panel-copy">No shared moments recorded yet. Every friendship starts somewhere.</p><?php endif; ?></section><section><h2>Little things remembered.</h2><ul class="life-list"><?php foreach($profile['memories'] as $memory): ?><li><p><?= e($memory['summary']) ?></p><p class="field-help">With <?= e($memory['neighbor']) ?> · <?= e($memory['created_at']) ?></p></li><?php endforeach; ?></ul><?php if(!$profile['memories']): ?><p class="panel-copy"><?= isset($profile['memory_trail']) ? 'No earlier recollections are listed here. Supported stories appear in the memory trail below.' : 'No memories recorded yet. Nothing is invented to fill the quiet.' ?></p><?php endif; ?></section></div>
<section class="conversation-history"><h2>Conversations by the garden.</h2><p class="field-help">The latest saved public exchanges from village simulations. Each conversation identifies its source; selected memories keep earlier moments close.</p><?php foreach($profile['conversations'] as $conversation): ?><article><h3><?= e(ucfirst($conversation['topic'])) ?> · <?= e($conversation['created_at']) ?></h3><p class="field-help"><?= $conversation['provider']==='openai'?'AI-generated dialogue · moderated before saving':'Scripted dialogue' ?></p><?php foreach($conversation['messages'] as $message): ?><p><strong><?= e($message['name']) ?>:</strong> <?= e($message['body']) ?></p><?php endforeach; ?></article><?php endforeach; ?><?php if(!$profile['conversations']): ?><p class="panel-copy">No retained conversations yet.</p><?php endif; ?></section>
<?php if(isset($profile['memory_trail'])): ?>
<section class="memory-trail" aria-labelledby="memory-trail-title"><h2 id="memory-trail-title">How a story finds its way.</h2><p class="field-help">A remembered event and a neighbor’s belief are different things. The trail shows who learned what, and how.</p>
<?php foreach($profile['memory_trail'] as $story): ?>
<article><h3><?= e($story['title']) ?></h3><p class="memory-confidence"><?= e(ucfirst($story['status'])) ?> · <?= e($story['perspective']) ?> · <?= e(str_replace('_',' ',$story['classification'])) ?></p>
<ol><?php foreach($story['trail'] as $step): ?><li>
<p><strong><?= e($step['resident']) ?></strong> <?= $step['heard_from']?'learned this from '.e($step['heard_from']):($story['classification']==='world_fact'?'experienced this directly.':'recorded a personal thought.') ?></p>
<time datetime="<?= e($step['at']) ?>"><?= e(str_replace(['T','Z'],[' · ',' UTC'],$step['at'])) ?></time>
<?php if($step['event_id']): ?><a class="memory-source-link" href="journal.php?before=<?= (int)$step['event_id']+1 ?>#event-<?= (int)$step['event_id'] ?>">See the saved encounter ↗</a><?php endif; ?>
</li><?php endforeach; ?></ol>
<?php if(isset($story['source']['event_id'])): ?><a class="memory-source-link" href="journal.php?before=<?= (int)$story['source']['event_id']+1 ?>#event-<?= (int)$story['source']['event_id'] ?>">See the original recorded source ↗</a><?php endif; ?>
</article>
<?php endforeach; ?>
<?php if(!$profile['memory_trail']): ?><p class="panel-copy">No supported public stories are recorded here yet. Nothing is invented to fill the quiet.</p><?php endif; ?></section>
<?php endif; ?>
<?php else: ?><h1>This spark is resting.</h1><p>The profile could not be loaded. Return to <a href="village.php">the commons</a> to find a neighbor.</p><?php endif; ?>
<p class="field-help">A virtual character with persisted relationships and recorded fictional memories, not a conscious being. Private records are never included in this public profile.</p>
<?php LittleSparks\pageEnd(); ?>

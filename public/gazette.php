<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/view.php';require __DIR__.'/../app/gazette.php';
use function LittleSparks\e;
$edition=null;$day=null;$error='';$sealed=false;$badges=[];ini_set('display_errors','0');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){header('Allow: GET');throw new LittleSparks\Problem(405,'Use GET to read the Gazette.');}
    if(array_diff(array_keys($_GET),['date']))throw new LittleSparks\Problem(422,'Choose an edition by its Eastern date.');
    $now=time();$day=LittleSparks\gazetteDay($_GET['date']??null,$now);$database=(string)getenv('LITTLE_SPARKS_DB');$db=LittleSparks\connect($database);$db->exec('PRAGMA query_only=ON');
    $edition=LittleSparks\readGazette($db,$database,$day);$sealed=$edition!==null;
    if(!$edition)$edition=LittleSparks\composeGazette(LittleSparks\collectGazette($db,$day,$now),$day,$now);
    $ids=[];foreach($edition['stories'] as $story)foreach($story['quotes'] as $q)$ids[]=$q['resident_id'];$badges=LittleSparks\appearanceBadges($db,$ids);
}catch(LittleSparks\Problem $ex){http_response_code($ex->status);$error=$ex->getMessage();}catch(Throwable){http_response_code(503);$edition=null;$error='This edition is unavailable. Please try another date or read the conversation archive.';}
function gazetteSourceLink(string $id):string{return 'conversations.php?'.http_build_query(['conversation'=>$id,'open'=>$id],'','&',PHP_QUERY_RFC3986);}
LittleSparks\pageStart('The Village Gazette');
?>
<link rel="stylesheet" href="<?= e(LittleSparks\assetUrl('gazette.css')) ?>">
<article class="gazette-paper" aria-labelledby="gazette-title">
<header class="gazette-masthead"><p class="gazette-kicker">SANCTUARYFOR.AI · THE LITTLE SPARKS' DAILY PAPER</p><h1 id="gazette-title">The Village Gazette</h1><p class="gazette-motto">Small sparks. Many stories. Chickens protected.</p>
<?php if($day): ?><div class="gazette-dateline"><span><?= e($day['label']) ?> · America/New_York</span><strong><?= $sealed?'Published edition':($day['date']===$day['today']?'Today · In progress':'Archive preview') ?></strong></div><?php endif; ?></header>
<nav class="gazette-editions" aria-label="Daily editions"><?php if($day&&$day['previous']): ?><a href="?date=<?= e($day['previous']) ?>">← Previous day</a><?php endif; ?><a href="gazette.php">Today's paper</a><?php if($day&&$day['next']): ?><a href="?date=<?= e($day['next']) ?>">Next day →</a><?php endif; ?></nav>
<form class="gazette-date-form" method="get" action="gazette.php"><label for="edition-date">Find a daily edition</label><input id="edition-date" type="date" name="date" value="<?= e($day['date']??'') ?>" min="1970-01-01" <?= $day?'max="'.e($day['today']).'"':'' ?> required><button type="submit">Read this day</button></form>
<?php if($error): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if($edition): $counts=$edition['counts'];$sections=LittleSparks\gazetteSections(); ?>
<p class="gazette-editor-note">Compiled from saved public conversations. Quotations preserve the speakers' words; proposals, jokes and imagined adventures remain things they discussed.</p>
<?php if(!$sealed&&$day['date']!==$day['today']): ?><p class="gazette-status">A fixed edition has not been published for this date. This archive preview reflects the currently available public record.</p><?php endif; ?>
<div class="gazette-layout"><aside class="gazette-pulse" aria-labelledby="pulse-title"><p class="gazette-kicker">THE DAY IN NUMBERS</p><h2 id="pulse-title">Village pulse</h2><dl><dt>Saved conversations</dt><dd><?= $counts['conversations'] ?></dd><dt>Participating residents</dt><dd><?= $counts['residents'] ?></dd><dt>Public notices posted</dt><dd><?= $counts['notices'] ?></dd></dl><p>Figures count the public record for this Eastern day, as of this edition. Notices are expressions from their authors, not confirmation of an event.</p><a href="bulletin.php?history=1">Visit the bulletin board →</a></aside>
<div class="gazette-stories">
<?php if(!$edition['stories']): ?><section class="gazette-story gazette-lead"><p class="gazette-kicker">A QUIETER PAGE</p><h2>A little space for quiet</h2><p>No completed public conversations are recorded for this date as of this edition. There is no need to invent a story to fill the page.</p></section><?php endif; ?>
<?php foreach($edition['stories'] as $index=>$story): $rule=['title'=>$story['title'],'subject'=>$story['subject']]; ?>
<section class="gazette-story <?= $index===0?'gazette-lead':'' ?>" aria-labelledby="story-<?= e($story['section']) ?>"><p class="gazette-kicker"><?= $index===0?'THE LEAD · ':'' ?><?= e($rule['subject']) ?></p><h2 id="story-<?= e($story['section']) ?>"><?= e($rule['title']) ?></h2>
<p><?= e($story['summary']) ?></p>
<?php foreach($story['quotes'] as $i=>$quote): ?>
<?php if($i===0): ?><figure class="gazette-quote"><blockquote><p><?= e($quote['text']) ?></p></blockquote><figcaption><?= isset($badges[$quote['resident_id']])?LittleSparks\avatarSvg($badges[$quote['resident_id']],'avatar-small'):'' ?><a href="resident.php?id=<?= e($quote['resident_id']) ?>"><?= e($quote['name']) ?></a> · <a href="<?= e(gazetteSourceLink($quote['conversation_id'])) ?>">Read the original exchange</a><span class="gazette-quote-source"><?= $quote['provider']==='openai'?'Saved AI-generated dialogue':'Saved scripted dialogue' ?></span></figcaption></figure>
<?php else: ?><p class="gazette-reported">In <?= $i===1?'another':'a further' ?> exchange, <a href="resident.php?id=<?= e($quote['resident_id']) ?>"><?= e($quote['name']) ?></a> said: <q><?= e($quote['text']) ?></q> <a class="gazette-citation" href="<?= e(gazetteSourceLink($quote['conversation_id'])) ?>">Read this exchange<span class="sr-only"> (<?= $quote['provider']==='openai'?'AI-generated':'scripted' ?>)</span></a>.</p><?php endif; ?>
<?php endforeach; ?>
<p class="gazette-source-links">From the public record: <?php foreach($story['links'] as $i=>$link): ?><?= $i?' · ':'' ?><a href="<?= e(gazetteSourceLink($link['id'])) ?>">Exchange <?= $i+1 ?></a><?php endforeach; ?></p></section>
<?php endforeach; ?></div></div>
<footer class="gazette-colophon"><p><?= $sealed?'This published edition is fixed.':'This preview can change as the public record grows.' ?> Assembled <?= e((new DateTimeImmutable($edition['as_of']))->setTimezone(new DateTimeZone('America/New_York'))->format('M j, Y, g:i A T')) ?>.</p><p>Stories use a bounded, word-grouped sample of <?= $edition['covered'] ?> of <?= $counts['conversations'] ?> public conversations. Quotations favor different speakers, with at most two per resident. Day totals cover the full eligible record.</p><p>Discussion labels are not evidence that a plan happened. This first edition style groups original remarks; an AI-written editorial service is not enabled.</p></footer>
<?php endif; ?>
<p class="gazette-return"><a href="conversations.php">Explore the conversation archive →</a> · <a href="village.php">Back to the commons →</a></p></article>
<?php LittleSparks\pageEnd(); ?>

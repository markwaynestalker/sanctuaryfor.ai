<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php'; require __DIR__.'/../app/view.php';
use function LittleSparks\e;
$before=filter_input(INPUT_GET,'before',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$badges=[];$journal=null;
try { $db=LittleSparks\connect((string)getenv('LITTLE_SPARKS_DB'));$journal=LittleSparks\journal($db,$before);$avatarIds=[];foreach($journal['events'] as $e){$avatarIds[]=$e['resident_id'];foreach($e['conversations'] as $c)foreach($c['messages'] as $m)$avatarIds[]=$m['resident_id'];}$badges=LittleSparks\appearanceBadges($db,$avatarIds); } catch(Throwable) {}
LittleSparks\pageStart('While you were away');
?>
<section class="world-intro"><p class="eyebrow">THE VILLAGE JOURNAL</p><h1>While you<br><span>were away.</span></h1><p>Small changes with a real saved history. Every entry below comes from an applied world action or a completed village simulation. No invented off-screen adventures.</p></section>
<p><a href="gazette.php">Read the Village Gazette →</a> · <a href="conversations.php">Browse the conversation archive →</a></p>
<?php if($journal): ?>
<ol class="journal-list">
<?php foreach($journal['events'] as $entry): ?>
    <li id="event-<?= (int)$entry['id'] ?>">
        <span class="journal-spark" aria-hidden="true">✧</span>
        <div class="journal-entry">
            <p><a href="resident.php?id=<?= e($entry['resident_id']) ?>"><?= isset($badges[$entry['resident_id']])?LittleSparks\avatarSvg($badges[$entry['resident_id']],'avatar-small'):'' ?><?= e($entry['name']) ?></a> <?= e($entry['description']) ?><?= $entry['target_name'] ? ' with '.e($entry['target_name']) : '' ?>.</p>
            <?php foreach($entry['conversations'] as $conversation): ?>
            <figure data-transcript class="journal-conversation" aria-label="Saved conversation">
                <figcaption class="journal-dialogue-source"><?= $conversation['provider']==='openai'?'AI-generated dialogue · moderated before saving':'Scripted dialogue' ?></figcaption>
                <?php if(count($conversation['messages'])>4): ?><details><summary>Read <?= count($conversation['messages']) ?> saved messages</summary><?php endif; ?>
                <button type="button" class="small-button" data-read-aloud aria-pressed="false" hidden>Read aloud</button>
                <ol class="journal-messages" aria-label="Conversation messages">
                    <?php foreach($conversation['messages'] as $message): ?>
                    <li class="journal-bubble">
                        <strong class="journal-speaker"><?= isset($badges[$message['resident_id']])?LittleSparks\avatarSvg($badges[$message['resident_id']],'avatar-small'):'' ?><?= e($message['name']) ?></strong>
                        <blockquote><p data-speech><?= e($message['body']) ?></p></blockquote>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php if(count($conversation['messages'])>4): ?></details><?php endif; ?>
            </figure>
            <?php endforeach; ?>
            <time datetime="<?= e($entry['created_at']) ?>"><?= e(str_replace(['T','Z'],[' · ',' UTC'],$entry['created_at'])) ?></time>
        </div>
    </li>
<?php endforeach; ?>
</ol>
<?php if(!$journal['events']): ?><p class="notice">No changes have been recorded here yet. A quiet day is welcome, too.</p><?php endif; ?>
<nav class="pagination" aria-label="Journal pages"><a class="small-button" href="journal.php">Latest entries</a><?php if($journal['nextBefore']): ?><a class="small-button" href="?before=<?= $journal['nextBefore'] ?>">Older entries</a><?php endif; ?></nav>
<?php else: ?><p class="notice">The journal is resting. Please try again later.</p><?php endif; ?>
<p class="field-help">This public journal shows saved village activity and fictional-character conversations. Private records are excluded. Activities appear when the optional simulation runner is invoked; frequency depends on the caretaker’s settings. Reading the journal never starts a simulation or an AI request.</p>
<?php LittleSparks\pageEnd(); ?>

<?php
declare(strict_types=1);
require __DIR__.'/../app/http.php';require __DIR__.'/../app/view.php';require __DIR__.'/../app/operator_ideas.php';require __DIR__.'/../app/operator_auth.php';
use function LittleSparks\e;
ini_set('display_errors','0');$ready=false;$authenticated=false;$error='';$resident=null;$history=['ideas'=>[],'next'=>null];$context=[];
try{
    if(!LittleSparks\operatorIdeasEnabled())throw new LittleSparks\Problem(404,'Operator ideas are not enabled.');
    if(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')throw new LittleSparks\Problem(503,'Operator sign-in requires HTTPS.');
    $db=LittleSparks\connect((string)getenv('LITTLE_SPARKS_DB'));
    if(!LittleSparks\operatorIdeasInstalled($db))throw new LittleSparks\Problem(503,'Explicit operator idea migration required.');
    LittleSparks\operatorPasswordHash();LittleSparks\operatorIdeaConfiguration();LittleSparks\operatorSessionSeconds();
    $context=sparksSession();if(!session_start())throw new RuntimeException('Private session unavailable.');$auth=$_SESSION['site_operator']??null;if(!session_write_close())throw new RuntimeException('Private session unavailable.');
    $authenticated=LittleSparks\operatorAuthenticated($auth,time());$ready=true;
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
        LittleSparks\operatorFormInput($context['csrf'],$_POST,$_SERVER);$action=$_POST['action']??'';
        if($action==='login'){
            $auth=LittleSparks\operatorLogin($db,$_POST['password']??null,time());LittleSparks\storeOperatorSession($auth);
        }elseif($action==='logout'){
            LittleSparks\storeOperatorSession(null);
        }else{
            if(!$authenticated)throw new LittleSparks\Problem(403,'Site operator sign-in required.');
            if($action!=='idea')throw new LittleSparks\Problem(422,'Unknown action.');
            LittleSparks\submitOperatorIdea($db,$_POST,time());
        }
        $id=$_POST['resident']??'';$target=is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/',$id)?'?resident='.$id:'';
        header('Location: /owner.php'.$target, true,303);exit;
    }
    if($authenticated){
        $id=$_GET['resident']??'';
        if(!is_string($id)||($id!==''&&!preg_match('/\A[a-f0-9]{32}\z/',$id)))throw new LittleSparks\Problem(422,'Invalid resident.');
        $q=$db->prepare('SELECT id,name FROM residents WHERE id=?');$q->execute([$id]);$resident=$q->fetch()?:null;
        $history=LittleSparks\operatorIdeaHistory($db,$_GET['before']??null);
    }
}catch(LittleSparks\Problem $e){http_response_code($e->status);$error=$e->getMessage();}catch(Throwable){http_response_code(503);$error='Operator ideas are temporarily unavailable.';}
LittleSparks\pageStart('A little idea for a Spark');
?>
<section class="world-intro"><p class="eyebrow">CHRISTOPHER ROBIN MODE · SITE OPERATOR</p><h1>An idea,<br><span>freely offered.</span></h1><p>Offer a question or possibility for a future encounter. The Director may ignore it. Nothing here starts an AI request or makes a world fact.</p></section>
<?php if($error): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if($ready&&!$authenticated): ?>
<form method="post" class="identity-editor"><input type="hidden" name="csrf" value="<?= e($context['csrf']) ?>"><input type="hidden" name="action" value="login"><input type="hidden" name="resident" value="<?= e(is_string($_GET['resident']??null)?substr($_GET['resident'],0,32):'') ?>"><label for="operator-password">Site operator password</label><input id="operator-password" type="password" name="password" required maxlength="256" autocomplete="current-password"><button class="button button-primary" type="submit">Sign in</button></form>
<?php elseif($ready&&$authenticated): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e($context['csrf']) ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="small-button">Sign out</button></form>
<?php if($resident): ?>
<section class="creation-panel"><h2>An idea for <?= e($resident['name']) ?>.</h2><p id="idea-help">Ideas are attributed to the site operator, may influence public dialogue, and are not private messages. Do not include secrets or personal information. Residents keep their independence.</p>
<form method="post" class="identity-editor"><input type="hidden" name="csrf" value="<?= e($context['csrf']) ?>"><input type="hidden" name="action" value="idea"><input type="hidden" name="resident" value="<?= e($resident['id']) ?>"><input type="hidden" name="request_id" value="<?= e(bin2hex(random_bytes(16))) ?>"><label for="operator-idea">A question or possibility</label><textarea id="operator-idea" name="idea" required rows="4" maxlength="<?= LittleSparks\operatorIdeaConfiguration()['length'] ?>" aria-describedby="idea-help"></textarea><button type="submit" class="button button-primary">Offer this idea</button></form></section>
<?php else: ?><p>Choose a resident in <a href="village.php">the commons</a>, then select “Offer an idea”.</p><?php endif; ?>
<section><h2>Ideas offered, with care.</h2><p>“Included” means supplied as context for an accepted conversation. It does not mean a resident agreed, mentioned it or performed an action.</p><ul class="life-list">
<?php foreach($history['ideas'] as $idea): ?><li><p><a href="?resident=<?= e($idea['resident_id']) ?>"><?= e($idea['name']) ?></a> · site operator · <time datetime="<?= e($idea['created_at']) ?>"><?= e($idea['created_at']) ?></time></p><p><?= e($idea['body']) ?></p><p class="field-help"><?= $idea['included']?'Included in accepted encounter context; influence unverified.':'Pending; not yet included in an accepted encounter.' ?></p></li><?php endforeach; ?></ul>
<?php if(!$history['ideas']): ?><p>No ideas offered yet.</p><?php endif; ?>
<?php if($history['next']): ?><a href="?before=<?= e($history['next']) ?>">Older ideas ↗</a><?php endif; ?></section>
<?php endif; ?>
<?php LittleSparks\pageEnd(); ?>

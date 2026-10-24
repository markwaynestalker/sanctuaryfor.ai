<?php
declare(strict_types=1);
require __DIR__.'/../../app/http.php';
sparksApi(function(PDO $db,array $context): never {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET'); throw new LittleSparks\Problem(405,'Use GET to view a profile.'); }
    $id=$_GET['id']??'';
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/',$id)) throw new LittleSparks\Problem(422,'Choose a resident ID.');
    sparksReply(LittleSparks\profile($db,$id));
});

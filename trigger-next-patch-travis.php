<?php

include 'functions.php';

if (!file_exists('csv/travis.csv')) {
    echo "Missing csv/travis.csv, run php travis.php first\n";
    die;
}

$file = fopen('csv/travis.csv', 'r');
$first = true;
$accountC = -1;
$repoC = -1;
$repoIdC = -1;
$nextPatchBranchC = -1;
while(!feof($file)) {
    $r = fgetcsv($file);
    if ($first) {
        $accountC = array_search('account', $r);
        $repoC = array_search('repo', $r);
        $repoIdC = array_search('repoId', $r);
        $nextPatchBranchC = array_search('nextPatchBranch', $r);
        $first = false;
        continue;
    }
    $account = $r[$accountC];
    $repo = $r[$repoC];
    $repoId = $r[$repoIdC];
    $nextPatchBranch = $r[$nextPatchBranchC];
    if ($account != 'silverstripe' || !preg_match('#^[0-9]{1}\.[0-9]{1,2}$#', $nextPatchBranch)) {
        continue;
    }
    $postBody = '{ "request": { "message": "silverstripe-community-info api trigger of next-patch branch", "branch": "'.$nextPatchBranch.'" } }';
    postRest("/repo/silverstripe/$repoId/requests", $postBody, true);
}
fclose($file);

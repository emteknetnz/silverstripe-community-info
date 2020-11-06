<?php

/*
branches
https://api.github.com/repos/silverstripe/silverstripe-config/branches

1 branch (latest major)
https://api.github.com/repos/silverstripe/silverstripe-config/statuses/3bafc2fc63a6c792f21a893d21428d482f726585

1.1 branch (latest minor)
https://api.github.com/repos/silverstripe/silverstripe-config/statuses/a1f28b12a4b50d55412a629f40bd7372135ebeb5

1.0 branch (previous minor)
https://api.github.com/repos/silverstripe/silverstripe-config/statuses/49c88a0d147b3aebac69eb154d102dea95f7cf3e
chuck it into silverstripe-community-info
*/

include 'modules.php';
include 'functions.php';

function createTravisCsv() {
    global $modules;
    
    $pagination = '@pagination';

    $account = 'silverstripe';

    // get repository id's
    $repo = 'meta';
    $repoIds = [];
    for ($offset = 0; $offset < 1000; $offset += 100) {
        $extra = "travis-repos-$offset";
        $filename = "json/rest-$account-$repo-$extra.json";
        if (file_exists($filename)) {
            echo "Using local data for $filename\n";
            $data = json_decode(file_get_contents($filename));
        } else {
            $url = "/repos?private=false&limit=100&offset=$offset";
            $data = fetchRest($url, $account, $repo, $extra, true);
        }
        if (!$data) {
            break;
        }
        foreach ($data->repositories as $obj) {
            if (!in_array($obj->owner_name, ['silverstripe'])) {
                continue;
            }
            $repoIds[$obj->name] = $obj->id;
        }
        if (is_null($data->$pagination->next)) {
            break;
        }
    }

    // query branches - get branches numbers (but not latest commits or status)
    $repo = 'silverstripe-config';
    $repoId = $repoIds[$repo];
    $branchNames = [];
    for ($offset = 0; $offset < 1000; $offset += 100) {
        $extra = "travis-branches-$offset";
        $filename = "json/rest-$account-$repo-$extra.json";
        if (file_exists($filename)) {
            echo "Using local data for $filename\n";
            $data = json_decode(file_get_contents($filename));
        } else {
            $url = "/repo/$repoId/branches?exists_on_github=true&limit=100&offset=$offset";
            $data = fetchRest($url, $account, $repo, $extra, true);
        }
        if (!$data) {
            break;
        }
        foreach ($data->branches as $obj) {
            $branchNames[] = $obj->name;
        }
        if (is_null($data->$pagination->next)) {
            break;
        }
    }
    sort($branchNames);
    $branchData = [
        'major-latest' => ['branch' => 0, 'number' => '', 'state' => '', 'commit' => ''],
        'minor-latest' => ['branch' => 0, 'number' => '', 'state' => '', 'commit' => ''],
        'minor-previous'=> ['branch' => 0, 'number' => '', 'state' => '', 'commit' => ''],
    ];
    // major
    foreach ($branchNames as $branch) {
        if (preg_match('#^[1-9]$#', $branch) && $branch > $branchData['major-latest']['branch']) {
            $branchData['major-latest']['branch'] = $branch;
        }
    }
    // minors
    foreach ($branchNames as $branch) {
        if (preg_match('#^([1-9])\.([0-9]{1,2})$#', $branch, $m)) {
            if ($m[1] == $branchData['major-latest']['branch']) {
                if ($branch > $branchData['minor-latest']['branch']) {
                    $branchData['minor-previous']['branch'] = $branchData['minor-latest']['branch'];
                    $branchData['minor-latest']['branch'] = $branch;
                }
            }
        }
    }
    // get builds
    foreach ($branchData as $type => $arr) {
        $branch = $arr['branch'];
        if ($branch == 0) {
            continue;
        }
        $extra = "travis-builds-$branch";
        $filename = "json/rest-$account-$repo-$extra.json";
        if (file_exists($filename)) {
            echo "Using local data for $filename\n";
            $data = json_decode(file_get_contents($filename));
        } else {
            $url = "/repo/$repoId/builds?branch.name=$branch&event_type=push,api&sort_by=number:desc&limit=1";
            $data = fetchRest($url, $account, $repo, $extra, true);
        }
        if (!$data || empty($data->builds)) {
            continue;
        }
        $branchData[$type]['number'] = $data->builds[0]->number;
        $branchData[$type]['state'] = $data->builds[0]->state;
        $branchData[$type]['commit'] = $data->builds[0]->commit->sha;
    }

    print_r($branchData);

    die;

//=========================

    $rows = [];
    $moduleType = 'regular'; // not interested in tooling
    foreach ($modules[$moduleType] as $account => $repos) {
        foreach ($repos as $repo) {
            // fetch branches
            $filename = "json/rest-$account-$repo-travis-branches.json";
            if (file_exists($filename)) {
                echo "Using local data for $filename\n";
                $data = json_decode(file_get_contents($filename));
            } else {
                $url = "/repos/$account/$repo/branches";
                $data = fetchRest($url, $account, $repo, 'travis-branches');
            }
            if (!$data) {
                continue;
            }
            $branches = [
                'major-latest' => ['branch' => 0, 'commit' => '', 'state' => ''],
                'minor-latest' => ['branch' => 0, 'commit' => '', 'state' => ''],
                'minor-previous'=> ['branch' => 0, 'commit' => '', 'state' => ''],
            ];
            // major
            foreach ($data as $obj) {
                if (!is_object($obj)) {
                    continue;
                }
                $branch = $obj->name;
                $commit = $obj->commit->sha;
                if (preg_match('#^[1-9]$#', $branch) && $branch > $branches['major-latest']['branch']) {
                    $branches['major-latest'] = ['branch' => $branch, 'commit' => $commit, 'state' => ''];
                }
            }
            // minors
            foreach ($data as $obj) {
                if (!is_object($obj)) {
                    continue;
                }
                $branch = $obj->name;
                $commit = $obj->commit->sha;
                if (preg_match('#^([1-9])\.([0-9]{1,2})$#', $branch, $m)) {
                    if ($m[1] == $branches['major-latest']['branch']) {
                        if ($branch > $branches['minor-latest']['branch']) {
                            $branches['minor-previous'] = $branches['minor-latest'];
                            $branches['minor-latest'] = ['branch' => $branch, 'commit' => $commit, 'state' => ''];
                        }
                    }
                }
            }
            // fetch status
            foreach ($branches as $iden => $arr) {
                $branch = $arr['branch'];
                $commit = $arr['commit'];
                $filename = "json/rest-$account-$repo-travis-commit-$commit.json";
                if (file_exists($filename)) {
                    echo "Using local data for $filename\n";
                    $data = json_decode(file_get_contents($filename));
                } else {
                    $url = "/repos/$account/$repo/statuses/$commit";
                    $data = fetchRest($url, $account, $repo, "travis-commit-$commit");
                }
                if (!$data || !is_array($data)) {
                    continue;
                }
                foreach ($data as $obj) {
                    $context = $obj->context;
                    $state = $obj->state;
                    if ($context == 'continuous-integration/travis-ci/push') {
                        $branches[$iden]['state'] = $state;
                        break;
                    }
                }
            }
            $mjl = $branches['major-latest']['state'];
            $mnl = $branches['minor-latest']['state'];
            $mnp = $branches['minor-previous']['state'];
            $a = ['success', 'pending'];
            $statusLatest = 'fail';
            if (in_array($mjl, $a) && in_array($mnl, $a)) {
                $statusLatest = ($mjl == 'success' && $mnl == 'success') ? 'success' : 'pending';
            }
            $statusInclPrev = 'fail';
            if (in_array($mjl, $a) && in_array($mnl, $a) && in_array($mnp, $a)) {
                $statusInclPrev = ($mjl == 'success' && $mnl == 'success' && $mnp == 'success') ? 'success' : 'pending';
            }
            $rows[] = [
                'account' => $account,
                'repo' => $repo,
                'statusLatest' => $statusLatest,
                'statusInclPrev' => $statusInclPrev,
                'majorLatestBranch' => $branches['major-latest']['branch'],
                'majorLatestStatus' => $branches['major-latest']['state'],
                'minorLatestBranch' => $branches['minor-latest']['branch'],
                'minorLatestStatus' => $branches['minor-latest']['state'],
                'minorPrevBranch' => $branches['minor-previous']['branch'],
                'minorPrevStatus' => $branches['minor-previous']['state'],
            ];
        }
    }
    createCsv('csv/travis.csv', $rows, array_keys($rows[0]));
}

// ======

createDataDirs(['json', 'csv']);

$useLocalData = in_array(($argv[1] ?? ''), ['-l', '--local']);

if (!$useLocalData) {
    deleteJsonFiles('/^rest\-.*?\-travis.+?\.json$/');
}

createTravisCsv();

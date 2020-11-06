<?php

include 'modules.php';
include 'functions.php';

/*
Travis API States:
created - waiting for free workers
started - running
passed - green
errored - red
failed - red
canceled - grey - yes it's a typo in travis api
unknown - not in API, added by this script
*/

function createTravisCsv() {
    global $modules;
    
    $pagination = '@pagination';
    $rows = [];

    // get repository id's
    $account = 'silverstripe';
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
            // TODO: can't find silverstripe-elemental as that repo is still in travis-ci.org
            if (!in_array($obj->owner_name, ['silverstripe'])) {
                continue;
            }
            $repoIds[$obj->name] = $obj->id;
        }
        if (is_null($data->$pagination->next)) {
            break;
        }
    }

    $moduleType = 'regular'; // not interested in tooling
    foreach ($modules[$moduleType] as $account => $repos) {
        foreach ($repos as $repo) {
            $branchData = [
                'major-latest' => ['branch' => -1, 'number' => '', 'state' => 'unknown', 'commit' => ''],
                'minor-latest' => ['branch' => -1, 'number' => '', 'state' => 'unknown', 'commit' => ''],
                'minor-previous'=> ['branch' => -1, 'number' => '', 'state' => 'unknown', 'commit' => ''],
            ];
            // branches
            if (!isset($repoIds[$repo])) {
                echo "Could not find repoId for $repo\n";
                continue;
            } else {
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
                    if ($branch == -1) {
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
            }
            $rows[] = [
                'account' => $account,
                'repo' => $repo,
                'link' => "https://travis-ci.com/github/$account/$repo/branches",
                'majorLatestBranch' => $branchData['major-latest']['branch'],
                'majorLatestStatus' => $branchData['major-latest']['state'],
                'minorLatestBranch' => $branchData['minor-latest']['branch'],
                'minorLatestStatus' => $branchData['minor-latest']['state'],
                'minorPrevBranch' => $branchData['minor-previous']['branch'],
                'minorPrevStatus' => $branchData['minor-previous']['state'],
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

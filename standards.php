<?php

# check repo code standards compliance

include 'modules.php';
include 'functions.php';

function createStandardsCsv() {
    global $modules;

    $rows = [];
    foreach (['regular', 'tooling'] as $moduleType) {
        foreach ($modules[$moduleType] as $account => $repos) {
            foreach ($repos as $repo) {
                // get branches available
                $filename = "json/rest-$account-$repo-standards-branches.json";
                if (file_exists($filename)) {
                    echo "Using local data for $filename\n";
                    $data = json_decode(file_get_contents($filename));
                } else {
                    $url = "/repos/$account/$repo/branches";
                    $data = fetchRest($url, $account, $repo, 'standards-branches');
                }
                if (!$data) {
                    continue;
                }
                // find the "highest" branch which should be the latest minor
                $ref = 0;
                foreach ($data as $branch) {
                    $name = $branch->name;
                    // allow major and minor -- a few repos may not have a single minor, major only
                    if (preg_match('#^[1-9]$#', $name) || preg_match('#^[1-9]\.[0-9]$#', $name)) {
                        if ($name > $ref || $name == $ref && preg_match('#\.#', $ref)) {
                            $ref = $name;
                        }
                    }
                }
                // default to master branch
                if (!$ref) {
                    $ref = 'master';
                }
                // get contents of .travis file
                https://api.github.com/repos/silverstripe/silverstripe-asset-admin/contents/.travis.yml?ref=1.7
                $filename = "json/rest-$account-$repo-standards-travis-$ref.json";
                if (file_exists($filename)) {
                    echo "Using local data for $filename\n";
                    $data = json_decode(file_get_contents($filename));
                } else {
                    $url = "/repos/$account/$repo/contents/.travis.yml?ref=$ref";
                    $data = fetchRest($url, $account, $repo, "standards-travis-$ref");
                }
                $travisSharedConfig = 'unknown';
                if ($data && isset($data->content)) {
                    $content = base64_decode($data->content);
                    $b = strpos($content, 'silverstripe/silverstripe-travis-shared') !== false;
                    $travisSharedConfig = $b ? 'yes' : 'no';
                }
                $row = [
                    'account' => $account,
                    'repo' => $repo,
                    'latestBranch' => $ref,
                    'travisSharedConfig' => $travisSharedConfig
                ];
                $rows[] = $row;
            }
        }
    }
    createCsv('csv/standards.csv', $rows, array_keys($rows[0]));
}

// ======

createDataDirs(['json', 'csv']);

$useLocalData = in_array(($argv[1] ?? ''), ['-l', '--local']);

if (!$useLocalData) {
    deleteJsonFiles('/^rest\-[a-z\-]+\-standards\.json$/');
}

createStandardsCsv();


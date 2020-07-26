<?php

include 'modules.php';
include 'functions.php';

function createIssuesCsv() {
    global $modules;
    
    $rows = [];
    foreach (['regular', 'tooling'] as $moduleType) {
        foreach ($modules[$moduleType] as $account => $repos) {
            foreach ($repos as $repo) {
                $output = [];
                $filename = "json/rest-$account-$repo-issues-open.json";
                if (file_exists($filename)) {
                    echo "Using local data for $filename\n";
                    $data = json_decode(file_get_contents($filename));
                } else {
                    // rest is pretty reliable, only to attempt to fetch once
                    $url = "/repos/$account/$repo/issues?state=open";
                    $data = fetchRest($url, $account, $repo, 'issues-open');
                }
                if (!$data) {
                    continue;
                }
                foreach ($data as $issue) {
                    if (!is_object($issue)) {
                        continue;
                    }
                    // issues api also contains pull requests for some weird reason
                    if (preg_match('@/pull/[0-9]+$@', $issue->html_url)) {
                        continue;
                    }
                    $labels = empty($issue->labels) ? [] : $issue->labels;
                    $row = [
                        'title' => $issue->title,
                        'account' => $account,
                        'repo' => $repo,
                        'label_type' => '',
                        'label_impact' => '',
                        'label_effort' => '',
                        'label_affects' => '',
                        'author' => $issue->user->login,
                        'authorType' => deriveUserType($issue->user->login),
                        'createdAt' => timestampToNZDate($issue->created_at),
                        'updatedAt' => timestampToNZDate($issue->updated_at),
                        'url' => $issue->html_url,
                    ];
                    foreach ($labels as $label) {
                        foreach (array_keys($row) as $key) {
                            if (strpos($key, 'label_') !== 0) {
                                continue;
                            }
                            $s = str_replace('label_', '', $key);
                            if (preg_match("@^$s/(.+)$@", $label->name, $m)) {
                                $row[$key] = $m[1];
                            }
                        }
                    }
                    $rows[] = $row;
                }
            }
        }
    }
    createCsv('csv/issues.csv', $rows, array_keys($rows[0]));
}

// ======

createDataDirs(['json', 'csv']);

$useLocalData = in_array(($argv[1] ?? ''), ['-l', '--local']);

if (!$useLocalData) {
    deleteJsonFiles('/^rest\-[a-z\-]+\-issues\-open\.json$/');
}

createIssuesCsv();

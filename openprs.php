<?php

include 'modules.php';
include 'teams.php';
include 'functions.php';

function fetchOpenPRsData() {
    global $modules;
    $data = [];
    foreach (['regular', 'tooling'] as $moduleType) {
        // can often be quite a few errors with github graphql api
        // partcularly when doing a new query for the first time
        // try downloading a few times in total
        for ($i = 0; $i < 3; $i++) {
            foreach ($modules[$moduleType] as $account => $repos) {
                foreach ($repos as $repo) {
                    $query = buildOpenPRsQuery($account, $repo);
                    $filename = "json/graphql-$account-$repo-openprs.json";
                    if (!file_exists($filename)) {
                        if ($i > 0) {
                            echo "Retry attempt $i for $account/$repo openprs\n";
                        }
                        fetchGraphQL($query, $account, $repo, 'openprs');
                    } elseif (file_exists($filename) && $i == 0) {
                        echo "Using local data for $account/$repo openprs\n";
                    }
                }
            }
        }
        foreach ($modules[$moduleType] as $account => $repos) {
            foreach ($repos as $repo) {
                $filename = "json/graphql-$account-$repo-openprs.json";
                if (!file_exists($filename)) {
                    continue;
                }
                $json = json_decode(file_get_contents($filename));
                foreach ($json->data->search->nodes as $pr) {
                    $row = deriveOpenPRDataRow($pr, $moduleType, $account, $repo);
                    if ($row) {
                        $data[] = $row;
                    }
                }
            }
        }
    }
    return $data;
}

function buildOpenPRsQuery($account, $repo) {
    return <<<EOT
      {
        search(
          query: "repo:$account/$repo is:open is:pr archived:false"
          type: ISSUE
          last: 100
        ) {
          nodes {
            ... on PullRequest {
              title
              body
              url
              author {
                login
              }
              isDraft
              createdAt
              updatedAt
              closedAt
              comments(last: 10) {
                nodes {
                  author {
                    login
                  }
                  createdAt
                }
              }
              merged
              mergedAt
              mergedBy {
                login
              }
              files(first: 100) {
                nodes {
                  path
                  additions
                  deletions
                }
              }
              reviews(last: 10) {
                nodes {
                  state
                  author {
                    login
                  }
                }
              }
              mergeable
              commits(last: 1) {
                nodes {
                  commit {
                    committedDate
                    status {
                      state
                      contexts {
                        state
                        context
                        description
                        targetUrl
                      }
                    }
                  }
                }
              }
              labels(last: 10) {
                nodes {
                  name
                }
              }
            }
          }
        }
      }
EOT;
}

function deriveOpenPRDataRow($pr, $moduleType, $account, $repo) {

    global $teams;

    $files = [];
    foreach ($pr->files->nodes as $node) {
        $files[] = [
            'path' => $node->path,
            'additions' => $node->additions,
            'deletions' => $node->deletions,
        ];
    }
    
    // author very occasionally null, possibly user deleted from github
    $author = $pr->author->login ?? '';

    // ci tool red
    $ciToolRed = [
        'travis' => false,
        'scrutinizer' => false,
        'codecov' => false,
    ];
    foreach ($pr->commits->nodes[0]->commit->status->contexts ?? [] as $context) {
        foreach (array_keys($ciToolRed) as $ci) {
            // states are SUCCESS, ERROR, FAILURE, PENDING
            // it's OK to treat PENDING as red, as sometimes ci tool gets stuck in a
            // pending state, and 'pending' is not immediately actionable anyway
            if (
                strpos(strtolower($context->context), $ci) !== false &&
                $context->state != 'SUCCESS'
            ) {
                $ciToolRed[$ci] = true;
            }
        }
    }

    // merge conflicts
    $mergeConflicts = $pr->mergeable == 'CONFLICTING';

    // ss3
    $ss3 = false;
    foreach ($pr->labels->nodes as $label) {
        if ($label->name == 'affects/v3') {
            $ss3 = true;
        }
    }
    $a = [
        'silverstripe-mfa',
        'silverstripe-totp-authenticator',
        'silverstripe-webauthn-authenticator',
        'silverstripe-realme'
    ];
    $ss3Supported = $ss3 && in_array($moduleType, $a);

    // approved / change requeted   
    $a = [];
    foreach ($pr->reviews->nodes as $review) {
        if (in_array($review->state, ['APPROVED', 'CHANGES_REQUESTED'])) {
            $a[$review->author->login ?? ''] = $review->state;
        }
    }
    $approved = !empty(array_filter($a, function($v) { return $v == 'APPROVED'; }));
    $changesRequested = !empty(array_filter($a, function($v) { return $v == 'CHANGES_REQUESTED'; }));

    // someone else on our team who can me is active on pr
    $teamActive = false;
    foreach ($pr->comments->nodes as $comment) {
        $a = array_merge($teams['core_commiters'], $teams['product_devs']);
        if (!in_array($comment->author->login ?? '', $a)) {
            continue;
        }
        if ($comment->author->login == $author) {
            continue;
        }
        if (olderThanOneWeek($comment->createdAt)) {
            continue;
        }
        $teamActive = true;
        break;
    }

    // looking good to pickup to get over the line - failing codecov is fine
    $lookingGood =
        !$pr->isDraft &&
        !$mergeConflicts &&
        !$ciToolRed['travis'] &&
        !$ciToolRed['scrutinizer'] &&
        (!$ss3 || ($ss3 && $ss3Supported)) &&
        !$changesRequested;

    // last commit at
    // handle strange state with zero commits after force pushing
    // only happens on old closed branches
    $lastCommitAt = $pr->updatedAt;
    foreach ($pr->commits->nodes as $commit) {
        $lastCommitAt = $commit->commit->committedDate;
    }

    // ask to close
    $authorType = deriveUserType($author);
    if (in_array($authorType, ['core_commiters', 'product_devs', 'pux'])) {
        $stalePR = olderThanOneMonth($lastCommitAt);
    } elseif (in_array($authorType, ['dependabot', 'me'])) {
        $stalePR = false;
    } else {
        // bespoke, tsp_ops, other
        $stalePR = olderThanOneMonth($lastCommitAt);
    }
    $askToClose = false;
    if (!$teamActive && !$lookingGood) {
        $askToClose = $stalePR || ($ss3 && !$ss3Supported);
    }

    // just close
    // they've already been asked and never responded
    $a = ['core_commiters', 'product_devs', 'pux', 'tsp_ops', 'me'];
    $lastComment = getLastNode($pr->comments->nodes);
    $justClose = 
        $askToClose &&
        $account == 'silverstripe' &&
        !in_array($authorType, $a) &&
        $lastComment &&
        in_array(deriveUserType($lastComment->author->login) ?? '', $a) &&
        olderThanTwoWeeks($lastComment->createdAt);
    
    // labels
    $labelType = '';
    $labelImpact = '';
    $labelAffects = '';
    $labelEffort = '';
    $labelChange = '';
    foreach ($pr->labels->nodes as $label) {
        if (preg_match('#^impact/(.+)$#', $label->name, $m)) {
            $labelImpact = $m[1];
        }
        if (preg_match('#^type/(.+)$#', $label->name, $m)) {
            $labelType = $m[1];
        }
        if (preg_match('#^effort/(.+)$#', $label->name, $m)) {
            $labelEffort = $m[1];
        }
        if (preg_match('#^affects/(.+)$#', $label->name, $m)) {
            $labelAffects = $m[1];
        }
        if (preg_match('#^change/(.+)$#', $label->name, $m)) {
            $labelChange = $m[1];
        }
    }

    // Most PR's don't have issues, so adding issue labels results in pretty low value data
    // Better solution is to add labels directly to PR's
    // // run php issues.php first to ensure you have json around
    // $issue = null;
    // $issueImpact = '';
    // if (preg_match('#https://github.com/([^/]+)/([^/]+)/issues/([0-9]+)#', $pr->body ?? '', $m)) {
    //     array_shift($m);
    //     list($account, $repo, $id) = $m;
    //     $filename = "json/rest-{$account}-$repo-issues-open.json";
    //     if (file_exists($filename)) {
    //         echo "Using local data for $account/$repo issues-open\n";
    //         $issues = json_decode(file_get_contents($filename));
    //         foreach ($issues as $_issue2) {
    //             if ($_issue2->number == $id) {
    //                 $issue = $_issue2;
    //                 break;
    //             }
    //         }
    //     } else {
    //         echo "run `php issues.php` first so that you have open issues json\n";
    //     }
    // }

    // issue impact
    // foreach ($issue->labels ?? [] as $label) {
    //     if (preg_match('#^impact/(.+)$#', $label->name, $m)) {
    //         $issueImpact = $m[1];
    //     }
    // }

    $productTeams = ['product_devs', 'pux', 'tsp_ops', 'me', 'dependabot'];

    $row = [
        'moduleType' => $moduleType,
        'account' => $account,
        'repo' => $repo,
        'title' => $pr->title,
        'type' => pullRequestType($files, $author),
        'author' => $author,
        'authorType' => $authorType,
        'draft' => $pr->isDraft,
        'stalePR' => $stalePR,
        'mergeConflicts' => $mergeConflicts,
        'travisRed' => $ciToolRed['travis'],
        'scrutinzerRed' => $ciToolRed['scrutinizer'],
        'codecovRed' => $ciToolRed['codecov'],
        'ss3' => $ss3,
        'ss3Supported' => $ss3Supported,
        'changesRequested' => $changesRequested,
        'approved' => $approved,
        'lookingGood' => $lookingGood,
        'teamActive' => $teamActive,
        'askToClose' => $askToClose,
        'justClose' => $justClose,
        'createdAt' => $pr->createdAt,
        'createdAtNZ' => timestampToNZDate($pr->createdAt),
        'updatedAt' => $pr->updatedAt,
        'updatedAtNZ' => timestampToNZDate($pr->updatedAt),
        'lastCommitAt' => $lastCommitAt,
        'lastCommitAtNZ' => timestampToNZDate($lastCommitAt),
        'url' => $pr->url,
        'urlFiles' => $pr->url . '/files',
        'labelImpact' => $labelImpact,
        'labelType' => $labelType,
        'labelEffort' => $labelEffort,
        'labelAffects' => $labelAffects,
        'labelChange' => $labelChange,
        // 'issueTitle' => $issue->title ?? '',
        // 'issueUrl' => $issue->html_url ?? '',
        // 'issueImpact' => $issueImpact,
    ];
    return $row;
}

function createOpenPRsCsv($data) {
    $fields = [
        'title',
        'account',
        'repo',
        'type',
        'author',
        'authorType',
        'labelType',
        'labelImpact',
        'labelEffort',
        'labelAffects',
        'labelChange',
        'url',
        'urlFiles',
        'createdAtNZ',
        'lastCommitAtNZ',
        'updatedAtNZ',
        'teamActive',
        'lookingGood',
        'approved',
        'draft',
        'changesRequested',
        'mergeConflicts',
        'travisRed',
        'scrutinzerRed',
        'codecovRed',
        'ss3',
        'ss3Supported',
        'stalePR',
        'askToClose',
        'justClose',
    ];
    createCsv('csv/openprs.csv', $data, $fields);
}

// ========

createDataDirs(['json', 'csv']);
updateTeams();

$useLocalData = in_array(($argv[1] ?? ''), ['-l', '--local']);

if (!$useLocalData) {
    deleteJsonFiles('/graphql\-[a-z\-]+\-openprs\.json/');
}

$data = fetchOpenPRsData();
createOpenPRsCsv($data);

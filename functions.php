<?php

include 'teams.php';

/**
 * username:token
 */
function getCredentials($userOnly = false, $travis = false) {
    // https://docs.github.com/en/github/authenticating-to-github/creating-a-personal-access-token
    //
    // .credentials
    // user=my_github_username
    // token=abcdef123456
    //
    // https://github.com/settings/tokens/new
    // [x] Access commit status 
    // [x] Access public repositories 
    //
    $data = [];
    $s = file_get_contents('.credentials');
    $lines = preg_split('/[\r\n]/', $s);
    foreach ($lines as $line) {
        $kv = preg_split('/=/', $line);
        if (count($kv) != 2) break;
        $key = $kv[0];
        $value = $kv[1];
        $data[$key] = $value;
    }
    if ($userOnly) {
        return $data['user'];
    }
    if ($travis) {
        return $data["travis_com_token"];
    }
    return $data['user'] . ':' . $data['token'];
}

function createCsv($filename, $data, $fields, $maxN = 9999) {
    $lines = [];
    $n = 0;
    foreach ($data as $row) {
        $line = [];
        foreach ($fields as $field) {
            $line[] = str_replace(',', '', $row[$field]);
        }
        $lines[] = implode(',', $line);
        if (++$n >= $maxN) {
            break;
        }
    }
    array_unshift($lines, implode(',', $fields));
    $output = implode("\n", $lines);
    file_put_contents($filename, $output);
    echo "\nWrote to $filename\n\n";
}

function deleteJsonFiles($rx) {
    foreach (scandir('json') as $filename) {
        if (!preg_match($rx, $filename)) {
            continue;
        }
        unlink("json/$filename");
    }
}

function createDataDirs($dirs) {
    foreach ($dirs as $path) {
        if (!file_exists($path)) {
            mkdir($path);
        }
    }
}

function updateTeams() {
    global $teams;
    // treat me as a special team
    $me = getCredentials(true);
    $i = array_search($me, $teams['product_devs']);
    unset($teams[$i]);
    $teams['me'] = [$me];
}

function getLastNode($nodes) {
    if (count($nodes) == 0) {
        return null;
    }
    return $nodes[count($nodes) - 1];
}

function parseTimestamp($timestamp) {
    $str = str_replace(['T', 'Z'], '', $timestamp);
    return DateTime::createFromFormat('Y-m-d H:i:s', $str);
}

function olderThanOneWeek($timestamp) {
    $dateTime = parseTimestamp($timestamp);
    $diff = $dateTime->diff(new DateTime());
    return $diff->y >= 1 || $diff->m >= 1 || $diff->d >= 7;
}

function olderThanTwoWeeks($timestamp) {
    $dateTime = parseTimestamp($timestamp);
    $diff = $dateTime->diff(new DateTime());
    return $diff->y >= 1 || $diff->m >= 1 || $diff->d >= 14;
}

function olderThanOneMonth($timestamp) {
    $dateTime = parseTimestamp($timestamp);
    $diff = $dateTime->diff(new DateTime());
    return $diff->y >= 1 || $diff->m >= 1;
}

function olderThanThreeMonths($timestamp) {
    $dateTime = parseTimestamp($timestamp);
    $diff = $dateTime->diff(new DateTime());
    return $diff->y >= 1 || $diff->m >= 3;
}

function olderThanSixMonths($timestamp) {
    $dateTime = parseTimestamp($timestamp);
    $diff = $dateTime->diff(new DateTime());
    return $diff->y >= 1 || $diff->m >= 6;
}

function olderThanOneYear($timestamp) {
    $dateTime = parseTimestamp($timestamp);
    $diff = $dateTime->diff(new DateTime());
    return $diff->y >= 1;
}

$lastRequestTS = 0;
function waitUntilCanFetch() {
    // https://developer.github.com/v3/#rate-limiting
    // - authentacted users can make 5,000 requests per hour
    // - wait 1 second between requests (max of 3,600 per hour)
    global $lastRequestTS;
    $ts = time();
    if ($ts == $lastRequestTS) {
        sleep(1);
    }
    $lastRequestTS = $ts;
}

function sortDesc($a, $b) {
    return ($a <=> $b) * -1;
}

function timestampToNZDate($ts) {
    // TODO: UTC to NZ time
    // ISO 8601 timestamp
    preg_match('/([0-9]{4})\-([0-9]{2})\-([0-9]{2})T/', $ts, $m);
    if (!isset($m[3])) {
        return '';
    }
    return $m[3] . '-' . $m[2] . '-' . $m[1];
}

function deriveUserType($user) {
    global $teams;
    foreach ($teams as $k => $a) {
        if (in_array($user, $a)) {
            return $k;
        }
    }
    return 'other';
}

function isDocFile($path) {
    $ext = pathinfo(strtolower($path), PATHINFO_EXTENSION);
    return in_array($ext, ['md']);
}

function isImageFile($path) {
    $ext = pathinfo(strtolower($path), PATHINFO_EXTENSION);
    return in_array($ext, ['jpg', 'jpeg', 'gif', 'png']);
}

function isConfigFile($path) {
    // possiblly should treat .travis.yml and .scrutinizer as 'tooling'
    return in_array($path, ['.travis.yml', '.scrutinizer.yml', 'behat.yml', 'composer.json', 'composer.lock', 'package.json', 'yarn.lock']) ||
        preg_match('@lang/[A-Za-z]{2}.yml$@', $path);
}

function isDistFile($path) {
    $ext = pathinfo(strtolower($path), PATHINFO_EXTENSION);
    return strpos($path, '/dist/') !== false ||
        in_array($path, ['bundle.js', 'vendor.js']) ||
        in_array($ext, ['css']);
}

function isTestFile($path) {
    return (bool) preg_match('/[a-z0-9]test\.php$/', $path);
}

function pullRequestType($files, $author) {
    $c = pullRequestFileCounts($files);
    $types = array_filter($c, function($v, $k) {
        return $v['count'] > 0;
    }, ARRAY_FILTER_USE_BOTH);
    $types = array_keys($types);
    sort($types);
    if($author == 'dependabot') {
        return 'depbot';
    } elseif ($types == ['doc'] || $types == ['doc', 'image']) {
        return 'doc';
    } elseif ($types == ['config']) {
        return 'config';
    } elseif ($types == ['dist'] || $types == ['dist', 'image']) {
        return 'dist';
    } else {
        return 'general';
    }
}

function pullRequestFileCounts($files) {
    $data = [];
    $ks = ['config', 'dist', 'doc', 'general', 'image', 'test'];
    foreach ($ks as $k) {
        $data[$k] = [
            'additions' => 0,
            'count' => 0,
            'deletions' => 0,
        ];
    }
    foreach ($files as $file) {
        $path = $file['path'];
        $k = 'general';
        if (isConfigFile($path)) {
            $k  = 'config';
        } elseif (isDistFile($path)) {
            $k  = 'dist';
        } elseif (isDocFile($path)) {
            $k  = 'doc';
        } elseif (isImageFile($path)) {
            $k  = 'image';
        } elseif (isTestFile($path)) {
            $k  = 'test';
        }
        $data[$k]['count']++;
        $data[$k]['additions'] += $file['additions'];
        $data[$k]['deletions'] += $file['deletions'];
    }
    return $data;
}

function fetchGraphQL($query, $account, $repo, $extra) {
    $endpoint = 'https://api.github.com/graphql';
    $json = buildGraphQLQueryJson($query);
    echo "Fetching data for $account/$repo $extra\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_USERPWD, getCredentials());
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:28.0) Gecko/20100101 Firefox/28.0'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    waitUntilCanFetch();
    $s = curl_exec($ch);
    curl_close($ch);
    // validate json before saving
    $json = json_decode($s);
    // making assumption that we're using the search "endpoint"
    if (!isset($json->data->search->nodes)) {
        echo "Error fetching data\n";
        return null;
    } else {
        $str = json_encode($json, JSON_PRETTY_PRINT);
        file_put_contents("json/graphql-$account-$repo-$extra.json", $str);
        return $json;
    }
}

function buildGraphQLQueryJson($query) {
    $q = trim($query);
    $q = str_replace("\n", '', $q);
    $q = preg_replace('/ {2,}/', ' ', $q);
    $q = str_replace('"', '\\"', $q);
    return "{\"query\":\"$q\"}";
}

function fetchRestOrUseLocal($remotePath, $account, $repo, $iden) {
    $filename = "json/rest-$account-$repo-$iden.json";
    if (file_exists($filename)) {
        echo "Using local data for $filename\n";
        $data = json_decode(file_get_contents($filename));
    } else {
        $data = fetchRest($remotePath, $account, $repo, $iden);
    }
    return $data;
}

function fetchRest($remotePath, $account, $repo, $extra, $travis = false) {
    $remoteBase = "https://api.github.com";
    if ($travis) {
        $remoteBase = "https://api.travis-ci.com";
    }
    $remotePath = str_replace($remoteBase, '', $remotePath);
    $remotePath = ltrim($remotePath, '/');
    if ($travis) {
        // travis
        $url = "${remoteBase}/${remotePath}";
    } else {
        // github
        if (preg_match('#/[0-9]+$#', $remotePath) || preg_match('@/[0-9]+/files$@', $remotePath)) {
            // requesting details
            $url = "${remoteBase}/${remotePath}";
        } else {
            // requesting a list
            $op = strpos($remotePath, '?') ? '&' : '?';
            $url = "${remoteBase}/${remotePath}${op}per_page=100";
        }
    }
    $label = str_replace($remoteBase, '', $url);
    echo "Fetching from ${label}\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($travis) {
        $headers = [
            'Travis-API-Version: 3',
            'Accept: application/vnd.travis-ci.2.1+json',
            'Authorization: token "' . getCredentials(false, true) . '"'
        ];
    } else {
        // github
        $headers = [
            'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:28.0) Gecko/20100101 Firefox/28.0'
        ];
        curl_setopt($ch, CURLOPT_USERPWD, getCredentials());
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    waitUntilCanFetch();
    $s = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($s);
    if (!is_array($json) && !is_object($json)) {
        echo "Error fetching data\n";
        return null;
    } else {
        $str = json_encode($json, JSON_PRETTY_PRINT);
        file_put_contents("json/rest-$account-$repo-$extra.json", $str);
    }
    return $json;
}

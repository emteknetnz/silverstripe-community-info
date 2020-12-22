<?php

# check repo code standards compliance

include 'modules.php';
include 'functions.php';

function createStandardsCsv() {
    global $modules;
    $varsList = [];
    foreach (['regular', 'tooling'] as $moduleType) {
        foreach ($modules[$moduleType] as $account => $repos) {
            foreach ($repos as $repo) {
                foreach (['next-minor', 'next-patch'] as $branchType) {
                    $varsList[] = [$account, $repo, $branchType];
                }
            }
        }
    }
    $rows = [];
    foreach ($varsList as $vars) {
        list($account, $repo, $branchType) = $vars;
        // get branches available
        $data = fetchRestOrUseLocal("/repos/$account/$repo/branches", $account, $repo, 'standards-branches');
        if (!$data) {
            continue;
        }
        // find the "highest" branch which should be the latest minor
        $ref = 0;
        foreach ($data as $branch) {
            $name = $branch->name;
            $rx = $branchType == 'next-minor' ? '#^[1-9]$#' : '#^[1-9]\.[0-9]$#';
            if (!preg_match($rx, $name) || $name <= $ref) {
                continue;
            }
            $ref = $name;
        }
        if (!$ref) {
            $ref = $branchType == 'next-minor' ? 'master' : 'missing';
        }
        if ($ref == 'missing') {
            $latestBranch = 'missing';
        } else {
            // important to suffix .x-dev otherwise excel will remove '.0' from next-patch branches
            $latestBranch = $ref == 'master' ? 'dev-master' : $ref . '.x-dev';
        }
        $repoKeyValues = [
            'account' => $account,
            'repo' => $repo,
            'branchType' => $branchType,
            'latestBranch' => $latestBranch
        ];
        $arr = array_merge($repoKeyValues);
        if ($ref != 'missing') {
            // get contents of .travis file
            // https://api.github.com/repos/silverstripe/silverstripe-asset-admin/contents/.travis.yml?ref=1.7
            $data = fetchRestOrUseLocal("/repos/$account/$repo/contents/.travis.yml?ref=$ref", $account, $repo, "standards-travis-$ref");
            $travisKeyValues = [
                'travisFileExists' => '',
                'travisSharedConfig' => '',
            ];
            $travisKeyRxs = [
                'travisProvision' => '#config/provision/([^\.]+)\.yml#',
                'travisRootVersion' => '#COMPOSER_ROOT_VERSION=["\']*([0-9\.]+?\.x-dev)["\']*#',
                'travisRequireRecipe' => '#REQUIRE_RECIPE=["\']*([0-9\.]+?\.x-dev)["\']*#',
                'travisRequireExtra' => '#REQUIRE_EXTRA=["\']*([^"\'$]+)["\']*#',
            ];
            $travisKeyStrs = [
                'travisTidy' => '- tidy',
                'travisCustomMatrix' => 'jobs:',
                'travisPhpUnit' => 'PHPUNIT_TEST',
                'travisPhpCS' => 'PHPCS_TEST',
                'travisPhpCoverage' => 'PHPUNIT_COVERAGE_TEST',
                'travisPreferLowest' => '--prefer-lowest',
                'travisPhp8' => 'php: nightly',
                'travisPhp8AllowFailure' => '  allow_failures:\n    - php: nightly',
                'travisNpm' => 'NPM_TEST',
                'travisBehat' => 'BEHAT_TEST',
                'travisCow' => 'COW_TEST',
            ];
            $arr['travisFileExists'] = 'no';
            if ($data && isset($data->content)) {
                $content = base64_decode($data->content);
                $arr['travisFileExists'] = 'yes';
                $arr['travisSharedConfig'] = strpos($content, 'silverstripe/silverstripe-travis-shared') !== false ? 'yes' : 'no';
                if ($arr['travisSharedConfig'] == 'yes') {
                    foreach ($travisKeyStrs as $key => $str) {
                        $arr[$key] = strpos($content, $str) !== false ? 'yes' : 'no';
                    }
                    foreach ($travisKeyRxs as $key => $rx) {
                        preg_match($rx, $content, $m);
                        $arr[$key] = $m[1] ?? '';
                    }
                }
            }
            // get contents of composer.json file
            // https://api.github.com/repos/silverstripe/silverstripe-asset-admin/contents/composer.json?ref=1.7
            $composerKeyValues = [
                'composerSminneePhpunit' => ''
            ];
            $data = fetchRestOrUseLocal("/repos/$account/$repo/contents/composer.json?ref=$ref", $account, $repo, "standards-composer-json-$ref");
            if ($data && isset($data->content)) {
                $content = base64_decode($data->content);
                $b = strpos($content, 'sminnee/phpunit') !== false || strpos($content, 'silverstripe/recipe-testing') !== false;
                $arr['composerSminneePhpunit'] = $b ? 'yes' : 'no';
            }
        }
        $keys = array_merge(
            array_keys($repoKeyValues),
            array_keys($travisKeyValues),
            array_keys($travisKeyRxs),
            array_keys($travisKeyStrs),
            array_keys($composerKeyValues)
        );
        $row = [];
        foreach ($keys as $key) {
            $row[$key] = $arr[$key] ?? '';
        }
        $rows[] = $row;
    }
    createCsv('csv/standards.csv', $rows, array_keys($rows[0]));
}

// ======

createDataDirs(['json', 'csv']);

$useLocalData = in_array(($argv[1] ?? ''), ['-l', '--local']);

if (!$useLocalData) {
    deleteJsonFiles('/^rest\-[a-z\-]+\-standards.*?\.json$/');
}

createStandardsCsv();


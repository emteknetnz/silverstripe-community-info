<?php

// modules with custom travis files that are excluded from travis shared config check
$modulesWithCustomTravis = [
    'silverstripe' => [
        'cwp-starter-theme',
        'silverstripe-upgrader',
        'sspak',
    ]
];

// modules that lack a next-minor `2` branch and only have a next-patch `2.1` branch
$modulesWithoutNextMinorBranch = [
    'bringyourownideas' => [
        'silverstripe-maintenance',
        'silverstripe-composer-update-checker',
        'silverstripe-composer-security-checker',
    ],
    'silverstripe' => [
        'silverstripe-selectupload',
        'silverstripe-lumberjack',
        'silverstripe-gridfieldqueuedexport',
    ],
    'lekoala' => [
        'silverstripe-debugbar',
    ],
];

$modules = [
    'regular' => [
        'bringyourownideas' => [
            'silverstripe-maintenance',
            'silverstripe-composer-update-checker',
            'silverstripe-composer-security-checker',
        ],
        'dnadesign' => [
            'silverstripe-elemental',
        ],
        'silverstripe' => [
            'recipe-cms',
            'recipe-core',
            'recipe-plugin',
            'silverstripe-reports',
            'silverstripe-siteconfig',
            'silverstripe-versioned',
            'silverstripe-versioned-admin',
            'silverstripe-userhelp-content', // not an installed module, though still relevant
            'cwp-agencyextensions',
            'cwp',
            'cwp-core',
            'cwp-installer',
            'cwp-pdfexport',
            'cwp-recipe-basic',
            'cwp-recipe-blog',
            'cwp-recipe-cms',
            'cwp-recipe-core',
            'cwp-recipe-search',
            'cwp-search',
            'cwp-starter-theme',
            'cwp-watea-theme',
            'cwp-theme-default',
            'silverstripe-akismet',
            'silverstripe-auditor',
            'silverstripe-blog',
            'comment-notifications',
            'silverstripe-admin',
            'silverstripe-asset-admin',
            'silverstripe-assets',
            'silverstripe-campaign-admin',
            'silverstripe-cms',
            'silverstripe-config',
            'silverstripe-errorpage',
            'silverstripe-framework',
            'silverstripe-graphql',
            'silverstripe-installer',
            'silverstripe-comments',
            'silverstripe-content-widget',
            'silverstripe-contentreview',
            'silverstripe-crontask',
            'silverstripe-documentconverter',
            'silverstripe-elemental-bannerblock',
            'silverstripe-elemental-fileblock',
            'silverstripe-environmentcheck',
            'silverstripe-externallinks',
            'silverstripe-fulltextsearch',
            'silverstripe-gridfieldqueuedexport',
            'silverstripe-html5',
            'silverstripe-hybridsessions',
            'silverstripe-iframe',
            'silverstripe-ldap',
            'silverstripe-lumberjack',
            'silverstripe-mimevalidator',
            'silverstripe-postgresql',
            'silverstripe-realme',
            'recipe-authoring-tools',
            'recipe-blog',
            'recipe-collaboration',
            'recipe-content-blocks',
            'recipe-form-building',
            'recipe-reporting-tools',
            'recipe-services',
            'silverstripe-registry',
            'silverstripe-restfulserver',
            'silverstripe-securityreport',
            'silverstripe-segment-field',
            'silverstripe-selectupload',
            'silverstripe-sharedraftcontent',
            'silverstripe-sitewidecontent-report',
            'silverstripe-spamprotection',
            'silverstripe-spellcheck',
            'silverstripe-subsites',
            'silverstripe-tagfield',
            'silverstripe-taxonomy',
            'silverstripe-userforms',
            'silverstripe-widgets',
            'silverstripe-mfa',
            'silverstripe-totp-authenticator',
            'silverstripe-webauthn-authenticator',
            'silverstripe-login-forms',
            'silverstripe-security-extensions',
            // not in commercially supported list, though is in cwp
            'silverstripe-versionfeed',
        ],
        'silverstripe-themes' => [
            'silverstripe-simple',
        ],
        'symbiote' => [
            'silverstripe-advancedworkflow',
            'silverstripe-multivaluefield',
            'silverstripe-queuedjobs',
        ],
    ],
    // TODO: I haven't migrated over all ss3 modules into here yet, only some of them
    'ss3' => [
        'silverstripe' => [
            'silverstripe-activedirectory',
            'silverstripe-dms',
            'silverstripe-dms-cart',
            'silverstripe-secureassets',
            'silverstripe-staticpublishqueue',
            'silverstripe-translatable',
        ],
        'symbiote' => [
            'silverstripe-versionedfiles',
        ],
    ],
    'legacy' => [
        'silverstripe-controllerpolicy',
        'silverstripe-elemental-blocks',
        'silverstripe-sqlite3',
    ],
    'tooling' => [
        'lekoala' => [
            'silverstripe-debugbar',
        ],
        'silverstripe' => [
            'cow',
            'eslint-config',
            'silverstripe-upgrader',
            'sspak',
            'vendor-plugin',
            'webpack-config',
        ]
    ],
];

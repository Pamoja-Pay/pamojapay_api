<?php

return [
    'id' => 'basic-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'components' => [
        // 'db' => [ //dev db
        //     'class' => 'yii\db\Connection',
        //     'dsn' => 'mysql:host=localhost;dbname=pamojapay_db-353039386bbe',
        //     'username' => 'root',
        //     'password' => 'Bongoflava@01',
        //     'charset' => 'utf8',
        // ],

        'db' => [ //live
            'class' => 'yii\db\Connection',
            'dsn' => 'mysql:host=mysql.us.stackcp.com:61402;dbname=pamojapay_db-353039386bbe',
            'username' => 'pamojapay_db-353039386bbe',
            'password' => 'ag6h96uwjt',
            'charset' => 'utf8',
        ],

        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    //'levels' => ['error', 'warning','info'],
                    'levels' => ['error'],
                    'exportInterval' => 1,
                    'logFile' => '@app/runtime/logs/app.log',
                    'maxFileSize' => 1024 * 2,
                    'maxLogFiles' => 20,
                ],
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['info'],
                    'logVars' => [],
                    'categories' => ['custom_error'],
                    'exportInterval' => 1,
                    'logFile' => '@app/runtime/logs/custom_error.log',

                ],
            ],
        ],
    ],
    'params' => [
        'sender_email' => 'njengatechs@zohomail.com',
        'jwt_key' => 'hkfdkshfkdhskfhdkhkfhskdfhs',
        'server_name' => 'Pamoja Pay'
    ],
];

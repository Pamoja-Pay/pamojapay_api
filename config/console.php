<?php

return [
    'id' => 'basic-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'components' => [
        'db' => [
            // 'class' => 'yii\db\Connection',
            // 'dsn' => 'mysql:host=localhost;dbname=minick_store_db',
            // 'username' => 'root',
            // 'password' => 'Bongoflava@01',
            // 'charset' => 'utf8',

            'class' => 'yii\db\Connection',
            //'driverName' => 'sqlsrv',
            'dsn' => 'sqlsrv:Server=DESKTOP-4QP3S7A\NEWSERVER;Database=pamojapay_db',
            'username' => 'sys_user',
            'password' => 'sys@2019',
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

<?php

return [
    'id' => 'pamoja-pay-api',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    //'bootstrap' => ['debug'],
    'timeZone' => 'Africa/Dar_es_Salaam',
    'components' => [
        'rateLimiter' => [
            'class' => 'yii\filters\RateLimiter',
            'enableRateLimitHeaders' => true,
            'rateLimit' => 60, // 60 requests
            'timePeriod' => 60, // per 60 seconds
        ],
        'helper' => [
            'class' => 'app\components\Helper',
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'request' => [
            'class' => 'yii\web\Request',
            'enableCsrfValidation' => false,
            'enableCookieValidation' => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
            'enableCsrfCookie' => false,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                ['class' => 'yii\rest\UrlRule', 'controller' => 'service'],
                // other rules...
            ],
        ],
        'mailer' => [
            'class' => 'yii\swiftmailer\Mailer',
            'viewPath' => '@app/mail',
            'useFileTransport' => false, // Set to false to send real emails
            'transport' => [
                'class' => 'Swift_SmtpTransport',
                'host' => 'smtp.zoho.com',
                'username' => 'njengatechs@zohomail.com',
                'password' => 'Bongoflava@01  ',
                'port' => '587',
                'encryption' => 'tls',
            ],
        ],
        'user' => [
            'identityClass' => 'app\models\User',
            'enableAutoLogin' => true,
            'enableSession' => false,
            'loginUrl' => null
        ],
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn' => 'pgsql:host=dpg-d0g68l24d50c73fgl5rg-a;port=5432;dbname=pamojapay_db;sslmode=require;options=--endpoint%3Ddpg-d0g68l24d50c73fgl5rg-a',
            'username' => 'minick',
            'password' => 'tgppLVtBZ4TNmFYRwEN62ae2RbylCdRS',
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
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['info'],
                    'logVars' => [],
                    'categories' => ['request'],
                    'exportInterval' => 1,
                    'logFile' => '@app/runtime/logs/request.log',
                ],
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['info'],
                    'logVars' => [],
                    'categories' => ['credentials'],
                    'exportInterval' => 1,
                    'logFile' => '@app/runtime/logs/credentials.log',
                ],
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning', 'info'],
                    'logVars' => [],
                    'categories' => ['admin_auth'],
                    'exportInterval' => 1,
                    'logFile' => '@app/runtime/logs/admin_auth.log',
                    'maxFileSize' => 1024 * 2,
                    'maxLogFiles' => 20,
                ],
            ],
        ],

        'response' => [
            'class'=>'yii\web\Response',
            'format' =>  \yii\web\Response::FORMAT_JSON,
            'formatters' => [
               \yii\web\Response::FORMAT_JSON => [
                    'class' => 'yii\web\JsonResponseFormatter',
                    'prettyPrint' => YII_DEBUG, // use "pretty" output in debug mode
                    'encodeOptions' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
               ],
            ],
        /* 'on beforeSend' => function($event) {
                $response = $event->sender;
                if ($response->data !== null && $response->statusCode !== 200) 
                {
                    $response->data = [
                        'success'   => $response->isSuccessful,
                        'message'   => $response->data['message'],
                        'code'    => "err50"
                    ];
                }
            } */
        ],

        'errorHandler' => [
            'class' =>  'app\components\ErrorHandler'
        ],
       /*  'debug' => [
            'class' => 'yii\debug\Module',
            // uncomment the following to add your IP if you are not connecting from localhost.
            //'allowedIPs' => ['127.0.0.1', '::1'],
            'allowedIPs'=> ['*']      //<--------- or your IP for security
        ] */
    ],
    'params' => [
        'sender_email' => 'njengatechs@zohomail.com',
        'jwt_key' => 'hkfdkshfkdhskfhdkhkfhskdfhs',
        'server_name' => 'Pamoja Pay',
        'support_notifications' => ["push", "email", "sms", "push, email", "push, sms", "email, sms", "all"]
    ],
];
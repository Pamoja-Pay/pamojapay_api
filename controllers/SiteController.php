<?php

namespace app\controllers;

use yii\rest\Controller;

class SiteController extends Controller
{
    public function behaviors() {
        return [
            'verbs' => [
                'class' => 'yii\filters\VerbFilter',
                'actions' => [
                    'index' => ['GET', 'POST'],
                    '*' => ['POST'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        return [
            'NAME' => 'JOHN MAGAYA',
            'PROJECT' => 'PAMOJA PAY',
            'VERSION'  => '0.0.1' 
        ];
    }

    public function actionMyIp()
    {
        return file_get_contents('https://api.ipify.org');
    }
}
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
}
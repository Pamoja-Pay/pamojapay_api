<?php

namespace app\controllers;

use Yii;
use yii\rest\Controller;
use yii\web\HttpException;
use app\models\Payments;
use app\components\RequestHandler;

class PaymentGatewayController extends Controller
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['contentNegotiator'] = [
            'class' => 'yii\filters\ContentNegotiator',
            'formats' => [
                'application/json' => \yii\web\Response::FORMAT_JSON,
            ],
        ];
        return $behaviors;
    }

    public function beforeAction($action)
    {
        $requestHandler = new RequestHandler();
        $auth = $requestHandler->validateRequest();

        if (!$auth['status']) {
            throw new HttpException(400, $auth['data']);
        }

        return parent::beforeAction($action);
    }

    # Payment Callback
    public function actionPaymentCallback()
    {
        $data = Yii::$app->request->post();

        if (empty($data)) {
            return [
                'success' => false,
                'message' => 'Invalid request',
                'data' => $data
            ];
        }

        // Make sure 'helper' component exists
        Yii::$app->helper->paymentLogs('payment-callback', json_encode($data), 'Invalid Request');

        // Check required fields
        $requiredFields = ['reference', 'status', 'message'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return [
                    'success' => false,
                    'message' => 'Required field missing from the request',
                    'data' => [
                        'field' => $field
                    ]
                ];
            }
        }

        $reference = $data['reference'];
        $paymentDetails = Payments::find()->where(['reference' => $reference])->one();

        if (empty($paymentDetails)) {
            return [
                'success' => false,
                'message' => 'Invalid Reference',
                'data' => ['reference' => $reference]
            ];
        }

        $paymentDetails->status = $data['status'];
        $paymentDetails->updated_at = date('Y-m-d H:i:s');
        $paymentDetails->remark = $data['message'];
        $paymentDetails->save(false);

        return [
            'success' => true,
            'message' => 'Payment callback received successfully',
            'data' => []
        ];
    }
}
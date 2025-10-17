<?php

namespace app\components;

use Yii;
use yii\web\HttpException;
use yii\base\Component;
use app\models\ExternalServiceAccess;



 
class RequestHandler extends Component
{

    public function validateRequest(){
        try {

            //Yii::error("Nimeingia huku kwenye Verification Callback");
            $headers = Yii::$app->request->headers;
            $auth_header = $headers->get('Authorization');
            $service_name = $headers->get('X-Service-Name');
            if(empty($service_name))
            {
                throw new HttpException(401, 'Service name not found');
            }

            $auth_header = explode(" ", $auth_header);
    
            $token = empty($auth_header[1]) ? null : $auth_header[1];
            $bearer = empty($auth_header[0]) ? null : $auth_header[0];
            if(!empty($token))
            {
                $servicesAccess = ExternalServiceAccess::findOne([
                    'service_name' => $service_name,
                    'access_token' => $token,
                    'status' => 1,
                ]);
                if(empty($servicesAccess))
                {
                    throw new HttpException(401, 'Invalid token');
                }
                else{
                    $valid_data = 'Token found';
                    $auth_result = [
                        'status'    => true,
                        'data'      => $valid_data,
                    ];
                }
            }
            else{
                $valid_data = 'Token not found';
                $auth_result = [
                    'status'    => false,
                    'data'      => $valid_data,
                ];
            }

            return $auth_result;
        } catch (\Throwable $th) {
            throw new HttpException(401, $th->getMessage());
        }
    }
}
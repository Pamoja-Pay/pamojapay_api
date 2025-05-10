<?php

namespace app\models;

use Yii;
use yii\base\NotSupportedException;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use \Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * This is the model class for table "users".
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $phone_number
 * @property string $password_hash
 * @property string $pin_code
 * @property string $language
 * @property boolean $biometric_enabled
 * @property string $created_at
 * @property string $status
 * @property string $profile_picture
 * @Property int $package_id
 * @property string $role
 * @property string $device_token
 */
 
class User extends ActiveRecord implements IdentityInterface
{
    public static function tableName()
    {
        return 'users';
    }

    public function rules()
    {
        return [
            [['email', 'phone_number'], 'unique'],
            [['id', 'package_id'], 'integer'],
            [['biometric_enabled'], 'boolean'],
            [['created_at', 'status', 'name', 'password_hash', 'pin_code', 'language', 'profile_picture', 'role', 'device_token'], 'safe'],
        ];
    }

    public function validateToken()
    {
        try {
            $headers = Yii::$app->request->headers;
            // Yii::error("**** THIS IS THE HEADER *******");
            // Yii::error($headers);
            // Yii::error("******************************************");
            $auth_header = $headers->get('Authorization');
            // Yii::error("***** THIS IS AUTHORIZATION ***********");
            // Yii::error($auth_header);
            // Yii::error("******************************************");

            $key = Yii::$app->params['jwt_key'];

            $auth_header = explode(" ", $auth_header);
  
            $token = empty($auth_header[1]) ? null : $auth_header[1];
            $bearer = empty($auth_header[0]) ? null : $auth_header[0];
            if(!empty($token))
            {
                $valid_data = JWT::decode($token, new Key($key, 'HS256'));

                $valid_data = $valid_data->data;
                
                $auth_result = [
                    'status'    => true,
                    'data'      => $valid_data,
                ];
            
            }
            else
            {
                $valid_data = 'Required Authentication';
                $auth_result = [
                    'status'    => false,
                    'data'      => $valid_data,
                ];
            }
            return $auth_result;

        } 
        catch (\Firebase\JWT\ExpiredException $e) {
            // Token has expired
            $valid_data = $e->getMessage();
            $auth_result = [
                'status' => false,
                'message' => 'Token has expired',
                'data' => $valid_data
            ];

            return $auth_result;
           
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            // Signature verification failed
            $valid_data = $e->getMessage();
            $auth_result = [
                'status' => false,
                'message' => 'Signature verification failed',
                'data' => $valid_data
            ];

            return $auth_result;
        } catch (\Exception $e) {
            // Other errors such as invalid token structure
            $valid_data = $e->getMessage();
            $auth_result = [
                'status' => false,
                'message' => 'invalid token structure',
                'data' => $valid_data
            ];

            return $auth_result;
        }
    }
    public static function findIdentity($id)
    {
        return null;
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        try {
            $jwt_key = Yii::$app->params['jwt_key'];
            if(!empty($token))
            {
                $valid_data = JWT::decode($token, new Key($jwt_key, 'HS256'));
            
                $not_before = date('Y-m-d h:i:s',$valid_data->nbf);

                $valid_data = $valid_data->data;

                return new static(self::findOne($valid_data->id));
            }
            else
            {
                $valid_data = 'Required Authentication';
                return null;
            }
        } catch (\Exception $ex) {
            $valid_data = $ex->getMessage();

            return null;
        }
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        return null;
    }

    public function validateAuthKey($authKey)
    {
        return null;
    }

    public function validatePassword($password)
    {
        return false;
    }
}

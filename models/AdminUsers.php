<?php

namespace app\models;

use Yii;
use yii\base\NotSupportedException;
use yii\db\ActiveRecord;
use \Firebase\JWT\JWT;
use Firebase\JWT\Key;
use yii\filters\RateLimitInterface;

/**
 * This is the model class for table "admin_users".
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $mobile
 * @property string $password
 * @property string $status
 * @property string $role
 * @property string $created_at
 * @property string $updated_at
 * @property string $profile
 */
 
class AdminUsers extends ActiveRecord implements RateLimitInterface
{
    /**
     * @var int|null Allowance for rate limiting
     */
    public $allowance;
    /**
     * @var int|null Last updated timestamp for allowance
     */
    public $allowance_updated_at;

    public static function tableName()
    {
        return 'admin_users';
    }

    public function rules()
    {
        return [
            [['email', 'mobile'], 'unique'],
            [['id'], 'integer'],
            [['created_at', 'status', 'name', 'password', 'role', 'updated_at', 'profile'], 'safe'],
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
            //Yii::error("This is passed token: ". $token);
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

    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException;
    }

    // --- RateLimitInterface implementation ---
    /**
     * @inheritdoc
     */
    public function getRateLimit(
        $request,
        $action
    ) {
        // 5 requests per 300 seconds
        return [5, 300];
    }

    /**
     * @inheritdoc
     */
    public function loadAllowance($request, $action)
    {
        return [
            $this->allowance === null ? 5 : $this->allowance,
            $this->allowance_updated_at === null ? time() : $this->allowance_updated_at,
        ];
    }

    /**
     * @inheritdoc
     */
    public function saveAllowance($request, $action, $allowance, $timestamp)
    {
        $this->allowance = $allowance;
        $this->allowance_updated_at = $timestamp;
        // Save only the allowance fields to avoid overwriting other attributes
        Yii::$app->db->createCommand()->update(
            self::tableName(),
            [
                'allowance' => $allowance,
                'allowance_updated_at' => $timestamp,
            ],
            ['id' => $this->id]
        )->execute();
    }
}

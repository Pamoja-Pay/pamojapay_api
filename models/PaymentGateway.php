<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "payment_gateway".
 *
 * @property int $id
 * @property string $api_name
 * @property string $api_key
 * @property string $api_secret
 * @property string $base_url
 * @property string $vendor
 
 */
class PaymentGateway extends ActiveRecord
{
    public static function tableName()
    {
        return 'payment_gateway';
    }

}

<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "external_service_access".
 *
 * @property int $id
 * @property string $service_name
 * @property string $access_token
 * @property int $status
 
 */
class ExternalServiceAccess extends ActiveRecord
{
    public static function tableName()
    {
        return 'external_service_access';
    }
}

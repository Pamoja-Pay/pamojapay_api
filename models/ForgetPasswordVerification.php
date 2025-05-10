<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "forget_password_verification".
 *
 * @property int $id
 *@property int $user_id
 * @property int $OTP
 * @property int $status
 * @property string $created_at
 * @property string $updated_at
 */
class ForgetPasswordVerification extends ActiveRecord
{
    public static function tableName()
    {
        return 'forget_password_verification';
    }
}

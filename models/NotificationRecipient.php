<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "notification_recipient".
 *
 * @property int $id
 * @property int $notification_id
 * @property int $user_id
 * @property string $read_at
 * @property string $created_at
 * @property string $status
 */
class NotificationRecipient extends ActiveRecord
{
    public static function tableName()
    {
        return 'notification_recipient';
    }
}

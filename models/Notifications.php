<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "notifications".
 *
 * @property int $id
 * @property int $user_id
 * @property int $group_id
 * @property string $type
 * @property string $title
 * @property string $message
 * @property string $sent_at
 * @property string $read_at
 * @property int $status
 * @property string $created_at
 * @property string $updated_at
 */
class Notifications extends ActiveRecord
{
    public static function tableName()
    {
        return 'notifications';
    }
}

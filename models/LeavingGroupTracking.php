<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "leaving_group_tracking".
 *
 * @property int $id
 * @property int $user_id
 * @property int $group_id
 * @property int $attempts
 *
 */
class LeavingGroupTracking extends ActiveRecord
{
    public static function tableName()
    {
        return 'leaving_group_tracking';
    }
}

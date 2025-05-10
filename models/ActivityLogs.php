<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "activity_logs".
 *
 * @property int $id
 * @property int $user_id
 * @property int $group_id
 * @property string $action
 * @property string $created_at
 * @property string $request_url
 * @property string $remote_ip
 * @property string $post_params
 * @property string $request_params
 */
class ActivityLogs extends ActiveRecord
{
    public static function tableName()
    {
        return 'activity_logs';
    }
}

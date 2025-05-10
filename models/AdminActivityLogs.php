<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "admin_activity_logs".
 *
 * @property int $id
 * @property int $user_id
 * @property string $action
 * @property string $created_at
 * @property string $request_url
 * @property string $remote_ip
 * @property string $post_params
 * @property string $request_params
 */
class AdminActivityLogs extends ActiveRecord
{
    public static function tableName()
    {
        return 'admin_activity_logs';
    }
}

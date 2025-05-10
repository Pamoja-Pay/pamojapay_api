<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "subscription".
 *
 * @property int $id
 *@property int $user_id
 * @property int $package_id
 * @property int $duration
 * @property string $activated_at
 * @property string $expire_at
 * @property string $created_at
 * @property string $updated_at
 * @property string $created_by
 * @property string $updated_by
 */
class Subscription extends ActiveRecord
{
    public static function tableName()
    {
        return 'subscriptions';
    }
}

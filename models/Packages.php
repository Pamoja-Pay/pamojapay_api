<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "packages".
 *
 * @property int $id
 * @property string $name
 * @property int $group_limit
 * @property int $member_limit
 * @property int $status
 * @property string $created_at
 * @property string $updated_at
 * @property string $notification_support
 * @property float $price
 * @property int $duration
 * @property string $color_code
 */
class Packages extends ActiveRecord
{
    public static function tableName()
    {
        return 'packages';
    }

}

<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "payout_schedule_setting".
 *
 * @property int $id
 * @property int $group_id
 * @property int $days_interval
 * @property string $created_at
 * @property string $updated_at
 */
class PayoutScheduleSetting extends ActiveRecord
{
    public static function tableName()
    {
        return 'payout_schedule_setting';
    }
}

<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "payout_schedule".
 *
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property string $scheduled_date
 * @property string $status
 * @property string $paid_at
 * @property string $created_at
 * @property string $updated_at
 */
class PayoutSchedule extends ActiveRecord
{
    public static function tableName()
    {
        return 'payout_schedule';
    }
}

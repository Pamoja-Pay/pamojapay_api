<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "contribution_schedule".
 *
 * @property int $id
 *@property int $group_id
 * @property int $user_id
 * @property string $due_date
 * @property float $amount
 * @property int $round_number
 * @property boolean $is_paid
 * @property string $paid_at
 */
class ContributionSchedule extends ActiveRecord
{
    public static function tableName()
    {
        return 'contribution_schedule';
    }
}

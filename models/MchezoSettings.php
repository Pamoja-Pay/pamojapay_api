<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "mchezo_settings".
 *
 * @property int    $id
 * @property int    $group_id
 * @property string $contribution_schedule
 * @property int|null    $contribution_schedule_custom_interval
 * @property float  $contribution_amount
 * @property string $receiving_number
 * @property string $payout_schedule
 * @property int|null    $payout_schedule_custom_interval
 * @property float  $payout_percent
 * @property string $start_date
 * @property string $status
 * @property string $withdraw_initiator
 * @property string $withdraw_approver
 * @property string $created_at
 * @property string $updated_at
 * @property int    $created_by
 * @property int    $updated_by 
 */
class MchezoSettings extends ActiveRecord
{
    public static function tableName()
    {
        return 'mchezo_settings';
    }
}

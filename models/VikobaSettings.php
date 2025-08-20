<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "vikoba_settings".
 *
 * @property int $id
 * @property int $group_id
 * @property string $contribution_schedule
 * @property float  $contribution_amount
 * @property string $receiving_number
 * @property float  $shares_value
 * @property int    $max_shares_per_member
 * @property int    $allow_selling
 * @property string $start_date
 * @property string $status
 * @property string $withdraw_initiator
 * @property string $withdraw_approver
 * @property string $created_at
 * @property string $updated_at
 * @property int    $created_by
 * @property int    $updated_by 
 */
class VikobaSettings extends ActiveRecord
{
    public static function tableName()
    {
        return 'vikoba_settings';
    }
}

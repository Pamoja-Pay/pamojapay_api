<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "event_settings".
 *
 * @property int    $id
 * @property int    $group_id
 * @property string $receiving_number
 * @property string $pledge_deadline
 * @property string $contribution_deadline
 * @property string $status
 * @property string $withdraw_initiator
 * @property string $withdraw_approver
 * @property string $created_at
 * @property string $updated_at
 * @property int    $created_by
 * @property int    $updated_by 
 */
class EventSettings extends ActiveRecord
{
    public static function tableName()
    {
        return 'event_settings';
    }
}

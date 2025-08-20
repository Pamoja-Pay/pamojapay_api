<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "ujamaa_settings".
 *
 * @property int    $id
 * @property int    $group_id
 * @property string $start_date
 * @property string $receiving_number
 * @property float  $minimum_amount
 * @property string $approver_title
 * @property string $status
 * @property string $withdraw_initiator
 * @property string $withdraw_approver
 * @property string $created_at
 * @property string $updated_at
 * @property int    $created_by
 * @property int    $updated_by 
 */
class UjamaaSettings extends ActiveRecord
{
    public static function tableName()
    {
        return 'ujamaa_settings';
    }
}

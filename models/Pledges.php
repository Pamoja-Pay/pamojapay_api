<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "pledges".
 *
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property float $amount
 * @property string $pledge_date
 * @property string $to_be_paid_at
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 * @property float $paid_amount
 * @property float $remain_amount
 * @property string $paid_at
 */
class Pledges extends ActiveRecord
{
    public static function tableName()
    {
        return 'pledges';
    }

}

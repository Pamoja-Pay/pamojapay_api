<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "contributions".
 *
 * @property int $id
 *@property int $group_id
 * @property int $grace_period_days
 * @property float $amount
 * @property string $frequency
 * @property string $start_date
 * @property string $payment_method
 * @property string $created_at
 * @property string $updated_at
 */
class Contributions extends ActiveRecord
{
    public static function tableName()
    {
        return 'contributions';
    }
}

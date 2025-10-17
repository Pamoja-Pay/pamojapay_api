<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "payments".
 *
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property float $amount
 * @property string $payment_date
 * @property string $reference
 * @property int $verified_by
 * @property string $verified_at
 * @property string $proof_url
 * @property string $payment_method
 * @property string $status
 * @property string $rejection_reason
 * @property string $payment_for
 * @property string $updated_at
 * @property string $remark
 */
class Payments extends ActiveRecord
{
    public static function tableName()
    {
        return 'payments';
    }

}

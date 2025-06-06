<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "push_payment_request".
 *
 * @property int $id
 * @property string $trans_date
 * @property string $trans_ref
 * @property float $amount
 * @property string $channel
 * @property string $msisdn
 * @property string $status
 * @property string $type
 * @property string $mno_ref
 * @property string $created_at
 * @property string $updated_at
 * @property int $user_id
 */
class PushPaymentRequest extends ActiveRecord
{
    public static function tableName()
    {
        return 'push_payment_request';
    }

}

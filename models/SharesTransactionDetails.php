<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "shares_transaction_details".
 *
 * @property int $id
 * @property string $trans_date
 * @property string $trans_ref
 * @property float $amount
 * @property int $group_id
 * @property string $channel
 * @property string $msisdn
 * @property string $status
 * @property string $mno_ref
 * @property string $created_at
 * @property string $updated_at
 * @property int $user_id
 */
class SharesTransactionDetails extends ActiveRecord
{
    public static function tableName()
    {
        return 'shares_transaction_details';
    }

}

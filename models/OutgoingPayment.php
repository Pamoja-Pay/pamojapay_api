<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "outgoing_payment".
 *
 * @property int $id
 * @property float $amount
 * @property int $group_id
 * @property int $recipient_id
 * @property string $reason
 * @property string $created_at
 * @property int $created_by
 * @property bool $approver_one
 * @property bool $approver_two
 * @property string $status
 * @property string $updated_at
 */
class OutgoingPayment extends ActiveRecord
{
    public static function tableName()
    {
        return 'outgoing_payment';
    }

}

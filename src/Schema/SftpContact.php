<?php

declare(strict_types=1);

namespace App\Schema;

/**
 * Columns for data_db.sftp_contact_{account_id} (Mailhub SFTP contact base).
 *
 * @phpstan-type Attr array{name:string,type:string,filterable:bool,group:string,comment?:string}
 */
final class SftpContact
{
    /** @return list<Attr> */
    public static function columns(): array
    {
        $core = [
            ['name' => 'id', 'type' => 'bigint', 'filterable' => true, 'group' => 'core', 'comment' => 'Internal auto-increment ID'],
            ['name' => 'account_id', 'type' => 'integer', 'filterable' => true, 'group' => 'core', 'comment' => 'Mailhub account identifier'],
            ['name' => 'primary_key', 'type' => 'varchar', 'filterable' => true, 'group' => 'core', 'comment' => 'Client primary unique identifier'],
            ['name' => 'email', 'type' => 'varchar', 'filterable' => true, 'group' => 'core', 'comment' => 'f0: Email ID'],
            ['name' => 'mobile', 'type' => 'varchar', 'filterable' => true, 'group' => 'core', 'comment' => 'f1: Mobile For SMS'],
            ['name' => 'created_at', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'updated_at', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'subscribed_on', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'last_emailed', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'last_sms', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'is_opened', 'type' => 'tinyint', 'filterable' => true, 'group' => 'core'],
            ['name' => 'is_clicked', 'type' => 'tinyint', 'filterable' => true, 'group' => 'core'],
            ['name' => 'last_opened', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'last_clicked', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'campaign_sent_cnt', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'campaign_opened_cnt', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'campaign_clicked_cnt', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'sms_campaign_sent_cnt', 'type' => 'integer', 'filterable' => true, 'group' => 'core'],
            ['name' => 'email_status', 'type' => 'tinyint', 'filterable' => true, 'group' => 'core', 'comment' => '0=deleted/fake 1=active 2=unsub 3=bounce 4=spam 5=manual 6=unverified'],
            ['name' => 'email_suppressed_on', 'type' => 'integer', 'filterable' => false, 'group' => 'core'],
            ['name' => 'sms_status', 'type' => 'tinyint', 'filterable' => true, 'group' => 'core'],
            ['name' => 'sms_suppressed_on', 'type' => 'integer', 'filterable' => false, 'group' => 'core'],
            ['name' => 'is_deleted', 'type' => 'tinyint', 'filterable' => true, 'group' => 'core'],
            ['name' => 'is_contact', 'type' => 'tinyint', 'filterable' => true, 'group' => 'core'],
            ['name' => 'is_preview', 'type' => 'tinyint', 'filterable' => true, 'group' => 'core'],
            ['name' => 'import_source', 'type' => 'tinyint', 'filterable' => true, 'group' => 'core', 'comment' => '1=upload 2=manual 3=copy 4=sftp 5=api 6=webform'],
            ['name' => 'domain', 'type' => 'varchar', 'filterable' => true, 'group' => 'core'],
            ['name' => 'data', 'type' => 'blob', 'filterable' => false, 'group' => 'core'],
        ];

        $labels = [
            2 => 'Account (Unique Identifier)',
            3 => 'Account Open Year',
            4 => 'Account Open Month',
            5 => 'Logo',
            6 => 'Organization',
            7 => 'Account Opening Date',
            8 => 'Transfer Indicator',
            9 => 'Date of Birth',
            10 => 'DNE Flag',
            11 => 'DNS Flag',
            12 => 'DNC Flag',
            13 => 'Mobile Home',
            14 => 'Product',
            15 => 'Product Cut',
            16 => 'Risk Channel',
            17 => 'Card Network',
            18 => 'City',
            19 => 'State',
            20 => 'Customer Name',
            21 => 'Gender',
            22 => 'MOB',
            23 => 'Occupation',
            24 => 'Total Credit Limit',
            25 => 'Promo Code',
            26 => 'Block Code 1',
            27 => 'Block Code 2',
            28 => 'Customer Number',
            29 => 'UCIC Key',
            30 => 'Active Network',
            31 => 'Age',
            32 => 'COL1',
            33 => 'COL2',
            34 => 'COL3',
            35 => 'COL4',
            36 => 'Date 1',
            37 => 'Date 2',
            38 => 'Date 3',
            39 => 'NUM1',
            40 => 'NUM2',
            41 => 'NUM3',
        ];

        $custom = [];
        for ($i = 2; $i <= 41; $i++) {
            $name = 'f' . $i;
            $type = $i === 31 ? 'tinyint' : 'varchar';
            $custom[] = [
                'name' => $name,
                'type' => $type,
                'filterable' => true,
                'group' => 'custom',
                'comment' => $name . ': ' . ($labels[$i] ?? ''),
            ];
        }

        return array_merge($core, $custom);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (array $c): string => $c['name'], self::columns());
    }

    /** @return Attr|null */
    public static function byName(string $name): ?array
    {
        foreach (self::columns() as $col) {
            if ($col['name'] === $name) {
                return $col;
            }
        }
        return null;
    }

    public static function tableName(int $accountId): string
    {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('account_id must be positive');
        }
        return 'sftp_contact_' . $accountId;
    }

    public static function qualifiedTable(int $accountId, string $schema = 'data_db'): string
    {
        return '`' . str_replace('`', '``', $schema) . '`.`' . self::tableName($accountId) . '`';
    }
}

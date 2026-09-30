<?php

declare(strict_types=1);

namespace App\Schema;

/** Contact profile columns. Mirrors the TempCodeShare Go schema. */
final class ContactAttrs
{
    /** @return list<array{name:string,type:string,filterable:bool,group:string}> */
    public static function core(): array
    {
        return [
        ["name" => "id", "type" => "bigint", "filterable" => true, "group" => "core"],
        ["name" => "account_id", "type" => "bigint", "filterable" => true, "group" => "core"],
        ["name" => "primary_key", "type" => "varchar", "filterable" => true, "group" => "core"],
        ["name" => "email", "type" => "varchar", "filterable" => true, "group" => "core"],
        ["name" => "mobile", "type" => "varchar", "filterable" => true, "group" => "core"],
        ["name" => "first_name", "type" => "varchar", "filterable" => true, "group" => "core"],
        ["name" => "last_name", "type" => "varchar", "filterable" => true, "group" => "core"],
        ["name" => "created_at", "type" => "timestamp", "filterable" => true, "group" => "core"],
        ["name" => "updated_at", "type" => "timestamp", "filterable" => false, "group" => "core"],
        ["name" => "subscribed_on", "type" => "timestamp", "filterable" => true, "group" => "core"],
        ["name" => "last_emailed", "type" => "timestamp", "filterable" => true, "group" => "core"],
        ["name" => "last_sms", "type" => "timestamp", "filterable" => true, "group" => "core"],
        ["name" => "is_opened", "type" => "tinyint", "filterable" => true, "group" => "core"],
        ["name" => "is_clicked", "type" => "tinyint", "filterable" => true, "group" => "core"],
        ["name" => "last_opened", "type" => "timestamp", "filterable" => true, "group" => "core"],
        ["name" => "last_clicked", "type" => "timestamp", "filterable" => true, "group" => "core"],
        ["name" => "campaign_sent_cnt", "type" => "integer", "filterable" => true, "group" => "core"],
        ["name" => "campaign_opened_cnt", "type" => "integer", "filterable" => true, "group" => "core"],
        ["name" => "campaign_clicked_cnt", "type" => "integer", "filterable" => true, "group" => "core"],
        ["name" => "sms_campaign_sent_cnt", "type" => "integer", "filterable" => true, "group" => "core"],
        ["name" => "email_status", "type" => "tinyint", "filterable" => true, "group" => "core"],
        ["name" => "email_suppressed_on", "type" => "timestamp", "filterable" => false, "group" => "core"],
        ["name" => "sms_status", "type" => "tinyint", "filterable" => true, "group" => "core"],
        ["name" => "sms_suppressed_on", "type" => "timestamp", "filterable" => false, "group" => "core"],
        ["name" => "is_deleted", "type" => "tinyint", "filterable" => true, "group" => "core"],
        ["name" => "is_contact", "type" => "tinyint", "filterable" => true, "group" => "core"],
        ["name" => "is_preview", "type" => "tinyint", "filterable" => false, "group" => "core"],
        ["name" => "import_source", "type" => "tinyint", "filterable" => true, "group" => "core"],
        ["name" => "domain", "type" => "varchar", "filterable" => true, "group" => "core"],
        ];
    }

    /** @return list<array{name:string,type:string,filterable:bool,group:string}> */
    public static function profile(): array
    {
        return [
        ["name" => "title", "type" => "varchar", "filterable" => true, "group" => "identity"],
        ["name" => "middle_name", "type" => "varchar", "filterable" => true, "group" => "identity"],
        ["name" => "gender", "type" => "varchar", "filterable" => true, "group" => "identity"],
        ["name" => "date_of_birth", "type" => "date", "filterable" => true, "group" => "identity"],
        ["name" => "age", "type" => "integer", "filterable" => true, "group" => "identity"],
        ["name" => "language", "type" => "varchar", "filterable" => true, "group" => "identity"],
        ["name" => "timezone", "type" => "varchar", "filterable" => true, "group" => "identity"],
        ["name" => "nationality", "type" => "varchar", "filterable" => true, "group" => "identity"],
        ["name" => "marital_status", "type" => "varchar", "filterable" => true, "group" => "identity"],
        ["name" => "address_line1", "type" => "varchar", "filterable" => true, "group" => "geo"],
        ["name" => "address_line2", "type" => "varchar", "filterable" => false, "group" => "geo"],
        ["name" => "city", "type" => "varchar", "filterable" => true, "group" => "geo"],
        ["name" => "state", "type" => "varchar", "filterable" => true, "group" => "geo"],
        ["name" => "country", "type" => "varchar", "filterable" => true, "group" => "geo"],
        ["name" => "postal_code", "type" => "varchar", "filterable" => true, "group" => "geo"],
        ["name" => "region", "type" => "varchar", "filterable" => true, "group" => "geo"],
        ["name" => "metro_area", "type" => "varchar", "filterable" => true, "group" => "geo"],
        ["name" => "latitude", "type" => "double", "filterable" => true, "group" => "geo"],
        ["name" => "longitude", "type" => "double", "filterable" => true, "group" => "geo"],
        ["name" => "company", "type" => "varchar", "filterable" => true, "group" => "firmographic"],
        ["name" => "job_title", "type" => "varchar", "filterable" => true, "group" => "firmographic"],
        ["name" => "department", "type" => "varchar", "filterable" => true, "group" => "firmographic"],
        ["name" => "industry", "type" => "varchar", "filterable" => true, "group" => "firmographic"],
        ["name" => "seniority", "type" => "varchar", "filterable" => true, "group" => "firmographic"],
        ["name" => "employee_count", "type" => "integer", "filterable" => true, "group" => "firmographic"],
        ["name" => "annual_revenue", "type" => "double", "filterable" => true, "group" => "firmographic"],
        ["name" => "company_domain", "type" => "varchar", "filterable" => true, "group" => "firmographic"],
        ["name" => "linkedin_url", "type" => "varchar", "filterable" => false, "group" => "firmographic"],
        ["name" => "website", "type" => "varchar", "filterable" => false, "group" => "firmographic"],
        ["name" => "email_opt_in", "type" => "tinyint", "filterable" => true, "group" => "preference"],
        ["name" => "sms_opt_in", "type" => "tinyint", "filterable" => true, "group" => "preference"],
        ["name" => "push_opt_in", "type" => "tinyint", "filterable" => true, "group" => "preference"],
        ["name" => "preferred_channel", "type" => "varchar", "filterable" => true, "group" => "preference"],
        ["name" => "lifecycle_stage", "type" => "varchar", "filterable" => true, "group" => "preference"],
        ["name" => "lead_source", "type" => "varchar", "filterable" => true, "group" => "preference"],
        ["name" => "lead_score", "type" => "integer", "filterable" => true, "group" => "preference"],
        ["name" => "do_not_call", "type" => "tinyint", "filterable" => true, "group" => "preference"],
        ["name" => "marketing_consent_at", "type" => "timestamp", "filterable" => true, "group" => "preference"],
        ["name" => "last_purchase_at", "type" => "timestamp", "filterable" => true, "group" => "commerce"],
        ["name" => "first_purchase_at", "type" => "timestamp", "filterable" => true, "group" => "commerce"],
        ["name" => "total_orders", "type" => "integer", "filterable" => true, "group" => "commerce"],
        ["name" => "total_spend", "type" => "double", "filterable" => true, "group" => "commerce"],
        ["name" => "ltv", "type" => "double", "filterable" => true, "group" => "commerce"],
        ["name" => "avg_order_value", "type" => "double", "filterable" => true, "group" => "commerce"],
        ["name" => "product_interest", "type" => "varchar", "filterable" => true, "group" => "commerce"],
        ["name" => "rfm_score", "type" => "integer", "filterable" => true, "group" => "commerce"],
        ["name" => "cart_value", "type" => "double", "filterable" => true, "group" => "commerce"],
        ["name" => "last_cart_at", "type" => "timestamp", "filterable" => true, "group" => "commerce"],
        ["name" => "subscription_plan", "type" => "varchar", "filterable" => true, "group" => "commerce"],
        ["name" => "subscription_status", "type" => "varchar", "filterable" => true, "group" => "commerce"],
        ["name" => "loyalty_tier", "type" => "varchar", "filterable" => true, "group" => "loyalty"],
        ["name" => "loyalty_points", "type" => "integer", "filterable" => true, "group" => "loyalty"],
        ["name" => "store_id", "type" => "varchar", "filterable" => true, "group" => "loyalty"],
        ["name" => "sales_rep", "type" => "varchar", "filterable" => true, "group" => "loyalty"],
        ["name" => "account_manager", "type" => "varchar", "filterable" => true, "group" => "loyalty"],
        ["name" => "customer_since", "type" => "date", "filterable" => true, "group" => "loyalty"],
        ["name" => "churn_risk", "type" => "varchar", "filterable" => true, "group" => "loyalty"],
        ["name" => "nps_score", "type" => "integer", "filterable" => true, "group" => "loyalty"],
        ["name" => "support_tickets", "type" => "integer", "filterable" => true, "group" => "loyalty"],
        ["name" => "device_type", "type" => "varchar", "filterable" => true, "group" => "digital"],
        ["name" => "os_name", "type" => "varchar", "filterable" => true, "group" => "digital"],
        ["name" => "browser_name", "type" => "varchar", "filterable" => true, "group" => "digital"],
        ["name" => "app_version", "type" => "varchar", "filterable" => true, "group" => "digital"],
        ["name" => "last_login_at", "type" => "timestamp", "filterable" => true, "group" => "digital"],
        ["name" => "session_count", "type" => "integer", "filterable" => true, "group" => "digital"],
        ["name" => "utm_source", "type" => "varchar", "filterable" => true, "group" => "digital"],
        ["name" => "utm_campaign", "type" => "varchar", "filterable" => true, "group" => "digital"],
        ["name" => "utm_medium", "type" => "varchar", "filterable" => true, "group" => "digital"],
        ["name" => "referrer", "type" => "varchar", "filterable" => true, "group" => "digital"],
        ["name" => "segment_tag", "type" => "varchar", "filterable" => true, "group" => "misc"],
        ["name" => "cohort", "type" => "varchar", "filterable" => true, "group" => "misc"],
        ["name" => "persona", "type" => "varchar", "filterable" => true, "group" => "misc"],
        ["name" => "vip_flag", "type" => "tinyint", "filterable" => true, "group" => "misc"],
        ["name" => "test_group", "type" => "varchar", "filterable" => true, "group" => "misc"],
        ["name" => "external_id", "type" => "varchar", "filterable" => true, "group" => "misc"],
        ["name" => "crm_id", "type" => "varchar", "filterable" => true, "group" => "misc"],
        ["name" => "tax_id", "type" => "varchar", "filterable" => false, "group" => "misc"],
        ["name" => "notes", "type" => "varchar", "filterable" => false, "group" => "misc"],
        ["name" => "custom_label_1", "type" => "varchar", "filterable" => true, "group" => "misc"],
        ["name" => "custom_label_2", "type" => "varchar", "filterable" => true, "group" => "misc"],
        ["name" => "custom_score_1", "type" => "integer", "filterable" => true, "group" => "misc"],
        ["name" => "custom_score_2", "type" => "integer", "filterable" => true, "group" => "misc"],
        ["name" => "custom_flag_1", "type" => "tinyint", "filterable" => true, "group" => "misc"],
        ["name" => "custom_flag_2", "type" => "tinyint", "filterable" => true, "group" => "misc"],
        ["name" => "event_attendance", "type" => "integer", "filterable" => true, "group" => "misc"],
        ["name" => "webinar_count", "type" => "integer", "filterable" => true, "group" => "misc"],
        ["name" => "content_downloads", "type" => "integer", "filterable" => true, "group" => "misc"],
        ["name" => "last_email_subject", "type" => "varchar", "filterable" => true, "group" => "misc"],
        ["name" => "preferred_send_hour", "type" => "integer", "filterable" => true, "group" => "misc"],
        ];
    }

    /** @return list<array{name:string,type:string,filterable:bool,group:string}> */
    public static function lake(): array
    {
        return [
        ["name" => "f4", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f5", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f6", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f7", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f8", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f9", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f10", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f11", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f12", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f13", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ["name" => "f14", "type" => "varchar", "filterable" => true, "group" => "lake"],
        ];
    }

    /** @return list<array{name:string,type:string,filterable:bool,group:string}> */
    public static function all(): array
    {
        return array_merge(self::core(), self::profile(), self::lake());
    }

    /** @return list<array{name:string,type:string,filterable:bool,group:string}> */
    public static function filterable(): array
    {
        return array_values(array_filter(self::all(), static fn (array $a): bool => $a["filterable"]));
    }

    /** @return array{name:string,type:string,filterable:bool,group:string}|null */
    public static function byName(string $name): ?array
    {
        foreach (self::all() as $attr) {
            if ($attr["name"] === $name) {
                return $attr;
            }
        }
        return null;
    }

    public static function duckType(string $type): string
    {
        return match ($type) {
            "varchar" => "VARCHAR",
            "integer" => "INTEGER",
            "bigint" => "BIGINT",
            "double" => "DOUBLE",
            "tinyint" => "TINYINT",
            "timestamp" => "TIMESTAMP",
            "date" => "DATE",
            default => "VARCHAR",
        };
    }
}

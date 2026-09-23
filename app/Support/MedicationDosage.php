<?php

namespace App\Support;

/**
 * Format and normalize medication product label mix ratios.
 */
final class MedicationDosage
{
    public const DILUENT_TYPES = ['water', 'feed', 'other'];

    public const MEDICINE_UNITS = ['g', 'mg', 'ml', 'tablet'];

    /**
     * @param  array<string, mixed>|object  $source
     */
    public static function formatRatio(array|object $source, string $prefix): ?string
    {
        $get = function (string $key) use ($source) {
            if (is_array($source)) {
                return $source[$key] ?? null;
            }

            return $source->{$key} ?? null;
        };

        $medicineAmount = $get("{$prefix}_medicine_amount");
        $medicineUnit = $get("{$prefix}_medicine_unit");
        if ($medicineAmount === null || $medicineAmount === '' || ! $medicineUnit) {
            return null;
        }

        $amount = rtrim(rtrim(number_format((float) $medicineAmount, 4, '.', ''), '0'), '.');
        $line = "{$amount} {$medicineUnit}";

        $diluentAmount = $get("{$prefix}_diluent_amount");
        $diluentUnit = $get("{$prefix}_diluent_unit");
        $diluentType = $get("{$prefix}_diluent_type");
        $diluentLabel = $get("{$prefix}_diluent_label");

        if ($diluentAmount !== null && $diluentAmount !== '' && $diluentUnit && $diluentType) {
            $dAmount = rtrim(rtrim(number_format((float) $diluentAmount, 4, '.', ''), '0'), '.');
            $of = $diluentType === 'other'
                ? (trim((string) $diluentLabel) ?: 'other')
                : $diluentType;
            $line .= " per {$dAmount} {$diluentUnit} {$of}";
        }

        return $line;
    }

    /**
     * Validate one dosage block. Returns error messages keyed by field, or empty if valid/empty.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public static function validateBlock(array $data, string $prefix): array
    {
        $keys = [
            "{$prefix}_medicine_amount",
            "{$prefix}_medicine_unit",
            "{$prefix}_diluent_amount",
            "{$prefix}_diluent_unit",
            "{$prefix}_diluent_type",
            "{$prefix}_diluent_label",
        ];

        $present = false;
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $present = true;
                break;
            }
        }

        if (! $present) {
            return [];
        }

        $errors = [];
        $medicineAmount = $data["{$prefix}_medicine_amount"] ?? null;
        $medicineUnit = $data["{$prefix}_medicine_unit"] ?? null;
        $diluentAmount = $data["{$prefix}_diluent_amount"] ?? null;
        $diluentUnit = $data["{$prefix}_diluent_unit"] ?? null;
        $diluentType = $data["{$prefix}_diluent_type"] ?? null;
        $diluentLabel = $data["{$prefix}_diluent_label"] ?? null;

        if ($medicineAmount === null || $medicineAmount === '' || (float) $medicineAmount <= 0) {
            $errors["{$prefix}_medicine_amount"] = ['Medicine amount is required and must be greater than 0.'];
        }
        if (! $medicineUnit) {
            $errors["{$prefix}_medicine_unit"] = ['Medicine unit is required.'];
        }
        if ($diluentAmount === null || $diluentAmount === '' || (float) $diluentAmount <= 0) {
            $errors["{$prefix}_diluent_amount"] = ['Diluent amount is required and must be greater than 0.'];
        }
        if (! $diluentUnit) {
            $errors["{$prefix}_diluent_unit"] = ['Diluent unit is required.'];
        }
        if (! $diluentType || ! in_array($diluentType, self::DILUENT_TYPES, true)) {
            $errors["{$prefix}_diluent_type"] = ['Diluent type must be water, feed, or other.'];
        }
        if ($diluentType === 'other' && ! trim((string) $diluentLabel)) {
            $errors["{$prefix}_diluent_label"] = ['Diluent name is required when type is other.'];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function syncLegacyDosage(array $data): array
    {
        if (isset($data['treatment_medicine_amount']) && $data['treatment_medicine_amount'] !== null && $data['treatment_medicine_amount'] !== '') {
            $data['dosage'] = $data['treatment_medicine_amount'];
            $data['dosage_unit'] = $data['treatment_medicine_unit'] ?? ($data['dosage_unit'] ?? 'ml');
        } elseif (isset($data['preventive_medicine_amount']) && $data['preventive_medicine_amount'] !== null && $data['preventive_medicine_amount'] !== '') {
            $data['dosage'] = $data['preventive_medicine_amount'];
            $data['dosage_unit'] = $data['preventive_medicine_unit'] ?? ($data['dosage_unit'] ?? 'ml');
        }

        return $data;
    }

    /**
     * Ratio field names for mass assignment.
     *
     * @return list<string>
     */
    public static function fieldNames(): array
    {
        $fields = [];
        foreach (['preventive', 'treatment'] as $prefix) {
            $fields[] = "{$prefix}_medicine_amount";
            $fields[] = "{$prefix}_medicine_unit";
            $fields[] = "{$prefix}_diluent_amount";
            $fields[] = "{$prefix}_diluent_unit";
            $fields[] = "{$prefix}_diluent_type";
            $fields[] = "{$prefix}_diluent_label";
        }

        return $fields;
    }
}

<?php

declare(strict_types=1);

namespace IEdify\Modules\Programs\Services;

/**
 * Constrained application-form schema. Only allowlisted field types and
 * rule operators are accepted — no arbitrary code or free-form markup.
 */
final readonly class FormDefinition
{
    private const FIELD_TYPES = ['text', 'number', 'date', 'select', 'checkbox', 'document'];
    private const RULE_OPS = ['eq', 'ne', 'gte', 'lte', 'in'];

    /**
     * @return list<array{key:string,label:string,type:string,required:bool,options:list<string>,help:string}>
     */
    public function validate(array $fields): array
    {
        if ($fields === [] || count($fields) > 60) {
            throw new \InvalidArgumentException('A form needs between 1 and 60 fields.');
        }
        $seen = [];
        $clean = [];
        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                throw new \InvalidArgumentException('Every field must be an object.');
            }
            $key = (string) ($field['key'] ?? '');
            if (!preg_match('~^[a-z][a-z0-9_]{1,60}$~D', $key) || isset($seen[$key])) {
                throw new \InvalidArgumentException('Field keys must be unique lowercase identifiers.');
            }
            $seen[$key] = true;
            $label = trim((string) ($field['label'] ?? ''));
            $type = (string) ($field['type'] ?? '');
            if ($label === '' || mb_strlen($label) > 180 || !in_array($type, self::FIELD_TYPES, true)) {
                throw new \InvalidArgumentException("Field {$key} needs a label and a supported type.");
            }
            $options = [];
            if ($type === 'select') {
                $options = array_values(array_filter(array_map('strval', (array) ($field['options'] ?? []))));
                if ($options === [] || count($options) > 50) {
                    throw new \InvalidArgumentException("Select field {$key} needs 1-50 options.");
                }
            }
            $clean[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'options' => $options,
                'help' => mb_substr(trim((string) ($field['help'] ?? '')), 0, 500),
            ];
        }
        return $clean;
    }

    /**
     * Validate submitted answers against a field schema. Document answers are
     * handled separately via uploads and referenced by media id.
     *
     * @return array<string, string|bool>
     */
    public function validateAnswers(array $fields, array $answers, bool $requireAll): array
    {
        $clean = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $raw = $answers[$key] ?? null;
            $isEmpty = $raw === null || $raw === '' || $raw === [];
            if ($isEmpty) {
                if ($field['required'] && $requireAll) {
                    throw new \InvalidArgumentException("{$field['label']} is required.");
                }
                continue;
            }
            $clean[$key] = match ($field['type']) {
                'text' => $this->text($raw, $field),
                'number' => $this->number($raw, $field),
                'date' => $this->date($raw, $field),
                'select' => $this->select($raw, $field),
                'checkbox' => $this->checkbox($raw),
                'document' => $this->documentRef($raw, $field),
                default => throw new \InvalidArgumentException("{$field['label']} uses an unsupported type."),
            };
        }
        return $clean;
    }

    /**
     * Evaluate constrained eligibility rules against applicant context values.
     * Unknown operators and fields evaluate as not eligible rather than fatal.
     *
     * @return list<string> unmet rule descriptions
     */
    public function unmetRules(array $rules, array $context): array
    {
        $unmet = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $field = (string) ($rule['field'] ?? '');
            $op = (string) ($rule['op'] ?? '');
            $expected = $rule['value'] ?? null;
            $actual = $context[$field] ?? null;
            $pass = match ($op) {
                'eq' => $actual !== null && (string) $actual === (string) $expected,
                'ne' => $actual === null || (string) $actual !== (string) $expected,
                'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
                'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
                'in' => is_array($expected) && in_array((string) $actual, array_map('strval', $expected), true),
                default => false,
            };
            if (!$pass) {
                $unmet[] = (string) ($rule['label'] ?? $field);
            }
        }
        return $unmet;
    }

    /**
     * @return list<array{field:string,op:string,value:mixed,label:string}>
     */
    public function validateRules(array $rules): array
    {
        $clean = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new \InvalidArgumentException('Eligibility rules must be objects.');
            }
            $field = (string) ($rule['field'] ?? '');
            $op = (string) ($rule['op'] ?? '');
            if (!preg_match('~^[a-z][a-z0-9_]{1,60}$~D', $field) || !in_array($op, self::RULE_OPS, true)) {
                throw new \InvalidArgumentException('Eligibility rules use allowlisted fields and operators only.');
            }
            $value = $rule['value'] ?? null;
            if ($op === 'in') {
                if (!is_array($value) || $value === []) {
                    throw new \InvalidArgumentException("Rule {$field} needs a list of values.");
                }
                $value = array_map('strval', $value);
            } elseif (!is_scalar($value)) {
                throw new \InvalidArgumentException("Rule {$field} needs a scalar value.");
            }
            $clean[] = ['field' => $field, 'op' => $op, 'value' => $value, 'label' => mb_substr(trim((string) ($rule['label'] ?? $field)), 0, 180)];
        }
        return $clean;
    }

    private function text(mixed $raw, array $field): string
    {
        if (!is_string($raw)) {
            throw new \InvalidArgumentException("{$field['label']} must be text.");
        }
        $value = trim($raw);
        if (mb_strlen($value) > 4000) {
            throw new \InvalidArgumentException("{$field['label']} is too long.");
        }
        return $value;
    }

    private function number(mixed $raw, array $field): string
    {
        if (!is_numeric($raw) || (float) $raw > 999999999 || (float) $raw < -999999999) {
            throw new \InvalidArgumentException("{$field['label']} must be a valid number.");
        }
        return (string) $raw;
    }

    private function date(mixed $raw, array $field): string
    {
        if (!is_string($raw) || !preg_match('~^\d{4}-\d{2}-\d{2}$~D', $raw) || !strtotime($raw)) {
            throw new \InvalidArgumentException("{$field['label']} must be a date (YYYY-MM-DD).");
        }
        return $raw;
    }

    private function select(mixed $raw, array $field): string
    {
        if (!is_string($raw) || !in_array($raw, $field['options'], true)) {
            throw new \InvalidArgumentException("{$field['label']} must be one of the listed options.");
        }
        return $raw;
    }

    private function checkbox(mixed $raw): bool
    {
        return in_array($raw, [true, '1', 'on', 1], true);
    }

    private function documentRef(mixed $raw, array $field): string
    {
        if (!is_scalar($raw) || !ctype_digit((string) $raw)) {
            throw new \InvalidArgumentException("{$field['label']} needs an uploaded document.");
        }
        return (string) $raw;
    }
}

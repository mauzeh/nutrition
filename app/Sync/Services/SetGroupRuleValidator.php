<?php

namespace App\Sync\Services;

use App\Services\ExerciseTypes\ExerciseTypeFactory;
use Illuminate\Validation\ValidationException;

class SetGroupRuleValidator
{
    /**
     * Map front-end set field names to exercise type field names.
     */
    private const FIELD_ALIAS_MAP = [
        'duration' => 'time',
    ];

    /**
     * Validate an array of sets against an exercise type's group_rule.
     * Throws ValidationException when any set fails the rule.
     *
     * @param string $exerciseType Strategy type name (e.g. 'timed_output')
     * @param array $sets Array of set data arrays
     * @throws ValidationException
     */
    public function validateSets(string $exerciseType, array $sets): void
    {
        $strategy = ExerciseTypeFactory::createFromTypeName($exerciseType);
        $config = $strategy->getTypeConfig();
        $groupRule = $config['group_rule'] ?? null;

        if (!$groupRule) {
            return;
        }

        if (($groupRule['kind'] ?? null) === 'require_one_of') {
            $requiredFields = $groupRule['fields'] ?? [];

            foreach ($sets as $index => $set) {
                $hasLoggedField = false;

                foreach ($requiredFields as $field) {
                    $val = $this->extractFieldValue($set, $field);
                    if ($val !== null && is_numeric($val) && (float) $val > 0) {
                        $hasLoggedField = true;
                        break;
                    }
                }

                if (!$hasLoggedField) {
                    $fieldNamesStr = implode(' or ', $requiredFields);
                    throw ValidationException::withMessages([
                        "sets.{$index}" => ["Each set for {$exerciseType} requires either {$fieldNamesStr}."],
                    ]);
                }
            }
        }
    }

    /**
     * Extract a field value from set data, resolving field aliases.
     */
    private function extractFieldValue(array $set, string $field): mixed
    {
        if (array_key_exists($field, $set)) {
            return $set[$field];
        }

        foreach (self::FIELD_ALIAS_MAP as $alias => $targetField) {
            if ($targetField === $field && array_key_exists($alias, $set)) {
                return $set[$alias];
            }
        }

        return null;
    }
}

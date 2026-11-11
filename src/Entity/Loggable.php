<?php
namespace AdmidioPlugin\Requirements\Entity;

/**
 * What the requirements entities add to the change history of core.
 *
 * - **Decimal columns are compared as numbers.** Core's change detection compares everything but
 *   dates and booleans as strings, and a decimal column is read back from the database as "6.00"
 *   while the value the code sets is the float 6 - "6". Every save of an activity would therefore
 *   write a change of "6.00" to "6" for every credit it carries, although nothing was changed.
 * - **A member's private records are not logged.** A requirement a member tracks for themselves, its
 *   targets and the credits booked to it are nobody else's business - not even the administrators',
 *   who cannot see them anywhere else either - and the change history would name them.
 */
trait Loggable
{
    /**
     * Whether this record belongs to one member alone and so stays out of the change history.
     */
    abstract protected function isPrivateRecord(): bool;

    public function logCreation(): bool
    {
        return !$this->isPrivateRecord() && parent::logCreation();
    }

    public function logDeletion(): bool
    {
        return !$this->isPrivateRecord() && parent::logDeletion();
    }

    public function logModifications(array $logChanges): bool
    {
        return !$this->isPrivateRecord() && parent::logModifications($logChanges);
    }

    protected function valuesDiffer(string $columnName, mixed $oldValue, ?string $newValue): bool
    {
        if (in_array(strtolower((string) $this->columnsInfos[$columnName]['type']), array('decimal', 'numeric', 'float', 'double', 'real'), true)) {
            // An unset value is not a zero: "not reviewed yet" and "rejected with zero credits" differ.
            $old = ($oldValue === null || $oldValue === '') ? null : (float) $oldValue;
            $new = ($newValue === null || $newValue === '') ? null : (float) $newValue;
            return $old !== $new;
        }

        return parent::valuesDiffer($columnName, $oldValue, $newValue);
    }

    /**
     * Whether the requirement type with this id is one a member tracks for themselves.
     */
    protected function isOwnRequirementType(int $typeId): bool
    {
        if ($typeId <= 0) {
            return false;
        }
        $type = new RequirementType($this->db, $typeId);

        return (int) $type->getValue('rqt_usr_id') > 0;
    }
}

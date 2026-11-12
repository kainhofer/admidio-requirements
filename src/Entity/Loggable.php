<?php
namespace AdmidioPlugin\Requirements\Entity;

/**
 * What the requirements entities add to the change history of core.
 *
 * Decimal columns need nothing here: Entity::valuesDiffer() compares them as numbers (before that
 * was fixed in core, every save of an activity logged a change of "6.00" to "6" for each of its
 * credits, and this trait carried the comparison itself).
 *
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

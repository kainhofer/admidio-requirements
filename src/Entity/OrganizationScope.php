<?php
namespace AdmidioPlugin\Requirements\Entity;

/**
 * Restricts every read of a requirements record to the current organization.
 *
 * The services check the organization of a record they are handed, but they are not the only way in.
 * `ChangelogService::getObjectForTable()` hands its callback nothing but the table name and reads
 * the record by id or UUID afterwards, so a callback cannot filter, and the changelog never consults
 * `isVisible()`. The check therefore belongs where every path meets: `Entity::readData()`, which
 * `readDataById()`, `readDataByUuid()`, `readDataByColumns()` and the constructor all funnel into.
 *
 * A record of another organization reads as "not found" rather than as a permission error, which is
 * the same answer the caller would get for an id that does not exist - and the one that leaks least.
 */
trait OrganizationScope
{
    /**
     * The SQL condition that ties this record to an organization. Exactly one placeholder, which is
     * filled with the current organization id.
     */
    abstract protected static function organizationScopeCondition(): string;

    /**
     * @throws \Admidio\Infrastructure\Exception
     */
    protected function readData(string $sqlWhereCondition, array $queryParams = array()): bool
    {
        $organizationId = (int) ($GLOBALS['gCurrentOrgId'] ?? 0);

        // Before the organization is known there is nothing to scope to - installation and the early
        // bootstrap read this way. Every request and every CLI invocation has it by the time a
        // requirements record is touched.
        if ($sqlWhereCondition !== '' && $organizationId > 0) {
            $sqlWhereCondition .= ' AND ' . static::organizationScopeCondition() . ' ';
            $queryParams[] = $organizationId;
        }

        return parent::readData($sqlWhereCondition, $queryParams);
    }
}

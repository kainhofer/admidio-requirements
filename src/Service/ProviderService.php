<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Provider;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use Throwable;

final class ProviderService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function save(Provider $provider, array $values): Provider
    {
        global $gCurrentOrgId, $gCurrentUser;

        if (!RequirementsPlugin::isUsableByCurrentUser()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $isNew = $provider->isNewRecord();
        if (!$isNew && !$provider->isEditable()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        if ($isNew) {
            $provider->setValue('rqp_org_id', $gCurrentOrgId);
        } elseif ((int)$provider->getValue('rqp_org_id') !== (int)$gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        foreach (array('rqp_name', 'rqp_short_name', 'rqp_address', 'rqp_url', 'rqp_description') as $field) {
            if (array_key_exists($field, $values)) {
                $provider->setValue($field, is_string($values[$field]) ? trim($values[$field]) : $values[$field]);
            }
        }

        if (array_key_exists('rqp_public', $values)) {
            $provider->setValue('rqp_public', (bool)$values['rqp_public']);
        } elseif ($isNew) {
            $provider->setValue('rqp_public', true);
        }

        if (Component::isAdministrable(RequirementsPlugin::ID) && array_key_exists('rqp_qualified', $values)) {
            $provider->setValue('rqp_qualified', (bool)$values['rqp_qualified']);
        } elseif ($isNew) {
            $provider->setValue('rqp_qualified', false);
        }

        if (trim((string)$provider->getValue('rqp_name', 'database')) === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('SYS_NAME'));
        }

        $this->assertShortNameAvailable(
            $provider,
            $isNew ? (int)$gCurrentUser->getValue('usr_id') : (int)$provider->getValue('rqp_usr_id_create')
        );

        $this->db->startTransaction();
        try {
            $provider->save();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        return $provider;
    }

    /**
     * Read every provider that the current user may see in the current organization,
     * optionally narrowed to a free-text search on name and short name.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findVisible(string $query = ''): array
    {
        global $gCurrentOrgId, $gCurrentUser;

        $sql = 'SELECT rqp_id, rqp_uuid, rqp_name, rqp_short_name, rqp_url, rqp_address,
                       rqp_public, rqp_qualified, rqp_usr_id_create
                  FROM ' . RequirementsTables::providers() . '
                 WHERE rqp_org_id = ?';
        $params = array((int) $gCurrentOrgId);

        if (!Component::isAdministrable(RequirementsPlugin::ID)) {
            $sql .= ' AND (rqp_public = true OR rqp_usr_id_create = ?)';
            $params[] = (int) $gCurrentUser->getValue('usr_id');
        }
        if ($query !== '') {
            $sql .= ' AND (rqp_name LIKE ? OR rqp_short_name LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }
        $sql .= ' ORDER BY rqp_qualified DESC, rqp_name, rqp_id';

        return $this->db->getArrayFromSql($sql, $params);
    }

    /**
     * Delete a provider. Activity snapshots keep the historical display name, the FK is set to null.
     *
     * @throws Exception
     */
    public function delete(Provider $provider): void
    {
        global $gCurrentOrgId;

        if ((int) $provider->getValue('rqp_id') === 0
            || (int) $provider->getValue('rqp_org_id') !== (int) $gCurrentOrgId
            || !$provider->isEditable()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $this->db->startTransaction();
        try {
            $provider->delete();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    private function assertShortNameAvailable(Provider $provider, int $creatorId): void
    {
        $shortName = trim((string)$provider->getValue('rqp_short_name', 'database'));
        if ($shortName === '') {
            return;
        }

        $params = array(
            (int)$provider->getValue('rqp_org_id'),
            $shortName,
            (int)$provider->getValue('rqp_id')
        );

        if ((bool)$provider->getValue('rqp_public')) {
            $sql = 'SELECT COUNT(*)
                      FROM ' . RequirementsTables::providers() . '
                     WHERE rqp_org_id = ?
                       AND rqp_short_name = ?
                       AND rqp_id <> ?
                       AND rqp_public = true';
        } else {
            $sql = 'SELECT COUNT(*)
                      FROM ' . RequirementsTables::providers() . '
                     WHERE rqp_org_id = ?
                       AND rqp_short_name = ?
                       AND rqp_id <> ?
                       AND rqp_public = false
                       AND rqp_usr_id_create = ?';
            $params[] = $creatorId;
        }

        if ((int)$this->db->queryPrepared($sql, $params)->fetchColumn() > 0) {
            throw new Exception('PLG_REQUIREMENTS_PROVIDER_SHORT_NAME_DUPLICATE');
        }
    }
}

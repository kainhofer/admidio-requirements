<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * A sub-requirement ("thereof in person") is a part of its parent: same period, and its credits
 * - claimed as well as accepted - never exceed those of the parent on the same activity.
 */
final class SubRequirementTest extends RequirementsDatabaseTestCase
{
    /** @return array{0:RequirementType,1:RequirementType,2:int} parent, part, member id */
    private function pair(string $login): array
    {
        $member = $this->createMember($login);
        $parent = $this->createRequirement('CPD', $member['rol_id'], 15.0);
        $part = $this->createRequirement('InPerson', $member['rol_id'], 5.0);
        (new RequirementTypeService($this->getDatabase()))->saveType($part, array(
            'rqt_parent_id' => (int) $parent->getValue('rqt_id'),
            'rqt_period_unit' => 'quarter'
        ));
        return array($parent, $part, (int) $member['usr_id']);
    }

    private function activity(int $userId): Activity
    {
        $activity = new Activity($this->getDatabase());
        (new ActivityService($this->getDatabase()))->save(
            $activity,
            array('rqa_title' => 'Course', 'rqa_begin_date' => '2026-03-01'),
            $userId
        );
        return $activity;
    }

    private function credit(Activity $activity, RequirementType $type, float $claimed, ?float $accepted = null): void
    {
        (new ActivityService($this->getDatabase()))->saveCredit(
            new ActivityCredit($this->getDatabase()),
            $activity,
            $type,
            '2026-03-01',
            $claimed,
            $accepted
        );
    }

    public function testAPartTakesThePeriodOfItsParent(): void
    {
        [$parent, $part] = $this->pair('req_sub_period');
        $reloaded = new RequirementType($this->getDatabase(), (int) $part->getValue('rqt_id'));

        $this->assertSame('year', $reloaded->getValue('rqt_period_unit'), 'The quarter asked for is overridden.');

        (new RequirementTypeService($this->getDatabase()))->saveType($parent, array('rqt_period_unit' => 'month'));
        $reloaded = new RequirementType($this->getDatabase(), (int) $part->getValue('rqt_id'));
        $this->assertSame('month', $reloaded->getValue('rqt_period_unit'), 'A change of the parent is passed on.');
    }

    public function testOnlyOneLevelIsAllowed(): void
    {
        [$parent, $part] = $this->pair('req_sub_levels');
        $service = new RequirementTypeService($this->getDatabase());
        $other = $this->createRequirement('Other', 0 + (int) $this->createMember('req_sub_levels2')['rol_id'], 1.0);

        $this->assertRefusedWith('PLG_REQUIREMENTS_PARENT_INVALID', fn() => $service->saveType(
            $other,
            array('rqt_parent_id' => (int) $part->getValue('rqt_id'))
        ));
        $this->assertRefusedWith('PLG_REQUIREMENTS_PARENT_INVALID', fn() => $service->saveType(
            $parent,
            array('rqt_parent_id' => (int) $other->getValue('rqt_id'))
        ));
        $this->assertRefusedWith('PLG_REQUIREMENTS_PARENT_INVALID', fn() => $service->saveType(
            $other,
            array('rqt_parent_id' => (int) $other->getValue('rqt_id'))
        ));
    }

    public function testAParentWithPartsCannotBeDeleted(): void
    {
        [$parent] = $this->pair('req_sub_delete');

        $this->assertRefusedWith(
            'PLG_REQUIREMENTS_REQUIREMENT_HAS_PARTS',
            fn() => (new RequirementTypeService($this->getDatabase()))->deleteType($parent),
            array(1)
        );
    }

    public function testAPartWithinItsParentIsAccepted(): void
    {
        [$parent, $part, $userId] = $this->pair('req_sub_ok');
        $activity = $this->activity($userId);
        $service = new ActivityService($this->getDatabase());

        // 25 hours recognised, 15 of them in person - the part comes first, which is fine in a batch.
        $service->withinCreditBatch($activity, function () use ($service, $activity, $parent, $part): void {
            $service->syncCredit($activity, $part, '2026-03-01', 15.0, 15.0);
            $service->syncCredit($activity, $parent, '2026-03-01', 25.0, 25.0);
        });

        $this->assertTrue(true);
    }

    public function testAPartNeedsItsParentInTheSamePeriod(): void
    {
        [, $part, $userId] = $this->pair('req_sub_orphan');
        $activity = $this->activity($userId);

        $this->assertRefusedWith(
            'PLG_REQUIREMENTS_THEREOF_NEEDS_PARENT',
            fn() => $this->credit($activity, $part, 2.0),
            array($part->getValue('rqt_name'), $this->parentName($part))
        );
    }

    public function testAPartCannotExceedItsParent(): void
    {
        [$parent, $part, $userId] = $this->pair('req_sub_exceed');
        $activity = $this->activity($userId);
        $this->credit($activity, $parent, 10.0);

        $this->assertRefusedWith(
            'PLG_REQUIREMENTS_THEREOF_EXCEEDS',
            fn() => $this->credit($activity, $part, 12.0),
            array($part->getValue('rqt_name'), $parent->getValue('rqt_name'))
        );
    }

    public function testTheAcceptedAmountOfAPartCannotExceedTheAcceptedAmountOfItsParent(): void
    {
        [$parent, $part, $userId] = $this->pair('req_sub_accepted');
        $activity = $this->activity($userId);
        $this->credit($activity, $parent, 10.0);
        $this->credit($activity, $part, 8.0);

        $parentCreditId = (int) $this->getDatabase()->queryPrepared(
            'SELECT rac_id FROM ' . RequirementsTables::activityCredits() . ' WHERE rac_activity_id = ? AND rac_requirement_type_id = ?',
            array((int) $activity->getValue('rqa_id'), (int) $parent->getValue('rqt_id'))
        )->fetchColumn();

        // A reviewer recognises only 6 hours of the parent, below the 8 claimed for the part.
        $this->assertRefusedWith(
            'PLG_REQUIREMENTS_THEREOF_EXCEEDS',
            fn() => (new ActivityService($this->getDatabase()))->acceptCredits(
                array($parentCreditId => 6.0)
            ),
            array($part->getValue('rqt_name'), $parent->getValue('rqt_name'))
        );
    }

    private function parentName(RequirementType $part): string
    {
        return (string) (new RequirementType($this->getDatabase(), (int) $part->getValue('rqt_parent_id')))->getValue('rqt_name');
    }
}

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

    /**
     * @return array<string,mixed>
     */
    private function ownValues(string $name, ?int $parentId = null, string $anchor = 'calendar'): array
    {
        static $counter = 0;
        ++$counter;

        return array(
            'rqt_name' => $name . ' ' . $counter,
            'rqt_short_name' => $name . $counter,
            'rqt_period_unit' => 'quarter',
            'rqt_period_anchor' => $anchor,
            'rqt_parent_id' => $parentId,
            'rrr_required_credits' => 5.0,
            'rrr_partial_period_mode' => 'pro_rata',
            'rrr_proration_basis' => 'month',
            'rrr_valid_from' => '2020-01-01'
        );
    }

    private function saveOwn(int $userId, array $values): RequirementType
    {
        return $this->asMember($userId, function () use ($values): RequirementType {
            $type = new RequirementType($this->getDatabase());
            (new RequirementTypeService($this->getDatabase()))->saveOwnType($type, $values);
            return $type;
        });
    }

    private function reload(RequirementType $type): RequirementType
    {
        return new RequirementType($this->getDatabase(), (int) $type->getValue('rqt_id'));
    }

    public function testAPersonalRequirementCanBePartOfAnOrganizationRequirement(): void
    {
        $member = $this->createMember('req_sub_own_org');
        $parent = $this->createRequirement('CPD', $member['rol_id'], 15.0);

        $part = $this->saveOwn($member['usr_id'], $this->ownValues('Mine', (int) $parent->getValue('rqt_id')));

        $part = $this->reload($part);
        $this->assertSame((int) $parent->getValue('rqt_id'), (int) $part->getValue('rqt_parent_id'));
        $this->assertSame('year', $part->getValue('rqt_period_unit'), 'It takes the period of its parent, not the quarter asked for.');

        (new RequirementTypeService($this->getDatabase()))->saveType($parent, array('rqt_period_unit' => 'month'));
        $this->assertSame('month', $this->reload($part)->getValue('rqt_period_unit'), 'A change of the parent reaches the personal part.');
    }

    public function testAPersonalRequirementCanBePartOfAnotherOfTheirOwn(): void
    {
        $member = $this->createMember('req_sub_own_own');
        $parent = $this->saveOwn($member['usr_id'], $this->ownValues('Goal'));

        $part = $this->saveOwn($member['usr_id'], $this->ownValues('Thereof', (int) $parent->getValue('rqt_id')));

        $this->assertSame((int) $parent->getValue('rqt_id'), (int) $this->reload($part)->getValue('rqt_parent_id'));
    }

    public function testAPersonalRequirementCannotBePartOfAnotherMembersOrOfOneThatDoesNotApply(): void
    {
        $member = $this->createMember('req_sub_own_denied');
        $other = $this->createMember('req_sub_own_other');
        $foreign = $this->saveOwn($other['usr_id'], $this->ownValues('Theirs'));
        $notMine = $this->createRequirement('Hidden', $other['rol_id'], 10.0);

        $this->assertRefusedWith('PLG_REQUIREMENTS_PARENT_INVALID', fn() =>
            $this->saveOwn($member['usr_id'], $this->ownValues('Mine', (int) $foreign->getValue('rqt_id'))));
        $this->assertRefusedWith('PLG_REQUIREMENTS_PARENT_INVALID', fn() =>
            $this->saveOwn($member['usr_id'], $this->ownValues('Mine', (int) $notMine->getValue('rqt_id'))));
    }

    public function testAnOrganizationRequirementCannotBePartOfAPersonalOne(): void
    {
        $member = $this->createMember('req_sub_org_own');
        $own = $this->saveOwn($member['usr_id'], $this->ownValues('Goal'));
        $organization = $this->createRequirement('CPD', $member['rol_id'], 10.0);

        $this->assertRefusedWith('PLG_REQUIREMENTS_PARENT_INVALID', fn() =>
            (new RequirementTypeService($this->getDatabase()))->saveType(
                $organization,
                array('rqt_parent_id' => (int) $own->getValue('rqt_id'))
            ));
    }

    public function testOnlyRequirementsWithACalendarPeriodHaveParts(): void
    {
        $member = $this->createMember('req_sub_calendar');
        $recurring = $this->createRequirement('Licence', $member['rol_id'], 1.0, array(), array('rqt_period_anchor' => 'last_fulfilment'));
        $service = new RequirementTypeService($this->getDatabase());
        $part = $this->createRequirement('Part', $member['rol_id'], 1.0);

        $this->assertRefusedWith('PLG_REQUIREMENTS_PARENT_PERIOD_ONLY', fn() =>
            $service->saveType($part, array('rqt_parent_id' => (int) $recurring->getValue('rqt_id'))));
        $this->assertRefusedWith('PLG_REQUIREMENTS_PARENT_PERIOD_ONLY', fn() =>
            $this->saveOwn($member['usr_id'], $this->ownValues('Mine', (int) $recurring->getValue('rqt_id'))));

        [$parent, $organizationPart] = $this->pair('req_sub_calendar2');
        $this->assertRefusedWith('PLG_REQUIREMENTS_PARENT_PERIOD_ONLY', fn() =>
            $service->saveType($parent, array('rqt_period_anchor' => 'last_fulfilment')));
    }

    public function testDeletingTheParentDetachesPersonalPartsButNotTheOrganizationsOwn(): void
    {
        $member = $this->createMember('req_sub_detach');
        $parent = $this->createRequirement('CPD', $member['rol_id'], 15.0);
        $personal = $this->saveOwn($member['usr_id'], $this->ownValues('Mine', (int) $parent->getValue('rqt_id')));
        $service = new RequirementTypeService($this->getDatabase());

        $service->deleteType($parent);

        $this->assertSame(0, (int) $this->reload($personal)->getValue('rqt_parent_id'));
        $this->assertGreaterThan(0, (int) $this->reload($personal)->getValue('rqt_id'), 'The personal requirement stays.');

        [$withPart] = $this->pair('req_sub_detach2');
        $this->assertRefusedWith('PLG_REQUIREMENTS_REQUIREMENT_HAS_PARTS', fn() => $service->deleteType($withPart), array(1));
    }

    public function testAdministrationIsNotToldAboutPersonalParts(): void
    {
        $member = $this->createMember('req_sub_secret');
        $parent = $this->createRequirement('CPD', $member['rol_id'], 15.0);
        $this->saveOwn($member['usr_id'], $this->ownValues('Mine', (int) $parent->getValue('rqt_id')));

        // Neither a refusal nor a count: the personal part is simply not part of what administration sees.
        (new RequirementTypeService($this->getDatabase()))->deleteType($parent);
        $this->assertTrue(true);
    }

    public function testDeletingAPersonalParentDetachesItsParts(): void
    {
        $member = $this->createMember('req_sub_detach_own');
        $parent = $this->saveOwn($member['usr_id'], $this->ownValues('Goal'));
        $part = $this->saveOwn($member['usr_id'], $this->ownValues('Thereof', (int) $parent->getValue('rqt_id')));

        $this->asMember($member['usr_id'], fn() => (new RequirementTypeService($this->getDatabase()))->deleteOwnType($parent));

        $this->assertSame(0, (int) $this->reload($part)->getValue('rqt_parent_id'));
    }

    public function testCreditsBookedBeforeARequirementBecameAPartStay(): void
    {
        $member = $this->createMember('req_sub_legacy');
        $parent = $this->createRequirement('CPD', $member['rol_id'], 15.0);
        $part = $this->createRequirement('Part', $member['rol_id'], 5.0);
        $activity = $this->createCreditedActivity($member['usr_id'], $part, '2026-03-01', 4.0);

        (new RequirementTypeService($this->getDatabase()))->saveType($part, array('rqt_parent_id' => (int) $parent->getValue('rqt_id')));

        $this->assertSame(1, (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::activityCredits() . ' WHERE rac_activity_id = ?',
            array((int) $activity->getValue('rqa_id'))
        )->fetchColumn(), 'The credit has no parent credit, and stays.');
    }

    public function testAReviewerIsNotHeldUpByAMembersPersonalPart(): void
    {
        $member = $this->createMember('req_sub_reviewer');
        $parent = $this->createRequirement('CPD', $member['rol_id'], 15.0);
        $part = $this->saveOwn($member['usr_id'], $this->ownValues('Mine', (int) $parent->getValue('rqt_id')));
        $activity = $this->activity($member['usr_id']);
        $this->credit($activity, $parent, 10.0);
        $this->credit($activity, $part, 8.0);

        $parentCreditId = (int) $this->getDatabase()->queryPrepared(
            'SELECT rac_id FROM ' . RequirementsTables::activityCredits() . ' WHERE rac_activity_id = ? AND rac_requirement_type_id = ?',
            array((int) $activity->getValue('rqa_id'), (int) $parent->getValue('rqt_id'))
        )->fetchColumn();

        // The reviewer (the current user) accepts less than the member's personal part: no refusal that
        // would name the member's requirement.
        $this->assertSame(1, (new ActivityService($this->getDatabase()))->acceptCredits(array($parentCreditId => 6.0)));
    }
}

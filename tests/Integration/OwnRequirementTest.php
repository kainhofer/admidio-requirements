<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\ActivityCredit;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * Requirements a member defines for themselves: they are calculated like the organization's, and
 * they are nobody else's business.
 */
final class OwnRequirementTest extends RequirementsDatabaseTestCase
{
    public function testAnOwnRequirementIsCalculatedLikeAnyOther(): void
    {
        $member = $this->createMember('req_own_calc');
        $type = $this->ownRequirement($member['usr_id'], 'IDD', 15.0, '2020-01-01');
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 15.0);

        $row = $this->coverageRow($member['usr_id'], $type, '2026-06-01');

        $this->assertSame(15.0, $row['required']);
        $this->assertSame('fulfilled', $row['status']);
    }

    /**
     * The day the obligation begins plays the part a membership start plays for a role.
     */
    public function testTheYearItBeginsInIsProRated(): void
    {
        $member = $this->createMember('req_own_prorata');
        $type = $this->ownRequirement($member['usr_id'], 'POG', 12.0, '2026-07-01');

        $this->assertSame(6.0, $this->coverageRow($member['usr_id'], $type, '2026-06-01')['required']);
        $this->assertNull(
            $this->findCoverageRow($member['usr_id'], $type, '2025-06-01'),
            'Nothing is owed before the requirement begins.'
        );
    }

    public function testItAppearsInTheOwnersOverviewMarkedAsTheirOwn(): void
    {
        $member = $this->createMember('req_own_overview');
        $type = $this->ownRequirement($member['usr_id'], 'IDD', 15.0, '2015-01-01');

        $row = $this->overviewRow($member['usr_id'], $type);

        $this->assertTrue($row['own']);
        $this->assertTrue($row['hasEarlier'], 'The years since 2015 are owed, activities or not.');
    }

    public function testNobodyElseSeesIt(): void
    {
        $owner = $this->createMember('req_own_owner');
        $other = $this->createMember('req_own_other');
        $type = $this->ownRequirement($owner['usr_id'], 'IDD', 15.0, '2020-01-01');
        $typeId = (int) $type->getValue('rqt_id');
        $service = new RequirementTypeService($this->getDatabase());

        $this->assertContains($typeId, array_map('intval', array_column($service->findTypes('', $owner['usr_id']), 'rqt_id')));
        $this->assertNotContains($typeId, array_map('intval', array_column($service->findTypes('', $other['usr_id']), 'rqt_id')));
        $this->assertNotContains($typeId, array_map('intval', array_column($service->findTypes(), 'rqt_id')));
        $this->assertSame(array(), $service->findRules($typeId), 'The rule list of the administration leaves it out.');
        $this->assertNotContains($typeId, (new FulfilmentService($this->getDatabase()))->applicableRequirementTypeIds($other['usr_id']));
        $this->assertContains($typeId, (new FulfilmentService($this->getDatabase()))->applicableRequirementTypeIds($owner['usr_id']));
    }

    public function testAdministrationCannotChangeOrReviewIt(): void
    {
        $member = $this->createMember('req_own_admin');
        $type = $this->ownRequirement($member['usr_id'], 'IDD', 15.0, '2020-01-01');
        $service = new RequirementTypeService($this->getDatabase());

        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $service->saveType($type, array('rqt_name' => 'Taken over')));
        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $service->saveRule(
            new RequirementRoleRule($this->getDatabase()),
            $type,
            array('rrr_role_id' => $member['rol_id'], 'rrr_required_credits' => 1.0)
        ));
        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $service->deleteType($type));
        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() =>
            (new FulfilmentService($this->getDatabase()))->coverageForMembers((int) $type->getValue('rqt_id')));
    }

    public function testAnotherMemberCanNeitherChangeNorDeleteIt(): void
    {
        $owner = $this->createMember('req_own_keep');
        $other = $this->createMember('req_own_intruder');
        $type = $this->ownRequirement($owner['usr_id'], 'IDD', 15.0, '2020-01-01');
        $service = new RequirementTypeService($this->getDatabase());

        $this->asMember($other['usr_id'], function () use ($service, $type): void {
            $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $service->saveOwnType($type, $this->ownValues('Taken over', 1.0, '2020-01-01')));
            $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $service->deleteOwnType($type));
        });

        $this->asMember($owner['usr_id'], fn() => $service->deleteOwnType($type));
        $this->assertSame(0, (int) (new RequirementType($this->getDatabase(), (int) $type->getValue('rqt_id')))->getValue('rqt_id'));
    }

    /**
     * The credit is the way into somebody's requirement, so it is refused where every credit is
     * written - whoever writes it.
     */
    public function testItTakesNoCreditsFromSomebodyElsesActivity(): void
    {
        $owner = $this->createMember('req_own_credit');
        $other = $this->createMember('req_own_foreign');
        $type = $this->ownRequirement($owner['usr_id'], 'IDD', 15.0, '2020-01-01');
        $activity = $this->createCreditedActivity($other['usr_id'], $this->createRequirement('CPD', $other['rol_id'], 1.0), '2026-03-01', 1.0);

        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => (new ActivityService($this->getDatabase()))->saveCredit(
            new ActivityCredit($this->getDatabase()),
            $activity,
            $type,
            '2026-03-01',
            5.0
        ));
    }

    public function testTheSinceDateIsRequired(): void
    {
        $member = $this->createMember('req_own_since');

        $this->asMember($member['usr_id'], function (): void {
            $this->assertRefusedWith('SYS_FIELD_EMPTY', fn() => (new RequirementTypeService($this->getDatabase()))
                ->saveOwnType(new RequirementType($this->getDatabase()), $this->ownValues('IDD', 15.0, '')),
                array('PLG_REQUIREMENTS_OWN_SINCE'));
        });
    }

    public function testDeletingTheMemberDeletesTheirOwnRequirements(): void
    {
        global $gProfileFields;

        $member = $this->createMember('req_own_leaves');
        $type = $this->ownRequirement($member['usr_id'], 'IDD', 15.0, '2020-01-01');
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 15.0);

        (new User($this->getDatabase(), $gProfileFields, $member['usr_id']))->delete();

        $this->assertSame(0, (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::requirementTypes() . ' WHERE rqt_id = ?',
            array((int) $type->getValue('rqt_id'))
        )->fetchColumn());
    }

    /**
     * Finding of the paging fix: a member who has held a role since 2015 and never entered an
     * activity still owes those years, so the overview has to be able to show them.
     */
    public function testEarlierYearsOfARoleCanBeVisitedWithoutAnyActivity(): void
    {
        $member = $this->createMemberJoining('req_own_since2015', '2015-03-01');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $year = (int) date('Y');

        $current = $this->overviewRow($member['usr_id'], $type, 2, 0);
        $first = $this->overviewRow($member['usr_id'], $type, 2, intdiv($year - 2015, 3));
        $beyond = $this->overviewRow($member['usr_id'], $type, 2, intdiv($year - 2015, 3) + 1);

        $this->assertTrue($current['hasEarlier']);
        $this->assertContains('2015', array_column($first['periods'], 'label'));
        $this->assertSame('missing', $first['periods'][array_search('2015', array_column($first['periods'], 'label'), true)]['status']);
        $this->assertFalse($first['hasEarlier'], 'Nothing was owed before 2015.');
        $this->assertFalse($beyond['hasEarlier']);
    }

    /**
     * The IDD case: the organization books credits to a requirement no role carries, and a member
     * whose employer asks for it sets their own target.
     */
    public function testAnOwnTargetGivesAnOrganizationRequirementATargetForThatMemberOnly(): void
    {
        $member = $this->createMember('req_target_idd');
        $other = $this->createMember('req_target_none');
        $type = $this->organizationRequirementWithoutRules('IDD');
        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 10.0);
        $this->createCreditedActivity($other['usr_id'], $type, '2026-03-01', 10.0);

        $this->ownTarget($member['usr_id'], $type, 15.0, '2020-01-01');

        $row = $this->coverageRow($member['usr_id'], $type, '2026-06-01');
        $this->assertSame(15.0, $row['required']);
        $this->assertSame('missing', $row['status']);
        $this->assertSame('additional-credits', $this->coverageRow($other['usr_id'], $type, '2026-06-01')['status'],
            'The other member\'s credits still count as additional credits.');
        $this->assertContains((int) $type->getValue('rqt_id'),
            (new FulfilmentService($this->getDatabase()))->applicableRequirementTypeIds($member['usr_id']));
    }

    public function testAnOwnTargetIsCombinedWithTheRoleRulesLikeOneMoreRole(): void
    {
        $member = $this->createMember('req_target_combined');
        $type = $this->createRequirement('CPD', $member['rol_id'], 10.0, array('rrr_partial_period_mode' => 'full'));

        $this->ownTarget($member['usr_id'], $type, 25.0, '2020-01-01');

        $this->assertSame(25.0, $this->coverageRow($member['usr_id'], $type, '2026-06-01')['required'],
            'The maximum of the role rule and the own target.');
    }

    public function testTheReviewAndTheAdministrationLeaveOwnTargetsOut(): void
    {
        $member = $this->createMember('req_target_private');
        $type = $this->organizationRequirementWithoutRules('IDD');
        $target = $this->ownTarget($member['usr_id'], $type, 15.0, '2020-01-01');
        $service = new RequirementTypeService($this->getDatabase());

        $this->assertSame(array(), $service->findRules((int) $type->getValue('rqt_id')));
        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $service->saveRule($target, $type, array('rrr_required_credits' => 1.0)));
        $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $service->deleteRule($target));

        $this->createCreditedActivity($member['usr_id'], $type, '2026-03-01', 3.0);
        $review = (new FulfilmentService($this->getDatabase()))->coverageForMembers((int) $type->getValue('rqt_id'), '2026-06-01');
        $rows = array_values(array_filter(
            $review['members'],
            static fn(array $row): bool => $row['userId'] === $member['usr_id']
        ));
        $this->assertCount(1, $rows, 'The member is reviewed for the credits booked to them.');
        $this->assertNull($rows[0]['required'], 'The review asks what the organization requires, not the member.');
    }

    public function testAnotherMemberCannotChangeAnOwnTarget(): void
    {
        $owner = $this->createMember('req_target_owner');
        $other = $this->createMember('req_target_intruder');
        $type = $this->organizationRequirementWithoutRules('IDD');
        $target = $this->ownTarget($owner['usr_id'], $type, 15.0, '2020-01-01');
        $service = new RequirementTypeService($this->getDatabase());

        $this->asMember($other['usr_id'], function () use ($service, $target, $type): void {
            $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $service->saveOwnTarget($target, $type, array(
                'rrr_required_credits' => 1.0, 'rrr_valid_from' => '2020-01-01'
            )));
            $this->assertRefusedWith('SYS_NO_RIGHTS', fn() => $service->deleteOwnTarget($target));
        });

        $this->asMember($owner['usr_id'], fn() => $service->deleteOwnTarget($target));
        $this->assertSame(array(), $service->findOwnTargets($owner['usr_id']));
    }

    public function testAMemberSeesTheRulesOfTheirOwnRolesOnly(): void
    {
        $member = $this->createMember('req_target_roles');
        $other = $this->createMember('req_target_otherrole');
        $mine = $this->createRequirement('CPD', $member['rol_id'], 10.0);
        $theirs = $this->createRequirement('Ethics', $other['rol_id'], 2.0);

        $rules = (new RequirementTypeService($this->getDatabase()))->findRulesForMember($member['usr_id']);
        $types = array_map('intval', array_column($rules, 'rrr_requirement_type_id'));

        $this->assertContains((int) $mine->getValue('rqt_id'), $types);
        $this->assertNotContains((int) $theirs->getValue('rqt_id'), $types);
    }

    private function organizationRequirementWithoutRules(string $name): RequirementType
    {
        static $counter = 0;
        ++$counter;

        $type = new RequirementType($this->getDatabase());
        (new RequirementTypeService($this->getDatabase()))->saveType($type, array(
            'rqt_name' => $name . ' ' . $counter,
            'rqt_short_name' => $name . 'ORG' . $counter,
            'rqt_period_unit' => 'year',
            'rqt_period_anchor' => 'calendar'
        ));
        return $type;
    }

    private function ownTarget(int $userId, RequirementType $type, float $credits, string $since): RequirementRoleRule
    {
        return $this->asMember($userId, function () use ($type, $credits, $since): RequirementRoleRule {
            $rule = new RequirementRoleRule($this->getDatabase());
            (new RequirementTypeService($this->getDatabase()))->saveOwnTarget($rule, $type, array(
                'rrr_required_credits' => $credits,
                'rrr_partial_period_mode' => 'pro_rata',
                'rrr_valid_from' => $since
            ));
            return $rule;
        });
    }

    private function ownRequirement(int $userId, string $name, float $credits, string $since): RequirementType
    {
        return $this->asMember($userId, function () use ($name, $credits, $since): RequirementType {
            $type = new RequirementType($this->getDatabase());
            (new RequirementTypeService($this->getDatabase()))->saveOwnType($type, $this->ownValues($name, $credits, $since));
            return $type;
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function ownValues(string $name, float $credits, string $since): array
    {
        static $counter = 0;
        ++$counter;

        return array(
            'rqt_name' => $name . ' ' . $counter,
            'rqt_short_name' => $name . $counter,
            'rqt_period_unit' => 'year',
            'rqt_period_anchor' => 'calendar',
            'rrr_required_credits' => $credits,
            'rrr_partial_period_mode' => 'pro_rata',
            'rrr_proration_basis' => 'month',
            'rrr_valid_from' => $since
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function coverageRow(int $userId, RequirementType $type, string $reference): array
    {
        $row = $this->findCoverageRow($userId, $type, $reference);
        if ($row === null) {
            $this->fail('No coverage row for requirement ' . $type->getValue('rqt_name') . '.');
        }
        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findCoverageRow(int $userId, RequirementType $type, string $reference): ?array
    {
        $rows = $this->asMember($userId, fn(): array =>
            (new FulfilmentService($this->getDatabase()))->coverageForUser($userId, $reference));
        foreach ($rows as $row) {
            if ($row['typeId'] === (int) $type->getValue('rqt_id')) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function overviewRow(int $userId, RequirementType $type, int $previous = 4, int $offset = 0): array
    {
        $rows = $this->asMember($userId, fn(): array =>
            (new FulfilmentService($this->getDatabase()))->coverageHistoryForUser($userId, $previous, $offset));
        foreach ($rows as $row) {
            if ($row['typeId'] === (int) $type->getValue('rqt_id')) {
                return $row;
            }
        }
        $this->fail('No overview row for requirement ' . $type->getValue('rqt_name') . '.');
    }
}

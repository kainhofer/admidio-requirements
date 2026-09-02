<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use Admidio\Infrastructure\Exception;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\Provider;
use AdmidioPlugin\Requirements\Entity\RequirementRoleRule;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\ProviderService;
use AdmidioPlugin\Requirements\Service\RequirementTypeService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The save services against a real database: organisation isolation (§7.6), provider rules (§6.7),
 * the owner-edit policy (§6.5) and rule validity (§6.2).
 */
final class RequirementsServiceTest extends RequirementsDatabaseTestCase
{
    // ---------------------------------------------------------------- providers (§6.7, §7.1)

    public function testAProviderIsCreatedInTheCurrentOrganisation(): void
    {
        global $gCurrentOrgId;

        $provider = new Provider($this->getDatabase());
        (new ProviderService($this->getDatabase()))->save($provider, array(
            'rqp_name' => 'Institute of Everything',
            'rqp_short_name' => 'IOE'
        ));

        $this->assertGreaterThan(0, (int) $provider->getValue('rqp_id'));
        $this->assertSame((int) $gCurrentOrgId, (int) $provider->getValue('rqp_org_id'));
        $this->assertValidUuid($provider->getValue('rqp_uuid'));
    }

    public function testAProviderNeedsAName(): void
    {
        $this->expectException(Exception::class);

        (new ProviderService($this->getDatabase()))->save(new Provider($this->getDatabase()), array(
            'rqp_name' => '   '
        ));
    }

    /**
     * §7.1: two public providers of one organisation may not share a short name.
     */
    public function testTwoPublicProvidersCannotShareAShortName(): void
    {
        $service = new ProviderService($this->getDatabase());
        $service->save(new Provider($this->getDatabase()), array(
            'rqp_name' => 'First', 'rqp_short_name' => 'DUP', 'rqp_public' => true
        ));

        $this->assertRefusedWith('PLG_REQUIREMENTS_PROVIDER_SHORT_NAME_DUPLICATE', function () use ($service): void {
            $service->save(new Provider($this->getDatabase()), array(
                'rqp_name' => 'Second', 'rqp_short_name' => 'DUP', 'rqp_public' => true
            ));
        });
    }

    /**
     * A private provider is only compared against the other private providers of its own creator,
     * so a short name that is taken publicly is still free privately.
     */
    public function testAPrivateProviderMayReuseAPublicShortName(): void
    {
        $service = new ProviderService($this->getDatabase());
        $service->save(new Provider($this->getDatabase()), array(
            'rqp_name' => 'Public one', 'rqp_short_name' => 'SHARED', 'rqp_public' => true
        ));

        $private = new Provider($this->getDatabase());
        $service->save($private, array(
            'rqp_name' => 'Private one', 'rqp_short_name' => 'SHARED', 'rqp_public' => false
        ));

        $this->assertGreaterThan(0, (int) $private->getValue('rqp_id'));
    }

    public function testAnEmptyShortNameNeverCollides(): void
    {
        $service = new ProviderService($this->getDatabase());
        $service->save(new Provider($this->getDatabase()), array('rqp_name' => 'One', 'rqp_short_name' => ''));

        $second = new Provider($this->getDatabase());
        $service->save($second, array('rqp_name' => 'Two', 'rqp_short_name' => ''));

        $this->assertGreaterThan(0, (int) $second->getValue('rqp_id'));
    }

    /**
     * §6.7: only administrators may mark a provider as recognised.
     */
    public function testANormalMemberCannotQualifyAProvider(): void
    {
        $member = $this->createMember('req_member_qualify');

        $this->asMember($member['usr_id'], function (): void {
            $provider = new Provider($this->getDatabase());
            (new ProviderService($this->getDatabase()))->save($provider, array(
                'rqp_name' => 'Self-declared', 'rqp_qualified' => true
            ));

            $this->assertFalse((bool) $provider->getValue('rqp_qualified'));
        });
    }

    // ---------------------------------------------------------------- organisation isolation (§7.6)

    /**
     * §3.4 of the concept's defect list, and §7.6: a provider of another organisation may not be
     * attached to an activity even when its id is submitted directly.
     */
    public function testAProviderOfAnotherOrganisationCannotBeAttached(): void
    {
        $foreignProviderId = $this->insertForeignProvider();

        $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($foreignProviderId): void {
            (new ActivityService($this->getDatabase()))->save(new Activity($this->getDatabase()), array(
                'rqa_title' => 'Course with a foreign provider',
                'rqa_begin_date' => '2026-03-01',
                'rqa_provider_id' => $foreignProviderId
            ));
        });
    }

    /**
     * The entity itself refuses to read a record of another organisation, which is what protects
     * the changelog callbacks that cannot filter on their own (finding 2.4).
     */
    public function testAnEntityRefusesToLoadAForeignRecord(): void
    {
        $foreignProviderId = $this->insertForeignProvider();

        $provider = new Provider($this->getDatabase(), $foreignProviderId);

        $this->assertSame(0, (int) $provider->getValue('rqp_id'), 'A foreign record must read as absent.');
    }

    public function testAForeignRecordIsAlsoInvisibleByUuid(): void
    {
        $this->insertForeignProvider('99999999-9999-9999-9999-999999999999');

        $provider = new Provider($this->getDatabase());
        $provider->readDataByUuid('99999999-9999-9999-9999-999999999999');

        $this->assertSame(0, (int) $provider->getValue('rqp_id'));
    }

    /**
     * A rule and a credit carry no organisation of their own, so they are scoped through their
     * requirement type and their activity.
     */
    public function testAForeignRuleIsInvisibleThroughItsRequirementType(): void
    {
        $db = $this->getDatabase();
        $otherOrgId = $this->createOtherOrganisation();

        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::requirementTypes()
            . ' (rqt_uuid, rqt_org_id, rqt_name, rqt_period_unit) VALUES (?, ?, ?, ?)',
            array('aaaaaaaa-0000-0000-0000-000000000001', $otherOrgId, 'Foreign CPD', 'year')
        );
        $foreignTypeId = (int) $db->queryPrepared(
            'SELECT rqt_id FROM ' . RequirementsTables::requirementTypes() . ' WHERE rqt_uuid = ?',
            array('aaaaaaaa-0000-0000-0000-000000000001')
        )->fetchColumn();

        $role = $this->fixture->createAndSaveRole('Foreign rule role', $otherOrgId);
        $db->queryPrepared(
            'INSERT INTO ' . RequirementsTables::requirementRoleRules()
            . ' (rrr_uuid, rrr_requirement_type_id, rrr_role_id, rrr_required_credits) VALUES (?, ?, ?, ?)',
            array('aaaaaaaa-0000-0000-0000-000000000002', $foreignTypeId, (int) $role['rol_id'], '10.00')
        );
        $foreignRuleId = (int) $db->queryPrepared(
            'SELECT rrr_id FROM ' . RequirementsTables::requirementRoleRules() . ' WHERE rrr_uuid = ?',
            array('aaaaaaaa-0000-0000-0000-000000000002')
        )->fetchColumn();

        $this->assertSame(0, (int) (new RequirementRoleRule($db, $foreignRuleId))->getValue('rrr_id'));
        $this->assertSame(0, (int) (new RequirementType($db, $foreignTypeId))->getValue('rqt_id'));
    }

    // ---------------------------------------------------------------- activities (§6.5)

    public function testAnActivityNeedsATitle(): void
    {
        $this->assertRefusedWith(
            'SYS_FIELD_EMPTY',
            function (): void {
                (new ActivityService($this->getDatabase()))->save(new Activity($this->getDatabase()), array(
                    'rqa_title' => '', 'rqa_begin_date' => '2026-03-01'
                ));
            },
            array('SYS_TITLE')
        );
    }

    /**
     * Entity::setValue() turns an empty string into 1970-01-01 on a NOT NULL date column, so an
     * activity with no begin date would otherwise be saved dated to the epoch - and would then be
     * credited to the wrong period by default. The value has to be refused as it arrives.
     */
    public function testAnEmptyBeginDateIsRefusedRatherThanTurnedIntoTheEpoch(): void
    {
        $this->assertRefusedWith(
            'SYS_FIELD_EMPTY',
            function (): void {
                (new ActivityService($this->getDatabase()))->save(new Activity($this->getDatabase()), array(
                    'rqa_title' => 'No date', 'rqa_begin_date' => ''
                ));
            },
            array('PLG_REQUIREMENTS_BEGIN_DATE')
        );

        $this->assertSame(
            0,
            (int) $this->getDatabase()->queryPrepared(
                'SELECT COUNT(*) FROM ' . RequirementsTables::activities() . ' WHERE rqa_begin_date = ?',
                array('1970-01-01')
            )->fetchColumn(),
            'Nothing should have been written with an epoch date.'
        );
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function malformedDates(): array
    {
        return array(
            'a day that does not exist' => array('rqa_begin_date', '2026-02-30'),
            'a localised format' => array('rqa_begin_date', '01.03.2026'),
            'text' => array('rqa_begin_date', 'tomorrow'),
            'an end date that is not a date' => array('rqa_end_date', '2026-13-01'),
            'a certificate date that is not a date' => array('rqa_certificate_date', 'soon')
        );
    }

    /**
     * @dataProvider malformedDates
     */
    public function testAMalformedDateIsRefused(string $field, string $value): void
    {
        $this->expectException(Exception::class);

        (new ActivityService($this->getDatabase()))->save(new Activity($this->getDatabase()), array(
            'rqa_title' => 'Bad date',
            'rqa_begin_date' => '2026-03-01',
            $field => $value
        ));
    }

    /**
     * The time columns take whatever string the entity is given, so they need the same treatment
     * as the dates (finding 3.14).
     *
     * @return array<string,array{0:string}>
     */
    public static function malformedTimes(): array
    {
        return array(
            'text' => array('not a time'),
            'hour 24' => array('24:00'),
            'minute 60' => array('10:60'),
            'no minutes' => array('10'),
            'a date' => array('2026-03-01')
        );
    }

    /**
     * @dataProvider malformedTimes
     */
    public function testAMalformedTimeIsRefused(string $value): void
    {
        $this->expectException(Exception::class);

        (new ActivityService($this->getDatabase()))->save(new Activity($this->getDatabase()), array(
            'rqa_title' => 'Bad time',
            'rqa_begin_date' => '2026-03-01',
            'rqa_begin_time' => $value
        ));
    }

    public function testAValidTimeIsStoredWithSeconds(): void
    {
        $activity = new Activity($this->getDatabase());
        (new ActivityService($this->getDatabase()))->save($activity, array(
            'rqa_title' => 'Timed',
            'rqa_begin_date' => '2026-03-01',
            'rqa_begin_time' => '09:30'
        ));

        $this->assertSame('09:30:00', (string) $activity->getValue('rqa_begin_time', 'database'));
    }

    public function testAnEmptyTimeMeansAFullDay(): void
    {
        $activity = new Activity($this->getDatabase());
        (new ActivityService($this->getDatabase()))->save($activity, array(
            'rqa_title' => 'All day',
            'rqa_begin_date' => '2026-03-01',
            'rqa_begin_time' => '',
            'rqa_end_time' => ''
        ));

        $this->assertSame('', (string) $activity->getValue('rqa_begin_time', 'database'));
        $this->assertSame('', (string) $activity->getValue('rqa_end_time', 'database'));
    }

    public function testWithinOneDayTheEndTimeMayNotPrecedeTheBeginTime(): void
    {
        $this->assertRefusedWith('PLG_REQUIREMENTS_INVALID_ACTIVITY_PERIOD', function (): void {
            (new ActivityService($this->getDatabase()))->save(new Activity($this->getDatabase()), array(
                'rqa_title' => 'Backwards in a day',
                'rqa_begin_date' => '2026-03-01',
                'rqa_end_date' => '2026-03-01',
                'rqa_begin_time' => '14:00',
                'rqa_end_time' => '09:00'
            ));
        });
    }

    public function testAcrossDaysTheTimesDoNotDecideTheOrder(): void
    {
        $activity = new Activity($this->getDatabase());
        (new ActivityService($this->getDatabase()))->save($activity, array(
            'rqa_title' => 'Overnight',
            'rqa_begin_date' => '2026-03-01',
            'rqa_end_date' => '2026-03-02',
            'rqa_begin_time' => '20:00',
            'rqa_end_time' => '06:00'
        ));

        $this->assertGreaterThan(0, (int) $activity->getValue('rqa_id'));
    }

    public function testTheEndDateMayNotPrecedeTheBeginDate(): void
    {
        $this->assertRefusedWith('PLG_REQUIREMENTS_INVALID_ACTIVITY_PERIOD', function (): void {
            (new ActivityService($this->getDatabase()))->save(new Activity($this->getDatabase()), array(
                'rqa_title' => 'Backwards',
                'rqa_begin_date' => '2026-03-10',
                'rqa_end_date' => '2026-03-01'
            ));
        });
    }

    /**
     * §6.5: under `always` a new activity is submitted straight away, because there is no state for
     * a submission step to change.
     */
    public function testUnderTheAlwaysPolicyANewActivityIsSubmitted(): void
    {
        $activity = new Activity($this->getDatabase());
        (new ActivityService($this->getDatabase()))->save($activity, array(
            'rqa_title' => 'Straight in', 'rqa_begin_date' => '2026-03-01'
        ));

        $this->assertSame('submitted', (string) $activity->getValue('rqa_status'));
    }

    public function testUnderTheUntilSubmittedPolicyANewActivityIsADraft(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY => RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
        ));

        $activity = new Activity($this->getDatabase());
        (new ActivityService($this->getDatabase()))->save($activity, array(
            'rqa_title' => 'Needs submitting', 'rqa_begin_date' => '2026-03-01'
        ));

        $this->assertSame('draft', (string) $activity->getValue('rqa_status'));
    }

    /**
     * §6.5 and finding 2.1: submitting is what moves a draft on, and it may only happen once.
     */
    public function testSubmittingMovesADraftOnAndOnlyOnce(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY => RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
        ));

        $service = new ActivityService($this->getDatabase());
        $activity = new Activity($this->getDatabase());
        $service->save($activity, array('rqa_title' => 'To submit', 'rqa_begin_date' => '2026-03-01'));

        $service->submit($activity);
        $this->assertSame('submitted', (string) $activity->getValue('rqa_status'));

        $this->expectException(Exception::class);
        $service->submit($activity);
    }

    /**
     * §6.5: under `until_submitted` a member loses the activity once it is submitted, while an
     * administrator keeps it in both settings.
     */
    public function testTheOwnerEditPolicyLocksASubmittedActivityForItsOwner(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY => RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
        ));
        $member = $this->createMember('req_member_policy');

        $activityId = $this->asMember($member['usr_id'], function (): int {
            $activity = new Activity($this->getDatabase());
            $service = new ActivityService($this->getDatabase());
            $service->save($activity, array('rqa_title' => 'Mine', 'rqa_begin_date' => '2026-03-01'));

            $this->assertTrue($activity->isEditable(), 'A draft belongs to its owner.');
            $service->submit($activity);

            return (int) $activity->getValue('rqa_id');
        });

        $this->asMember($member['usr_id'], function () use ($activityId): void {
            $activity = new Activity($this->getDatabase(), $activityId);

            $this->assertTrue($activity->isVisible(), 'The owner still sees their own activity.');
            $this->assertFalse($activity->isEditable(), 'Submitting hands it over.');
        });

        // The administrator context of the test case is restored outside the closures.
        $this->assertTrue((new Activity($this->getDatabase(), $activityId))->isEditable());
    }

    public function testUnderTheAlwaysPolicyTheOwnerKeepsASubmittedActivity(): void
    {
        $member = $this->createMember('req_member_always');

        $this->asMember($member['usr_id'], function (): void {
            $activity = new Activity($this->getDatabase());
            (new ActivityService($this->getDatabase()))->save($activity, array(
                'rqa_title' => 'Still mine', 'rqa_begin_date' => '2026-03-01'
            ));

            $this->assertSame('submitted', (string) $activity->getValue('rqa_status'));
            $this->assertTrue($activity->isEditable());
            $this->assertTrue($activity->isDeletable());
        });
    }

    public function testAMemberCannotSeeAnotherMembersActivity(): void
    {
        $owner = $this->createMember('req_member_owner');
        $other = $this->createMember('req_member_other');

        $activityId = $this->asMember($owner['usr_id'], function (): int {
            $activity = new Activity($this->getDatabase());
            (new ActivityService($this->getDatabase()))->save($activity, array(
                'rqa_title' => 'Private', 'rqa_begin_date' => '2026-03-01'
            ));
            return (int) $activity->getValue('rqa_id');
        });

        $this->asMember($other['usr_id'], function () use ($activityId): void {
            $activity = new Activity($this->getDatabase(), $activityId);

            $this->assertFalse($activity->isVisible());
            $this->assertFalse($activity->isEditable());
        });
    }

    public function testAMemberCannotCreateAnActivityForSomebodyElse(): void
    {
        $member = $this->createMember('req_member_impersonate');
        $victim = $this->createMember('req_member_victim');

        $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($member, $victim): void {
            $this->asMember($member['usr_id'], function () use ($victim): void {
                (new ActivityService($this->getDatabase()))->save(
                    new Activity($this->getDatabase()),
                    array('rqa_title' => 'Not mine to enter', 'rqa_begin_date' => '2026-03-01'),
                    $victim['usr_id']
                );
            });
        });
    }

    // ---------------------------------------------------------------- requirement types and rules

    public function testACalendarRequirementWithACountAboveOneIsRefused(): void
    {
        $this->assertRefusedWith('PLG_REQUIREMENTS_CALENDAR_MULTI_PERIOD_UNSUPPORTED', function (): void {
            (new RequirementTypeService($this->getDatabase()))->saveType(
                new RequirementType($this->getDatabase()),
                array(
                    'rqt_name' => 'Every two years',
                    'rqt_period_unit' => 'year',
                    'rqt_period_count' => 2,
                    'rqt_period_anchor' => 'calendar'
                )
            );
        });
    }

    public function testTwoRequirementTypesCannotShareAShortName(): void
    {
        $service = new RequirementTypeService($this->getDatabase());
        $service->saveType(new RequirementType($this->getDatabase()), array(
            'rqt_name' => 'First', 'rqt_short_name' => 'CPD', 'rqt_period_unit' => 'year'
        ));

        $this->assertRefusedWith('PLG_REQUIREMENTS_REQUIREMENT_SHORT_NAME_DUPLICATE', function () use ($service): void {
            $service->saveType(new RequirementType($this->getDatabase()), array(
                'rqt_name' => 'Second', 'rqt_short_name' => 'CPD', 'rqt_period_unit' => 'year'
            ));
        });
    }

    /**
     * §6.2: two simultaneously valid rows for one role and requirement are configuration ambiguity.
     */
    public function testOverlappingRuleValidityIsRefused(): void
    {
        global $gCurrentOrgId;

        $service = new RequirementTypeService($this->getDatabase());
        $type = new RequirementType($this->getDatabase());
        $service->saveType($type, array('rqt_name' => 'Overlap', 'rqt_period_unit' => 'year'));
        $role = $this->fixture->createAndSaveRole('Overlap role', (int) $gCurrentOrgId);

        $service->saveRule(new RequirementRoleRule($this->getDatabase()), $type, array(
            'rrr_role_id' => (int) $role['rol_id'],
            'rrr_required_credits' => 10.0,
            'rrr_valid_from' => '2026-01-01',
            'rrr_valid_until' => '2026-06-30'
        ));

        $this->assertRefusedWith('PLG_REQUIREMENTS_RULE_VALIDITY_OVERLAP', function () use ($service, $type, $role): void {
            $service->saveRule(new RequirementRoleRule($this->getDatabase()), $type, array(
                'rrr_role_id' => (int) $role['rol_id'],
                'rrr_required_credits' => 20.0,
                'rrr_valid_from' => '2026-06-01',
                'rrr_valid_until' => '2026-12-31'
            ));
        });
    }

    public function testAdjacentRuleValidityIsAccepted(): void
    {
        global $gCurrentOrgId;

        $service = new RequirementTypeService($this->getDatabase());
        $type = new RequirementType($this->getDatabase());
        $service->saveType($type, array('rqt_name' => 'Adjacent', 'rqt_period_unit' => 'year'));
        $role = $this->fixture->createAndSaveRole('Adjacent role', (int) $gCurrentOrgId);

        $service->saveRule(new RequirementRoleRule($this->getDatabase()), $type, array(
            'rrr_role_id' => (int) $role['rol_id'],
            'rrr_required_credits' => 10.0,
            'rrr_valid_until' => '2026-06-30'
        ));

        $second = new RequirementRoleRule($this->getDatabase());
        $service->saveRule($second, $type, array(
            'rrr_role_id' => (int) $role['rol_id'],
            'rrr_required_credits' => 20.0,
            'rrr_valid_from' => '2026-07-01'
        ));

        $this->assertGreaterThan(0, (int) $second->getValue('rrr_id'));
    }

    /**
     * §6.4: the plugin setting only preselects a value; the rule stores its own.
     */
    public function testANewRuleTakesItsProrationBasisFromTheSetting(): void
    {
        global $gCurrentOrgId;

        $this->enablePluginForCurrentOrganization(array(
            RequirementsPlugin::SETTING_PRORATION_BASIS_DEFAULT => 'day'
        ));

        $service = new RequirementTypeService($this->getDatabase());
        $type = new RequirementType($this->getDatabase());
        $service->saveType($type, array('rqt_name' => 'Basis', 'rqt_period_unit' => 'year'));
        $role = $this->fixture->createAndSaveRole('Basis role', (int) $gCurrentOrgId);

        $rule = new RequirementRoleRule($this->getDatabase());
        $service->saveRule($rule, $type, array(
            'rrr_role_id' => (int) $role['rol_id'],
            'rrr_required_credits' => 10.0
        ));

        $this->assertSame('day', (string) $rule->getValue('rrr_proration_basis'));

        // Changing the setting afterwards must not reinterpret the stored rule.
        $this->enablePluginForCurrentOrganization(array(
            RequirementsPlugin::SETTING_PRORATION_BASIS_DEFAULT => 'week'
        ));
        $reloaded = new RequirementRoleRule($this->getDatabase(), (int) $rule->getValue('rrr_id'));
        $this->assertSame('day', (string) $reloaded->getValue('rrr_proration_basis'));
    }

    public function testARoleOfAnotherOrganisationCannotCarryARule(): void
    {
        $otherOrgId = $this->createOtherOrganisation();
        $foreignRole = $this->fixture->createAndSaveRole('Foreign role', $otherOrgId);

        $service = new RequirementTypeService($this->getDatabase());
        $type = new RequirementType($this->getDatabase());
        $service->saveType($type, array('rqt_name' => 'Cross org', 'rqt_period_unit' => 'year'));

        // The Role entity already refuses to hand over a role of another organisation, so the
        // rejection arrives before saveRule() gets to its own check. Either way the rule is not
        // written, which is what matters here - the message is the core entity's to choose.
        try {
            $service->saveRule(new RequirementRoleRule($this->getDatabase()), $type, array(
                'rrr_role_id' => (int) $foreignRole['rol_id'],
                'rrr_required_credits' => 10.0
            ));
            $this->fail('A role of another organisation should not be able to carry a rule.');
        } catch (Exception) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::requirementRoleRules() . ' WHERE rrr_role_id = ?',
            array((int) $foreignRole['rol_id'])
        )->fetchColumn());
    }

    public function testANormalMemberCannotAdministerRequirementTypes(): void
    {
        $member = $this->createMember('req_member_types');

        $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($member): void {
            $this->asMember($member['usr_id'], function (): void {
                (new RequirementTypeService($this->getDatabase()))->saveType(
                    new RequirementType($this->getDatabase()),
                    array('rqt_name' => 'Mine', 'rqt_period_unit' => 'year')
                );
            });
        });
    }

    // ---------------------------------------------------------------- helpers

    private function createOtherOrganisation(): int
    {
        static $counter = 0;
        ++$counter;

        return (int) $this->fixture->createAndSaveOrganization(
            'Other Organisation ' . $counter,
            'OTHR' . $counter
        )['org_id'];
    }

    private function insertForeignProvider(string $uuid = 'bbbbbbbb-0000-0000-0000-000000000001'): int
    {
        $otherOrgId = $this->createOtherOrganisation();

        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . RequirementsTables::providers()
            . ' (rqp_uuid, rqp_org_id, rqp_name, rqp_public) VALUES (?, ?, ?, ?)',
            array($uuid, $otherOrgId, 'Foreign Provider', true)
        );

        return (int) $this->getDatabase()->queryPrepared(
            'SELECT rqp_id FROM ' . RequirementsTables::providers() . ' WHERE rqp_uuid = ?',
            array($uuid)
        )->fetchColumn();
    }
}

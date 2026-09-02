<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Tests\Unit\Support\RequirementsUnitTestCase;
use DateTimeImmutable;

require_once __DIR__ . '/Support/RequirementsUnitTestCase.php';

/**
 * The obligation half of the calculator: concept §6.2 (several rules for one role), §6.3 (the
 * period-start gate and partial membership), §6.4 (proration) and §8.1 step 8 (several roles).
 *
 * Everything here runs against a calendar year, because the policy is the same for every unit and a
 * year is the one whose numbers are easiest to check by hand.
 */
final class FulfilmentCalculationTest extends RequirementsUnitTestCase
{
    private const YEAR_START = '2026-01-01';
    private const YEAR_END = '2026-12-31';

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<int,array<int,array{start:string,end:string}>> $memberships
     * @return array{0:float,1:array<int,string>,2:bool}
     */
    private static function calculate(array $rules, array $memberships, string $mode = 'maximum'): array
    {
        return FulfilmentService::calculateRequiredCredits(
            $mode,
            $rules,
            $memberships,
            new DateTimeImmutable(self::YEAR_START),
            new DateTimeImmutable(self::YEAR_END)
        );
    }

    /** The whole year, for a member who was there throughout. */
    private static function wholeYear(): array
    {
        return self::memberships(array(array('2026-01-01', '2026-12-31')));
    }

    public function testAFullYearMemberOwesTheWholeAmount(): void
    {
        [$required, $warnings, $hasRule] = self::calculate(
            array(self::rule(1, 15.0)),
            array(1 => self::wholeYear())
        );

        $this->assertSame(15.0, $required);
        $this->assertSame(array(), $warnings);
        $this->assertTrue($hasRule);
    }

    public function testARoleTheMemberDoesNotHoldCreatesNoObligation(): void
    {
        [$required, , $hasRule] = self::calculate(array(self::rule(1, 15.0)), array());

        $this->assertSame(0.0, $required);
        $this->assertFalse($hasRule, 'no rule applied, so a zero here means "not applicable"');
    }

    /**
     * A zero-credit rule is a real configuration - "be enrolled, collect nothing". It has to be
     * distinguishable from no rule at all, which is what the third return value is for (finding 3.5).
     */
    public function testAZeroCreditRuleStillCountsAsAnAppliedRule(): void
    {
        [$required, , $hasRule] = self::calculate(
            array(self::rule(1, 0.0)),
            array(1 => self::wholeYear())
        );

        $this->assertSame(0.0, $required);
        $this->assertTrue($hasRule);
    }

    // ---------------------------------------------------------------- period-start gate (§6.3)

    public function testThePeriodStartGateBlocksAMemberWhoJoinedLater(): void
    {
        [$required, , $hasRule] = self::calculate(
            array(self::rule(1, 15.0, array('rrr_membership_at_period_start' => true))),
            array(1 => self::memberships(array(array('2026-04-01', '2026-12-31'))))
        );

        $this->assertSame(0.0, $required);
        $this->assertFalse($hasRule);
    }

    public function testThePeriodStartGatePassesForAMemberPresentOnTheFirstDay(): void
    {
        [$required] = self::calculate(
            array(self::rule(1, 15.0, array(
                'rrr_membership_at_period_start' => true,
                'rrr_partial_period_mode' => 'full'
            ))),
            array(1 => self::memberships(array(array('2025-06-01', '2026-06-30'))))
        );

        $this->assertSame(15.0, $required);
    }

    /**
     * §6.3: the gate and the partial mode are independent. Having passed the gate, a member who
     * leaves half way still only owes the part they were there for.
     */
    public function testPassingTheGateDoesNotMakeTheObligationFull(): void
    {
        [$required] = self::calculate(
            array(self::rule(1, 12.0, array('rrr_membership_at_period_start' => true))),
            array(1 => self::memberships(array(array('2026-01-01', '2026-06-30'))))
        );

        $this->assertSame(6.0, $required, 'six of twelve month-starts are inside the membership');
    }

    public function testWithoutTheGateALateJoinerStillOwesAProRataShare(): void
    {
        [$required] = self::calculate(
            array(self::rule(1, 12.0)),
            array(1 => self::memberships(array(array('2026-10-01', '2026-12-31'))))
        );

        $this->assertSame(3.0, $required);
    }

    public function testLeavingAndRejoiningCountsBothIntervals(): void
    {
        [$required] = self::calculate(
            array(self::rule(1, 12.0)),
            array(1 => self::memberships(array(
                array('2026-01-01', '2026-03-31'),
                array('2026-10-01', '2026-12-31')
            )))
        );

        $this->assertSame(6.0, $required, 'January to March plus October to December');
    }

    // ---------------------------------------------------------------- proration basis (§6.4)

    /**
     * @return array<string,array{0:string,1:float}>
     */
    public static function prorationBases(): array
    {
        // 1 January to 30 June. Month: 6 of 12 unit starts. Day: 181 of 365. Week: 2026-01-01 is
        // itself a unit start (§6.4: the first unit starts on the period boundary), plus the 26
        // Mondays from 5 January to 29 June, of 53 unit starts in the year.
        return array(
            'month' => array('month', 6.0),
            'day' => array('day', 181 / 365 * 12),
            'week' => array('week', 27 / 53 * 12)
        );
    }

    /**
     * @dataProvider prorationBases
     */
    public function testTheProrationBasisDecidesTheFraction(string $basis, float $expected): void
    {
        [$required] = self::calculate(
            array(self::rule(1, 12.0, array('rrr_proration_basis' => $basis))),
            array(1 => self::memberships(array(array('2026-01-01', '2026-06-30'))))
        );

        $this->assertEqualsWithDelta($expected, $required, 0.0001);
    }

    public function testFullModeIgnoresPartialMembership(): void
    {
        [$required] = self::calculate(
            array(self::rule(1, 12.0, array('rrr_partial_period_mode' => 'full'))),
            array(1 => self::memberships(array(array('2026-11-01', '2026-11-30'))))
        );

        $this->assertSame(12.0, $required);
    }

    // ---------------------------------------------------------------- rule validity (REQ-DEC-023)

    /**
     * REQ-DEC-023: the denominator is the whole requirement period, not the validity window. A rule
     * valid for half the year obligates at most half its value, even for a member present all year.
     */
    public function testValidityClipsTheObligationOfAFullYearMember(): void
    {
        [$required] = self::calculate(
            array(self::rule(1, 12.0, array('rrr_valid_until' => '2026-06-30'))),
            array(1 => self::wholeYear())
        );

        $this->assertSame(6.0, $required);
    }

    /**
     * §6.2: adjacent rows are how a value that changed mid-year is expressed, so their fractions add
     * up to one year rather than to two.
     */
    public function testTwoAdjacentHalfYearRulesAddUpToOneYear(): void
    {
        [$required, $warnings] = self::calculate(
            array(
                self::rule(1, 12.0, array('rrr_valid_until' => '2026-06-30')),
                self::rule(1, 12.0, array('rrr_valid_from' => '2026-07-01'))
            ),
            array(1 => self::wholeYear())
        );

        $this->assertSame(12.0, $required);
        $this->assertSame(array(), $warnings, 'adjacent pro-rata rows are normal, not a misconfiguration');
    }

    public function testAValueThatChangedMidYearIsWeightedByItsHalf(): void
    {
        [$required] = self::calculate(
            array(
                self::rule(1, 12.0, array('rrr_valid_until' => '2026-06-30')),
                self::rule(1, 24.0, array('rrr_valid_from' => '2026-07-01'))
            ),
            array(1 => self::wholeYear())
        );

        $this->assertSame(18.0, $required, 'six months of 12 plus six months of 24');
    }

    public function testARuleValidOutsideThePeriodDoesNotApply(): void
    {
        [$required, , $hasRule] = self::calculate(
            array(self::rule(1, 12.0, array(
                'rrr_valid_from' => '2020-01-01',
                'rrr_valid_until' => '2020-12-31'
            ))),
            array(1 => self::wholeYear())
        );

        $this->assertSame(0.0, $required);
        $this->assertFalse($hasRule);
    }

    /**
     * The membership has to overlap the *clipped* window, not merely the period: a member who left
     * before the rule started owes nothing under it.
     */
    public function testMembershipMustOverlapTheValidityWindowItself(): void
    {
        [$required, , $hasRule] = self::calculate(
            array(self::rule(1, 12.0, array('rrr_valid_from' => '2026-07-01'))),
            array(1 => self::memberships(array(array('2026-01-01', '2026-03-31'))))
        );

        $this->assertSame(0.0, $required);
        $this->assertFalse($hasRule);
    }

    // ---------------------------------------------------------------- several rows (§6.2)

    public function testSeveralFullRowsUseTheHighestValueAndWarn(): void
    {
        [$required, $warnings] = self::calculate(
            array(
                self::rule(1, 10.0, array('rrr_partial_period_mode' => 'full')),
                self::rule(1, 25.0, array('rrr_partial_period_mode' => 'full')),
                self::rule(1, 15.0, array('rrr_partial_period_mode' => 'full'))
            ),
            array(1 => self::wholeYear())
        );

        $this->assertSame(25.0, $required);
        $this->assertSame(array('PLG_REQUIREMENTS_WARNING_SEVERAL_FULL_RULES'), $warnings);
    }

    public function testOneFullRowRaisesNoWarning(): void
    {
        [, $warnings] = self::calculate(
            array(self::rule(1, 10.0, array('rrr_partial_period_mode' => 'full'))),
            array(1 => self::wholeYear())
        );

        $this->assertSame(array(), $warnings);
    }

    public function testMixingFullAndProRataTakesTheHigherAndWarns(): void
    {
        [$required, $warnings] = self::calculate(
            array(
                self::rule(1, 10.0, array('rrr_partial_period_mode' => 'full')),
                self::rule(1, 24.0)
            ),
            array(1 => self::wholeYear())
        );

        $this->assertSame(24.0, $required);
        $this->assertSame(array('PLG_REQUIREMENTS_WARNING_MIXED_RULES'), $warnings);
    }

    // ---------------------------------------------------------------- several roles (§8.1 step 8)

    /**
     * @return array<string,array{0:string,1:float}>
     */
    public static function multipleRoleModes(): array
    {
        return array(
            'maximum is the default' => array('maximum', 20.0),
            'minimum' => array('minimum', 5.0),
            'sum' => array('sum', 35.0),
            'an unknown mode falls back to maximum' => array('', 20.0)
        );
    }

    /**
     * @dataProvider multipleRoleModes
     */
    public function testTheMultipleRolesModeCombinesTheRoleAmounts(string $mode, float $expected): void
    {
        [$required] = self::calculate(
            array(
                self::rule(1, 5.0, array('rrr_partial_period_mode' => 'full')),
                self::rule(2, 20.0, array('rrr_partial_period_mode' => 'full')),
                self::rule(3, 10.0, array('rrr_partial_period_mode' => 'full'))
            ),
            array(1 => self::wholeYear(), 2 => self::wholeYear(), 3 => self::wholeYear()),
            $mode
        );

        $this->assertSame($expected, $required);
    }

    /**
     * Only the roles the member actually holds take part in the combination - `minimum` would
     * otherwise be decided by a rule that does not apply to them at all.
     */
    public function testOnlyHeldRolesTakePartInTheCombination(): void
    {
        [$required] = self::calculate(
            array(
                self::rule(1, 5.0, array('rrr_partial_period_mode' => 'full')),
                self::rule(2, 20.0, array('rrr_partial_period_mode' => 'full'))
            ),
            array(2 => self::wholeYear()),
            'minimum'
        );

        $this->assertSame(20.0, $required);
    }

    /**
     * Holding the role is not enough either. A role whose only rule was gated out must drop out of
     * the combination rather than contributing a zero, which would take `minimum` down with it.
     */
    public function testAGatedOutRoleDoesNotDragTheMinimumToZero(): void
    {
        [$required, , $hasRule] = self::calculate(
            array(
                self::rule(1, 5.0, array(
                    'rrr_partial_period_mode' => 'full',
                    'rrr_membership_at_period_start' => true
                )),
                self::rule(2, 20.0, array('rrr_partial_period_mode' => 'full'))
            ),
            array(
                // joined in April, so the gate on role 1 blocks it
                1 => self::memberships(array(array('2026-04-01', '2026-12-31'))),
                2 => self::wholeYear()
            ),
            'minimum'
        );

        $this->assertSame(20.0, $required);
        $this->assertTrue($hasRule);
    }

    // ---------------------------------------------------------------- helpers

    public function testDateInMembershipsIsInclusiveOnBothEnds(): void
    {
        $memberships = self::memberships(array(array('2026-03-01', '2026-03-31')));

        $this->assertTrue(FulfilmentService::dateInMemberships(new DateTimeImmutable('2026-03-01'), $memberships));
        $this->assertTrue(FulfilmentService::dateInMemberships(new DateTimeImmutable('2026-03-31'), $memberships));
        $this->assertFalse(FulfilmentService::dateInMemberships(new DateTimeImmutable('2026-02-28'), $memberships));
        $this->assertFalse(FulfilmentService::dateInMemberships(new DateTimeImmutable('2026-04-01'), $memberships));
    }

    public function testProrationFractionIsZeroWithoutAnyMembership(): void
    {
        $this->assertSame(0.0, FulfilmentService::prorationFraction(
            array(),
            new DateTimeImmutable(self::YEAR_START),
            new DateTimeImmutable(self::YEAR_END),
            'month',
            null,
            null
        ));
    }

    public function testProrationFractionIsOneForAWholeYearMember(): void
    {
        $this->assertSame(1.0, FulfilmentService::prorationFraction(
            self::wholeYear(),
            new DateTimeImmutable(self::YEAR_START),
            new DateTimeImmutable(self::YEAR_END),
            'month',
            null,
            null
        ));
    }

    public function testAnUnknownProrationBasisIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FulfilmentService::prorationUnitStarts(
            new DateTimeImmutable(self::YEAR_START),
            new DateTimeImmutable(self::YEAR_END),
            'fortnight'
        );
    }
}

<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Tests\Unit\Support\RequirementsUnitTestCase;

require_once __DIR__ . '/Support/RequirementsUnitTestCase.php';

/**
 * Threshold crossing for requirements that recur from their last fulfilment (§8.3, REQ-DEC-005).
 *
 * The rule the maintainer chose is that a cycle renews when the accumulated effective credits reach
 * the target - not when any credit arrives. These cases are the ones that separate the two.
 */
final class RenewalCycleTest extends RequirementsUnitTestCase
{
    /**
     * @param array<int,array{0:string,1:float}> $contributions date and effective credits
     * @return array<int,array<string,mixed>>
     */
    private static function credits(array $contributions): array
    {
        return array_map(
            static fn(array $c): array => array('periodReference' => $c[0], 'effectiveCredits' => $c[1]),
            $contributions
        );
    }

    public function testNothingRenewsWithoutContributions(): void
    {
        [$renewals, $running] = FulfilmentService::renewalCycles(array(), 15.0);

        $this->assertSame(array(), $renewals);
        $this->assertSame(0.0, $running);
    }

    /**
     * The case the concept names: one short course must not reset a long requirement.
     */
    public function testASingleSmallCreditDoesNotRenewAnHourCountedRequirement(): void
    {
        [$renewals, $running] = FulfilmentService::renewalCycles(
            self::credits(array(array('2024-02-01', 1.0))),
            15.0
        );

        $this->assertSame(array(), $renewals);
        $this->assertSame(1.0, $running, 'The hour still counts towards the next renewal.');
    }

    public function testTheCreditThatReachesTheTargetIsTheRenewalDate(): void
    {
        [$renewals, $running] = FulfilmentService::renewalCycles(
            self::credits(array(
                array('2024-02-01', 4.0),
                array('2024-09-12', 6.0),
                array('2025-01-20', 5.0)
            )),
            15.0
        );

        $this->assertSame(array('2025-01-20'), $renewals);
        $this->assertSame(0.0, $running);
    }

    public function testReachingTheTargetExactlyCounts(): void
    {
        [$renewals] = FulfilmentService::renewalCycles(
            self::credits(array(array('2025-03-01', 15.0))),
            15.0
        );

        $this->assertSame(array('2025-03-01'), $renewals);
    }

    /**
     * §8.3 step 5: excess does not carry into the new cycle.
     */
    public function testExcessCreditIsNotCarriedIntoTheNextCycle(): void
    {
        [$renewals, $running] = FulfilmentService::renewalCycles(
            self::credits(array(array('2025-03-01', 30.0))),
            15.0
        );

        $this->assertSame(array('2025-03-01'), $renewals, 'Thirty hours renew once, not twice.');
        $this->assertSame(0.0, $running, 'The extra fifteen are dropped, not banked.');
    }

    public function testEachCompletedCycleIsItsOwnRenewal(): void
    {
        [$renewals, $running] = FulfilmentService::renewalCycles(
            self::credits(array(
                array('2020-05-01', 15.0),
                array('2023-06-01', 8.0),
                array('2023-11-01', 7.0),
                array('2026-01-01', 3.0)
            )),
            15.0
        );

        $this->assertSame(array('2020-05-01', '2023-11-01'), $renewals);
        $this->assertSame(3.0, $running, 'The third cycle is under way.');
    }

    /**
     * A binary requirement has no threshold, so any positive contribution renews it - which is the
     * half of REQ-DEC-005 that was never in doubt.
     */
    public function testABinaryRequirementRenewsOnAnyPositiveCredit(): void
    {
        [$renewals, $running] = FulfilmentService::renewalCycles(
            self::credits(array(
                array('2022-04-01', 1.0),
                array('2025-04-01', 1.0)
            )),
            0.0
        );

        $this->assertSame(array('2022-04-01', '2025-04-01'), $renewals);
        $this->assertSame(0.0, $running);
    }

    public function testABinaryRequirementIsNotRenewedByAZeroCredit(): void
    {
        [$renewals] = FulfilmentService::renewalCycles(
            self::credits(array(array('2025-04-01', 0.0))),
            0.0
        );

        $this->assertSame(array(), $renewals, 'An accepted zero is a rejection, not a renewal.');
    }

    /**
     * An accepted zero on an hour-counted requirement contributes nothing, and must not push the
     * running total over the line on its own.
     */
    public function testARejectedContributionAddsNothing(): void
    {
        [$renewals, $running] = FulfilmentService::renewalCycles(
            self::credits(array(
                array('2025-01-01', 14.0),
                array('2025-02-01', 0.0)
            )),
            15.0
        );

        $this->assertSame(array(), $renewals);
        $this->assertSame(14.0, $running);
    }

    public function testFractionalCreditsAccumulate(): void
    {
        [$renewals, $running] = FulfilmentService::renewalCycles(
            self::credits(array(
                array('2025-01-01', 2.5),
                array('2025-02-01', 2.5),
                array('2025-03-01', 0.5)
            )),
            5.0
        );

        $this->assertSame(array('2025-02-01'), $renewals);
        $this->assertSame(0.5, $running);
    }
}

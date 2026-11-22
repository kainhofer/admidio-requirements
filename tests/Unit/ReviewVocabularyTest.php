<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

use Admidio\Infrastructure\Cli\CliTaskRegistry;
use AdmidioPlugin\Requirements\Presenter\ReviewPresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Tests\Unit\Support\RequirementsUnitTestCase;

require_once __DIR__ . '/Support/RequirementsUnitTestCase.php';

/**
 * The coverage statuses are the calculator's vocabulary, and three places have to agree on it: the
 * review view that turns a status into a sentence, the two language files that hold the sentences,
 * and the CLI option that filters by one. A status added to the calculator without a label reaches
 * the page as a raw key like `no-credits-required`, which nothing in the test suite would otherwise
 * notice - the page still renders, it just renders nonsense.
 */
final class ReviewVocabularyTest extends RequirementsUnitTestCase
{
    /**
     * Every status FulfilmentService::coverageStatus() and recurringOutcome() can return.
     */
    private const STATUSES = array(
        'fulfilled',
        'missing',
        'no-credits-required',
        'additional-credits',
        'none',
        'never-fulfilled',
        'overdue'
    );

    /**
     * @dataProvider everyStatus
     */
    public function testEveryStatusHasItsOwnLabelInBothLanguages(string $status): void
    {
        $key = ReviewPresenter::statusLabelKey($status);

        foreach (array('en', 'de') as $language) {
            $this->assertArrayHasKey(
                $key,
                self::languageKeys($language),
                $status . ' has no ' . $language . ' label'
            );
        }
    }

    /**
     * A status that fell through to the default of the match would silently read as "no obligation",
     * which is a wrong answer rather than a missing one.
     */
    public function testNoTwoStatusesShareALabel(): void
    {
        $keys = array_map(
            static fn(string $status): string => ReviewPresenter::statusLabelKey($status),
            self::STATUSES
        );

        $this->assertSame(count(self::STATUSES), count(array_unique($keys)));
    }

    /**
     * The filter offers what the requirement can actually produce. A calendar period has no due date
     * and cannot be overdue; a recurring cycle has no period and is never simply "missing".
     */
    public function testEachAnchorFiltersOnlyByStatusesItCanProduce(): void
    {
        foreach (array('calendar', 'last_fulfilment') as $anchor) {
            foreach (ReviewPresenter::statusFilters($anchor) as $status) {
                $this->assertContains($status, self::STATUSES, $anchor . ' offers an unknown status');
            }
        }

        $this->assertNotContains('overdue', ReviewPresenter::statusFilters('calendar'));
        $this->assertNotContains('missing', ReviewPresenter::statusFilters('last_fulfilment'));
    }

    /**
     * The CLI filters the same roster by the same names, so `--status` has to accept every one of
     * them - the web filter and `requirements:report --requirement` are one feature with two faces.
     */
    public function testTheCliStatusOptionAcceptsTheWholeVocabulary(): void
    {
        if (CliTaskRegistry::get('requirements:report') === null) {
            CliTaskRegistry::setPluginContext(RequirementsPlugin::ID);
            try {
                require dirname(__DIR__, 2) . '/cli.php';
            } finally {
                CliTaskRegistry::setPluginContext(null);
            }
        }

        $task = CliTaskRegistry::get('requirements:report');
        $values = array();
        foreach ($task['options'] ?? array() as $option) {
            if (($option['name'] ?? '') === 'status') {
                $values = $option['values'] ?? array();
            }
        }

        sort($values);
        $expected = self::STATUSES;
        sort($expected);
        $this->assertSame($expected, $values);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function everyStatus(): array
    {
        $cases = array();
        foreach (self::STATUSES as $status) {
            $cases[$status] = array($status);
        }
        return $cases;
    }

    /**
     * The keys of one shipped language file, as a set.
     *
     * @return array<string,string>
     */
    private static function languageKeys(string $language): array
    {
        static $cache = array();
        if (array_key_exists($language, $cache)) {
            return $cache[$language];
        }

        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/languages/' . $language . '.xml');
        self::assertNotFalse($xml, $language . '.xml is not well-formed XML');

        $keys = array();
        foreach ($xml->string as $string) {
            $keys[(string) $string['name']] = (string) $string;
        }

        return $cache[$language] = $keys;
    }

    /**
     * The review process names its modes, the states of a review, the filters of the review page and
     * the decisions on a credit by building the key from the value, so a value without its label
     * would reach the page as a raw key.
     */
    public function testEveryReviewValueHasItsLabelInBothLanguages(): void
    {
        $keys = array();
        foreach (\AdmidioPlugin\Requirements\Service\ReviewService::MODES as $mode) {
            $keys[] = 'PLG_REQUIREMENTS_REVIEW_MODE_' . strtoupper($mode);
        }
        foreach (\AdmidioPlugin\Requirements\Entity\Review::STATUSES as $status) {
            $keys[] = 'PLG_REQUIREMENTS_REVIEW_STATE_' . strtoupper($status);
        }
        foreach (\AdmidioPlugin\Requirements\Service\ReviewListService::REVIEW_FILTERS as $filter) {
            $keys[] = 'PLG_REQUIREMENTS_REVIEW_FILTER_' . strtoupper($filter);
        }
        foreach (\AdmidioPlugin\Requirements\Service\ReviewService::CREDIT_STATUSES as $status) {
            $keys[] = \AdmidioPlugin\Requirements\Service\ReviewNotifier::creditStatusLabelKey($status);
        }
        // The reminders are put together from a greeting, the reminder of their kind and a thank-you.
        foreach (array('GREETING', 'THANKS', 'OPEN_LIST', 'REMINDER_DUE', 'REMINDER_REQUESTED', 'REMINDER_CLARIFICATION',
            'SUBMISSION_REMINDER', 'REQUESTED', 'CANCELLED') as $mail) {
            $keys[] = 'PLG_REQUIREMENTS_REVIEW_MAIL_' . $mail;
        }
        foreach (array('SUBMIT_ACTIVITY', 'SUBMIT_PERIOD', 'SHARE', 'REREVIEW', 'RESUBMIT') as $action) {
            $keys[] = 'PLG_REQUIREMENTS_REVIEW_' . $action;
            $keys[] = 'PLG_REQUIREMENTS_REVIEW_' . $action . '_HELP';
        }

        foreach (array('en', 'de') as $language) {
            $missing = array_diff($keys, array_keys(self::languageKeys($language)));
            $this->assertSame(array(), array_values($missing), 'missing in ' . $language);
        }
    }
}

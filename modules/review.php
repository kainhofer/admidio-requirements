<?php
/**
 ***********************************************************************************************
 * Requirements plugin - the review page: one row per member, requirement and credited period, with
 * the reviewer's decision on each credit, and the requests for reviews.
 *
 * Parameters:
 * mode        : list (default) | save-row | finish | remind | cancel | request | request-save
 * requirement : UUID of the requirement type
 * period      : raw value of the credited-period control (year, YYYY-MM, YYYY-Www or a date)
 * periodq     : quarter "1".."4", for a requirement with a quarterly period
 * status      : review status (needs_review, requested, not_submitted, overdue, finished,
 *               all_reviews) or "coverage:" and a fulfilment status; empty for every member
 * member      : part of a member's name the rows are narrowed to
 * user        : UUID of a member (request form, and the row a save or reminder is for)
 *
 * @copyright Reinhold Kainhofer
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Entity\Review;
use AdmidioPlugin\Requirements\Presenter\ReviewPresenter;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\FulfilmentService;
use AdmidioPlugin\Requirements\Service\ReviewService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    if (!RequirementsPlugin::isUsableByCurrentUser() || !ReviewPresenter::mayOpen()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'save-row', 'finish', 'remind', 'cancel', 'request', 'request-save')
    ));
    $getRequirement = admFuncVariableIsValid($_GET, 'requirement', 'uuid');
    // The period control submits a year, a month, an ISO week or a date depending on the
    // requirement, so it arrives as a string and the presenter resolves it once it knows which.
    $getPeriod = admFuncVariableIsValid($_GET, 'period', 'string');
    $getQuarter = admFuncVariableIsValid($_GET, 'periodq', 'string');
    // An empty status is a choice of its own (every member), so only a missing one takes the default.
    $getStatus = array_key_exists('status', $_GET)
        ? admFuncVariableIsValid($_GET, 'status', 'string')
        : ReviewPresenter::defaultStatus();
    $getMember = admFuncVariableIsValid($_GET, 'member', 'string');
    $getUser = admFuncVariableIsValid($_GET, 'user', 'uuid');

    $reviews = new ReviewService($gDb);

    /** The member, requirement and period a row of the page stands for, from the posted row. */
    $rowOf = static function () use ($gDb, $gProfileFields): array {
        $user = new User($gDb, $gProfileFields);
        $user->readDataByUuid(admFuncVariableIsValid($_POST, 'user', 'uuid', array('requireValue' => true)));
        $type = new RequirementType($gDb);
        $type->readDataByUuid(admFuncVariableIsValid($_POST, 'requirement', 'uuid', array('requireValue' => true)));
        $period = admFuncVariableIsValid($_POST, 'period', 'date');
        if ((int) $user->getValue('usr_id') === 0 || (int) $type->getValue('rqt_id') === 0) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        return array((int) $user->getValue('usr_id'), $type, $period);
    };

    switch ($getMode) {
        case 'list':
            $page = new ReviewPresenter();
            $page->createList($getRequirement, $getPeriod, $getQuarter, $getStatus, $getMember);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline(), 'bi-clipboard-data');
            $page->show();
            break;

        case 'save-row':
            // The decisions of one row: amount, status and comment per credit. Every credit is
            // checked again by the service, so a row cannot decide on what its reviewer may not.
            SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
            [$userId, $type, $period] = $rowOf();

            $decisions = array();
            foreach ((array) ($_POST['accepted'] ?? array()) as $creditId => $value) {
                $raw = trim((string) $value);
                if ($raw !== '' && !is_numeric($raw)) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                $decisions[(int) $creditId] = array(
                    'accepted' => $raw === '' ? null : (float) $raw,
                    'status' => (string) ($_POST['status'][$creditId] ?? ''),
                    'comment' => (string) ($_POST['comment'][$creditId] ?? '')
                );
            }
            $reviews->saveDecisions($decisions);

            echo json_encode(array(
                'status' => 'success',
                'html' => (new ReviewPresenter())->renderRow($userId, (int) $type->getValue('rqt_id'), $period)
            ));
            break;

        case 'finish':
            SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
            $review = new Review($gDb);
            $review->readDataByUuid(admFuncVariableIsValid($_POST, 'review', 'uuid', array('requireValue' => true)));
            if ((int) $review->getValue('rrv_id') === 0) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }
            $reviews->finish($review, (string) ($_POST['comment'] ?? ''));

            [$userId, $type, $period] = $rowOf();
            echo json_encode(array(
                'status' => 'success',
                'html' => (new ReviewPresenter())->renderRow($userId, (int) $type->getValue('rqt_id'), $period)
            ));
            break;

        case 'cancel':
            // A configured reviewer or an administrator calls a review off; the row is drawn again,
            // and disappears from a filter it no longer matches the next time the page is loaded.
            SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
            $review = new Review($gDb);
            $review->readDataByUuid(admFuncVariableIsValid($_POST, 'review', 'uuid', array('requireValue' => true)));
            if ((int) $review->getValue('rrv_id') === 0) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }
            $reviews->cancel($review);

            [$userId, $type, $period] = $rowOf();
            echo json_encode(array(
                'status' => 'success',
                'html' => (new ReviewPresenter())->renderRow($userId, (int) $type->getValue('rqt_id'), $period)
            ));
            break;

        case 'remind':
            SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
            $reviewUuid = admFuncVariableIsValid($_POST, 'review', 'uuid');
            if ($reviewUuid !== '') {
                $review = new Review($gDb);
                $review->readDataByUuid($reviewUuid);
                if ((int) $review->getValue('rrv_id') === 0) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                $reviews->remind($review);
            } else {
                [$userId, $type, $period] = $rowOf();
                $reviews->remindSubmission($type, $period, $userId);
            }
            echo json_encode(array('status' => 'success'));
            break;

        case 'request':
            $page = new ReviewPresenter();
            $page->createRequestForm($getRequirement, admFuncVariableIsValid($_GET, 'period', 'date'), $getUser);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'request-save':
            $formValues = $gCurrentSession->getFormObject($_POST['adm_csrf_token'])->validate($_POST);

            $type = new RequirementType($gDb);
            $type->readDataByUuid((string) $formValues['requirement']);
            if ((int) $type->getValue('rqt_id') === 0) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }

            // The periods: the one the date lies in, and as many before it as asked for.
            $periods = array();
            $reference = ReviewService::date((string) $formValues['period']);
            for ($i = 0, $count = max(1, min(10, (int) ($formValues['periods_back'] ?? 1))); $i < $count; ++$i) {
                [$start] = FulfilmentService::periodFor($type, $reference);
                $periods[] = $start->format('Y-m-d');
                $reference = $start->modify('-1 day');
            }

            $members = $formValues['members'] ?? array();
            if (is_string($members)) {
                $members = str_starts_with($members, '[') ? (array) json_decode($members, true) : explode(',', $members);
            }
            $members = array_values(array_filter(array_map('intval', (array) $members)));

            // A random sample: of the members picked, or of everybody the requirement applied to.
            $sample = (int) ($formValues['sample'] ?? 0);
            if ($sample > 0) {
                if ($members === array()) {
                    $fulfilment = new FulfilmentService($gDb);
                    foreach ($periods as $periodStart) {
                        foreach ($fulfilment->coverageForMembers((int) $type->getValue('rqt_id'), $periodStart)['members'] as $member) {
                            if ($member['required'] !== null || $member['status'] === 'no-credits-required') {
                                $members[] = (int) $member['userId'];
                            }
                        }
                    }
                    $members = array_values(array_unique($members));
                }
                shuffle($members);
                $members = array_slice($members, 0, $sample);
            }
            if ($members === array()) {
                throw new Exception('PLG_REQUIREMENTS_REVIEW_REQUEST_NO_MEMBERS');
            }

            [$reviewerUser, $reviewerRole] = ReviewPresenter::parseReviewerChoice((string) ($formValues['reviewer'] ?? ''));
            if ($reviewerUser > 0 || $reviewerRole > 0) {
                // The request picks a reviewer as the settings allow; the requester may name themselves.
                if (ReviewService::reviewerChoice() === ReviewService::CHOICE_CONFIGURED) {
                    throw new Exception('SYS_NO_RIGHTS');
                }
            }
            $created = $reviews->requestReviews(
                $type,
                $periods,
                $members,
                (string) ($formValues['due_date'] ?? ''),
                (string) ($formValues['message'] ?? ''),
                $reviewerUser,
                $reviewerRole
            );

            $gNavigation->deleteLastUrl();
            echo json_encode(array(
                'status' => 'success',
                'message' => $gL10n->get('PLG_REQUIREMENTS_REVIEW_REQUESTS_SENT', array(count($created))),
                'url' => SecurityUtils::encodeUrl(
                    PluginRegistry::requireEnabled(RequirementsPlugin::ID)->getUrl('review.php'),
                    array('mode' => 'list', 'status' => 'requested', 'requirement' => (string) $type->getValue('rqt_uuid'))
                )
            ));
            break;
    }
} catch (Throwable $e) {
    handleException($e, in_array($getMode ?? '', array('save-row', 'finish', 'remind', 'cancel', 'request-save'), true));
}

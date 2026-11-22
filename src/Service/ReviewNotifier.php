<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Roles\Entity\Role;
use Admidio\Users\Entity\User;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\RequirementType;
use AdmidioPlugin\Requirements\Entity\Review;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use Throwable;

/**
 * The mails of the review process. The recipients are always the member and the reviewer of the
 * review: the member or role a member or reviewer named, or else the configured reviewers.
 *
 * A mail is sent once the transaction it reports on is committed, so that a rolled-back action
 * announces nothing, and a mail that cannot be sent is logged rather than undoing the action.
 */
final class ReviewNotifier
{
    /** Where a mail text carries its link; replaced by the link once the text is encoded. */
    private const LINK = '{{link}}';

    public function __construct(private readonly Database $db, private readonly ReviewService $reviews)
    {
    }

    public function submitted(Review $review): void
    {
        $this->toReviewers($review, 'PLG_REQUIREMENTS_REVIEW_MAIL_SUBMITTED',
            array($this->memberName($review), $this->scopeLabel($review)), (string) $review->getValue('rrv_message'));
    }

    public function shared(Review $review): void
    {
        $this->toReviewers($review, 'PLG_REQUIREMENTS_REVIEW_MAIL_SHARED',
            array($this->memberName($review), $this->scopeLabel($review)), (string) $review->getValue('rrv_message'));
    }

    public function withdrawn(Review $review): void
    {
        $this->toReviewers($review, 'PLG_REQUIREMENTS_REVIEW_MAIL_WITHDRAWN',
            array($this->memberName($review), $this->scopeLabel($review)));
    }

    public function reReviewRequested(Review $review, Activity $activity, string $reason): void
    {
        $this->toReviewers($review, 'PLG_REQUIREMENTS_REVIEW_MAIL_REREVIEW',
            array($this->memberName($review), (string) $activity->getValue('rqa_title')), $reason);
    }

    public function resubmitted(Review $review): void
    {
        $this->toReviewers($review, 'PLG_REQUIREMENTS_REVIEW_MAIL_RESUBMITTED',
            array($this->memberName($review), $this->scopeLabel($review)), (string) $review->getValue('rrv_message'));
    }

    /**
     * The member is asked to share their activities: a greeting, what is asked and how to do it - with
     * the activity list as a link in the sentence - the deadline, the message of the reviewer as a
     * paragraph of its own, and a thank-you.
     */
    public function requested(Review $review): void
    {
        global $gL10n;

        $message = trim((string) $review->getValue('rrv_message'));
        $this->personal(
            (int) $review->getValue('rrv_user_id'),
            $this->scopeLabel($review),
            $gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_REQUESTED', array($this->scopeLabel($review), self::LINK)),
            (string) $review->getValue('rrv_due_date', 'database'),
            $this->activityListUrl($review),
            $message
        );
    }

    /**
     * The member is told that a review of theirs was called off.
     */
    public function cancelled(Review $review): void
    {
        global $gL10n;

        $this->personal((int) $review->getValue('rrv_user_id'), $this->scopeLabel($review),
            $gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_CANCELLED', array($this->scopeLabel($review), self::LINK)),
            '', $this->activityListUrl($review));
    }

    /**
     * A friendly reminder of a review that waits for the member: activities a reviewer asked for and
     * that are not shared yet, or a question of the reviewer that is not answered yet.
     */
    public function reminded(Review $review): void
    {
        global $gL10n;

        $textId = (string) $review->getValue('rrv_status') === Review::STATUS_CLARIFICATION
            ? 'PLG_REQUIREMENTS_REVIEW_MAIL_REMINDER_CLARIFICATION'
            : 'PLG_REQUIREMENTS_REVIEW_MAIL_REMINDER_REQUESTED';
        $this->personal((int) $review->getValue('rrv_user_id'), $this->scopeLabel($review),
            $gL10n->get($textId, array($this->scopeLabel($review), self::LINK)),
            (string) $review->getValue('rrv_due_date', 'database'), $this->activityListUrl($review));
    }

    /**
     * A friendly reminder to submit a finished period of a requirement in mode `period`.
     */
    public function submissionReminder(RequirementType $type, string $periodStart, string $periodLabel, ?string $dueDate, int $userId): void
    {
        global $gL10n;

        $scope = (string) $type->getValue('rqt_name') . ' – ' . $periodLabel;
        $this->personal($userId, $scope,
            $gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_SUBMISSION_REMINDER', array($scope, self::LINK)), (string) $dueDate,
            $this->pageUrl('index.php', array('mode' => 'list', 'requirement' => (string) $type->getValue('rqt_uuid'),
                'period' => $periodStart)));
    }

    /**
     * A mail to the member that reads like a note from a person: a greeting by first name, what is
     * waiting and what to do about it - with the activity list as a link in the sentence - the
     * deadline if there is one, the message of the reviewer if there is one, and a thank-you.
     */
    private function personal(int $userId, string $scope, string $text, string $dueDate, string $url, string $message = ''): void
    {
        global $gL10n, $gProfileFields;

        $member = new User($this->db, $gProfileFields, $userId);
        $due = $dueDate === '' ? '' : ' ' . $gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_REMINDER_DUE', array($this->formatDate($dueDate)));
        $body = $gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_GREETING', array((string) $member->getValue('FIRST_NAME')))
            . "\n\n" . $text . $due
            . ($message === '' ? '' : "\n\n" . $message)
            . "\n\n" . $gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_THANKS');

        $this->toMember($userId, $scope, $body, '', $url, $gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_OPEN_LIST'));
    }

    public function finished(Review $review): void
    {
        global $gL10n;

        $lines = array($gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_FINISHED', array($this->scopeLabel($review))), '');
        foreach ($this->reviews->creditsOfReview($review) as $credit) {
            $lines[] = $this->creditLine(
                (string) $credit['rqa_title'],
                (string) ($credit['rqt_short_name'] ?: $credit['rqt_name']),
                (float) $credit['rac_credits_claimed'],
                $credit['rac_credits_accepted'] === null ? null : (float) $credit['rac_credits_accepted'],
                (string) $credit['rac_review_status'],
                (string) $credit['rac_review_comment']
            );
        }
        $this->toMember((int) $review->getValue('rrv_user_id'), $this->scopeLabel($review), implode("\n", $lines),
            (string) $review->getValue('rrv_result_comment'));
    }

    /**
     * The decisions of one save, one mail per member.
     *
     * @param array<int,array<string,mixed>> $changed as ReviewService::saveDecisions() returns them
     */
    public function decided(array $changed): void
    {
        global $gL10n;

        $byMember = array();
        foreach ($changed as $decision) {
            $activity = new Activity($this->db, (int) $decision['activityId']);
            $type = new RequirementType($this->db, (int) $decision['requirementTypeId']);
            $byMember[(int) $activity->getValue('rqa_user_id')][] = $this->creditLine(
                (string) $activity->getValue('rqa_title'),
                (string) ($type->getValue('rqt_short_name') ?: $type->getValue('rqt_name')),
                (float) $decision['claimed'],
                $decision['accepted'],
                (string) $decision['status'],
                (string) $decision['comment']
            );
        }
        foreach ($byMember as $userId => $lines) {
            $this->toMember($userId, $gL10n->get('PLG_REQUIREMENTS_REVIEW'),
                $gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_DECIDED') . "\n\n" . implode("\n", $lines));
        }
    }

    /**
     * @param array<string,mixed> $previous the review before it was handed on
     */
    public function reassigned(Review $review, array $previous): void
    {
        $old = new Review($this->db);
        $old->setArray(array_merge(ReviewService::rowOf($review), array(
            'rrv_reviewer_user_id' => $previous['rrv_reviewer_user_id'],
            'rrv_reviewer_role_id' => $previous['rrv_reviewer_role_id']
        )));
        foreach (array($old, $review) as $recipientsOf) {
            $this->toReviewers($recipientsOf, 'PLG_REQUIREMENTS_REVIEW_MAIL_REASSIGNED',
                array($this->scopeLabel($review), $this->memberName($review)));
        }
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * @param array<int,string> $parameters
     */
    private function toReviewers(Review $review, string $textId, array $parameters, string $message = ''): void
    {
        global $gL10n;

        $userIds = array();
        $roleIds = array();
        if ((int) $review->getValue('rrv_reviewer_user_id') > 0) {
            $userIds[] = (int) $review->getValue('rrv_reviewer_user_id');
        } elseif ((int) $review->getValue('rrv_reviewer_role_id') > 0) {
            $roleIds[] = (int) $review->getValue('rrv_reviewer_role_id');
        } else {
            $userIds = ReviewService::reviewerUserIds();
            $roleIds = ReviewService::reviewerRoleIds();
        }

        $this->send(
            $this->scopeLabel($review),
            $gL10n->get($textId, $parameters),
            $message,
            $this->pageUrl('review.php', array('status' => 'needs_review')),
            $userIds,
            $roleIds
        );
    }

    /**
     * A mail to the member. $url is where the mail leads - the activity list by default. With
     * $linkText the text carries the link itself, at the place of self::LINK, instead of the URL
     * standing at the end.
     */
    private function toMember(int $userId, string $subject, string $text, string $message = '', string $url = '', string $linkText = ''): void
    {
        $this->send($subject, $text, $message, $url !== '' ? $url : $this->pageUrl('index.php'), array($userId), array(), $linkText);
    }

    /**
     * The activity list of the member, narrowed to the requirement and the period of a review - or to
     * the activity of a review of a single activity.
     */
    private function activityListUrl(Review $review): string
    {
        $activityId = (int) $review->getValue('rrv_activity_id');
        if ($activityId > 0) {
            return $this->pageUrl('index.php', array('mode' => 'edit',
                'uuid' => (string) (new Activity($this->db, $activityId))->getValue('rqa_uuid')));
        }
        $type = new RequirementType($this->db, (int) $review->getValue('rrv_requirement_type_id'));
        return $this->pageUrl('index.php', array_filter(array(
            'mode' => 'list',
            'requirement' => (string) $type->getValue('rqt_uuid'),
            'period' => (string) $review->getValue('rrv_period_reference', 'database')
        )));
    }

    /**
     * @param array<int,int> $userIds
     * @param array<int,int> $roleIds
     */
    private function send(string $subject, string $text, string $message, string $url, array $userIds, array $roleIds, string $linkText = ''): void
    {
        if (!ReviewService::notificationsEnabled() || ($userIds === array() && $roleIds === array())) {
            return;
        }

        $send = function () use ($subject, $text, $message, $url, $userIds, $roleIds, $linkText): void {
            global $gCurrentOrganization, $gL10n, $gSettingsManager, $gProfileFields, $gLogger;

            try {
                $body = $text;
                if (trim($message) !== '') {
                    // The message of whoever wrote it is the personal part of the mail, a paragraph of
                    // its own after the text that says what happened.
                    $body .= "\n\n" . trim($message);
                }
                $inline = $linkText !== '' && $url !== '' && str_contains($body, self::LINK);
                if ($url !== '' && !$inline) {
                    $body .= "\n\n" . $url;
                }

                $email = new ReviewEmail();
                $html = $gSettingsManager->getBool('mail_html_registered_users');
                if ($html) {
                    $email->setHtmlMail();
                    $body = nl2br(SecurityUtils::encodeHTML($body));
                }
                // The link goes in after the text is encoded, so that its markup survives - and a
                // plain-text mail names the address right after the words.
                $body = str_replace(self::LINK, !$inline ? '' : ($html
                    ? '<a href="' . SecurityUtils::encodeHTML($url) . '">' . SecurityUtils::encodeHTML($linkText) . '</a>'
                    : $linkText . ' (' . $url . ')'), $body);
                $email->setSender();
                $email->setSubject($gCurrentOrganization->getValue('org_shortname') . ': '
                    . $gL10n->get('PLG_REQUIREMENTS_REVIEW') . ' – ' . $subject);
                $email->setText($body);

                $recipients = 0;
                foreach (array_unique($userIds) as $userId) {
                    $user = new User($this->db, $gProfileFields, (int) $userId);
                    if ((int) $user->getValue('usr_id') > 0) {
                        $recipients += $email->addRecipientsByUser((string) $user->getValue('usr_uuid'));
                    }
                }
                foreach (array_unique($roleIds) as $roleId) {
                    $role = new Role($this->db, (int) $roleId);
                    if ((int) $role->getValue('rol_id') > 0) {
                        $recipients += $email->addRecipientsByRole((string) $role->getValue('rol_uuid'));
                    }
                }
                if ($recipients > 0) {
                    $email->sendEmail();
                }
            } catch (Throwable $exception) {
                if (isset($gLogger)) {
                    $gLogger->warning('REQUIREMENTS: a review mail could not be sent.', array('error' => $exception->getMessage()));
                }
            }
        };

        $this->db->registerAfterCommit($send);
    }

    private function creditLine(string $activity, string $requirement, float $claimed, ?float $accepted, string $status, string $comment): string
    {
        global $gL10n;

        $amount = self::format($claimed);
        if ($accepted !== null && abs($accepted - $claimed) > 0.005) {
            $amount = self::format($claimed) . ' → ' . self::format($accepted);
        }
        $line = '- ' . $activity . ' (' . $requirement . '): ' . $amount;
        if ($status !== '') {
            $line .= ' – ' . $gL10n->get(self::creditStatusLabelKey($status));
        }
        if (trim($comment) !== '') {
            $line .= "\n  " . $gL10n->get('PLG_REQUIREMENTS_REVIEW_MAIL_COMMENT', array(trim($comment)));
        }
        return $line;
    }

    public static function creditStatusLabelKey(string $status): string
    {
        return match ($status) {
            ReviewService::CREDIT_ACCEPTED => 'PLG_REQUIREMENTS_REVIEW_ACCEPTED',
            ReviewService::CREDIT_PARTIALLY_ACCEPTED => 'PLG_REQUIREMENTS_REVIEW_PARTIALLY_ACCEPTED',
            ReviewService::CREDIT_NOT_ACCEPTED => 'PLG_REQUIREMENTS_REVIEW_NOT_ACCEPTED',
            ReviewService::CREDIT_CLARIFICATION => 'PLG_REQUIREMENTS_REVIEW_CLARIFICATION',
            default => 'PLG_REQUIREMENTS_REVIEW_OPEN'
        };
    }

    /**
     * What a review is about, as a reader of the mail calls it: the activity, or the requirement and
     * the label of the period.
     */
    public function scopeLabel(Review $review): string
    {
        $activityId = (int) $review->getValue('rrv_activity_id');
        if ($activityId > 0) {
            return (string) (new Activity($this->db, $activityId))->getValue('rqa_title');
        }
        $type = new RequirementType($this->db, (int) $review->getValue('rrv_requirement_type_id'));
        $reference = (string) $review->getValue('rrv_period_reference', 'database');
        if ((int) $type->getValue('rqt_id') === 0 || $reference === '') {
            return (string) $type->getValue('rqt_name');
        }
        [, , $label] = FulfilmentService::periodFor($type, ReviewService::date($reference));
        return (string) $type->getValue('rqt_name') . ' – ' . $label;
    }

    private function memberName(Review $review): string
    {
        global $gProfileFields;

        return (new User($this->db, $gProfileFields, (int) $review->getValue('rrv_user_id')))->readableName();
    }

    private function formatDate(string $ymd): string
    {
        global $gSettingsManager;

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        return $date === false ? $ymd : $date->format($gSettingsManager->getString('system_date'));
    }

    private static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param array<string,string> $parameters
     */
    private function pageUrl(string $page, array $parameters = array()): string
    {
        $plugin = PluginRegistry::get(RequirementsPlugin::ID);
        return $plugin === null ? '' : SecurityUtils::encodeUrl($plugin->getUrl($page), $parameters);
    }
}

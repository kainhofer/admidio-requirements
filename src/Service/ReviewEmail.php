<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Infrastructure\Email;

/**
 * The mail of the review process. It differs from Admidio's in one thing: the plain-text part of an
 * HTML mail keeps the address of a link. PHPMailer derives that part by stripping the tags, which
 * turns "<a href=…>Open your activity list</a>" into words that lead nowhere; here it becomes
 * "Open your activity list (https://…)".
 */
final class ReviewEmail extends Email
{
    public function html2text($html, $advanced = false)
    {
        $html = (string) preg_replace_callback(
            '/<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/si',
            static fn(array $match): string => $match[3] . ' (' . $match[2] . ')',
            (string) $html
        );
        return parent::html2text($html, $advanced);
    }
}

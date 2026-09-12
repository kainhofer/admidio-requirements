<?php
namespace AdmidioPlugin\Requirements\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Requirements\Database\RequirementsTables;

/**
 * One certificate or proof-of-attendance file of an activity.
 *
 * A file has no visibility of its own: whoever may see the activity may see its evidence, and
 * whoever may edit the activity may add to or remove from it. That is `Activity::isVisible()` and
 * `Activity::isEditable()`, and duplicating either here would be a second answer to the same
 * question - the one that eventually disagrees.
 *
 * The record is immutable. A file is added or removed, never edited, which is why the table has no
 * change columns and this class no setters beyond what `ActivityFileService` writes once.
 */
final class ActivityFile extends Entity
{
    use OrganizationScope;

    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, RequirementsTables::activityFiles(), 'raf', $id);
    }

    /**
     * A file has no organization of its own; it belongs to the one of its activity.
     */
    protected static function organizationScopeCondition(): string
    {
        return 'raf_activity_id IN (SELECT rqa_id FROM '
            . RequirementsTables::activities() . ' WHERE rqa_org_id = ?)';
    }

    public function getHookId(): ?string
    {
        return 'requirements_activity_file';
    }

    /**
     * The extension the file is stored under, derived from the MIME type that was sniffed on
     * upload - never from the name the browser sent.
     */
    public function getExtension(): string
    {
        return self::extensionForMimeType((string) $this->getValue('raf_mime_type'));
    }

    /**
     * The stored file name: the UUID plus the extension of its sniffed type.
     *
     * The user-supplied name never reaches the disk. It cannot traverse a path, cannot collide with
     * another member's upload, and cannot smuggle a second extension past the web server.
     */
    public function getStoredName(): string
    {
        $extension = $this->getExtension();

        return (string) $this->getValue('raf_uuid') . ($extension === '' ? '' : '.' . $extension);
    }

    /**
     * Every type this plugin knows how to store, as sniffed MIME type => stored extension.
     *
     * This is the ceiling, not the setting. An organization narrows it through
     * `requirements_files_allowed_types`, but cannot widen it past what is here: a type that is not
     * on this list has no extension to be stored under and no evidence that it is safe to hand back
     * to a browser as an attachment.
     *
     * @return array<string,string>
     */
    public static function mimeTypes(): array
    {
        return array(
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png'
        );
    }

    /**
     * Spellings an administrator may reasonably write for a type whose stored extension differs.
     *
     * "jpeg" and "jpg" are one type, and a setting that silently ignored one of them would switch
     * off a format the administrator believed they had allowed.
     *
     * @var array<string,string>
     */
    public const EXTENSION_ALIASES = array('jpeg' => 'jpg');

    /**
     * The stored extension a configured token refers to, or '' when it names nothing known.
     */
    public static function canonicalExtension(string $token): string
    {
        $token = strtolower(ltrim(trim($token), '.'));
        $token = self::EXTENSION_ALIASES[$token] ?? $token;

        return in_array($token, self::mimeTypes(), true) ? $token : '';
    }

    public static function extensionForMimeType(string $mimeType): string
    {
        return self::mimeTypes()[strtolower(trim($mimeType))] ?? '';
    }
}

<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Model\Entity;

use Cake\ORM\Entity;

/**
 * One public page (or a gone one), identified by (subject, subject_id).
 *
 * @property int $id
 * @property string $subject
 * @property string $subject_id
 * @property string $path
 * @property string $title
 * @property string|null $description
 * @property string|null $robots
 * @property string|null $og_type
 * @property string|null $og_image
 * @property \Cake\I18n\DateTime|null $lastmod
 * @property mixed $schema
 * @property string $status
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class SeoPage extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];
}

<?php
declare(strict_types=1);

namespace TheMusicDev\Seo\Model\Entity;

use Cake\ORM\Entity;

/**
 * An old path that redirects to a page's current path.
 *
 * @property int $id
 * @property string $from_path
 * @property int $seo_page_id
 * @property string $source
 * @property \Cake\I18n\DateTime $created
 * @property \TheMusicDev\Seo\Model\Entity\SeoPage|null $seo_page
 */
class SeoRedirect extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];
}

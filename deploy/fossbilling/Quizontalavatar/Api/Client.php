<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalavatar\Api;

/** FOSSBilling 0.7-compatible client API. */
class Client extends \Api_Abstract
{
    /** Return the avatar URL for the logged-in client, or an empty string. */
    public function avatar_url($data): string
    {
        $clientId = (int) $this->getIdentity()->id;
        if ($this->getService()->avatarPath($clientId) === null) {
            return '';
        }
        return 'quizontalavatar/avatar/'.$clientId;
    }
}

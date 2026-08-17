<?php
declare(strict_types=1);
namespace Box\Mod\Quizontalhostingtrial\Api;
class Client extends \Api_Abstract
{
    public function status($data): array { return $this->getService()->clientStatus((int) $this->getIdentity()->id); }
}

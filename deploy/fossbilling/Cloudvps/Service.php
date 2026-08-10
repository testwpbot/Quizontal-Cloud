<?php

declare(strict_types=1);

namespace Box\Mod\Cloudvps;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }
    public function install(): bool { return true; }
    public function uninstall(): bool { return true; }
}

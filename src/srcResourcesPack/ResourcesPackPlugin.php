<?php

declare(strict_types=1);

namespace srcResourcesPack;

use pocketmine\plugin\PluginBase;
use srcResourcesPack\listener\ResourcePackListener;
use srcResourcesPack\service\ResourcePackService;

final class ResourcesPackPlugin extends PluginBase
{
	private ?ResourcePackService $service = null;

	public function onEnable(): void
	{
		$this->saveDefaultConfig();
		$this->service = new ResourcePackService($this);
		$this->getServer()->getPluginManager()->registerEvents(new ResourcePackListener($this->service), $this);
	}

	public function onDisable(): void
	{
		$this->service = null;
	}
}
